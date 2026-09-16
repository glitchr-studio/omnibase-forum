<?php

namespace Base\Forum\Controller\Client;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Post;
use Base\Forum\Entity\Topic;
use Base\Forum\Form\Model\PostModel;
use Base\Forum\Form\Model\TopicModel;
use Base\Forum\Form\Type\PostType;
use Base\Forum\Form\Type\TopicType;
use Base\Forum\Repository\CategoryRepository;
use Base\Forum\Repository\PostRepository;
use Base\Forum\Repository\TopicRepository;
use Base\Forum\Security\ForumVoter;
use Base\Forum\Service\MarkdownRenderer;
use Base\Service\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A topic: reading it, opening one, replying, editing, and the moderation
 * switches (pin, lock, delete).
 */
class TopicController extends AbstractController
{
    private readonly TopicRepository $topics;
    private readonly PostRepository $posts;
    private readonly CategoryRepository $categories;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
        private readonly MarkdownRenderer $markdown,
        private readonly TranslatorInterface $translator,
        #[Autowire('%forum.posts_per_page%')] private readonly int $postsPerPage = 10,
        #[Autowire('%forum.flood_interval%')] private readonly int $floodInterval = 15,
    ) {
        $this->topics = $entityManager->getRepository(Topic::class);
        $this->posts = $entityManager->getRepository(Post::class);
        $this->categories = $entityManager->getRepository(Category::class);
    }

    /**
     * Declared before Show(): "/bbs/{slug}" would otherwise swallow
     * "nouveau" as a topic slug.
     */
    #[Route('/bbs/nouveau/{category}', name: 'forum_topic_new', defaults: ['category' => null], priority: 5)]
    #[IsGranted('ROLE_USER')]
    public function Create(Request $request, ?string $category = null): Response
    {
        $model = new TopicModel();
        if ($category) {
            $model->category = $this->categories->findOneBySlug($category);
        }

        $form = $this->createForm(TopicType::class, $model);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->isGranted(ForumVoter::POST, $model->category)) {
                $this->addFlash('error', $this->translator->trans('@forum.flash.board_locked'));
            } elseif ($wait = $this->floodWait()) {
                $this->addFlash('error', $this->translator->trans('@forum.flash.flood', ['%seconds%' => $wait]));
            } else {
                $topic = new Topic($this->getUser(), $model->category, $model->title);
                foreach ($model->tags as $tag) {
                    $topic->addTag($tag);
                }
                $post = new Post($this->getUser(), $model->content);
                $topic->addPost($post);

                $this->entityManager->persist($topic);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('@forum.flash.topic_created'));

                return $this->redirectToRoute('forum_topic', ['slug' => $topic->getSlug()]);
            }
        }

        return $this->render('@Forum/client/topic/new.html.twig', [
            'form' => $form->createView(),
            'category' => $model->category,
        ]);
    }

    #[Route('/bbs/{slug}', name: 'forum_topic')]
    public function Show(Request $request, string $slug): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        if (!$topic) {
            throw $this->createNotFoundException('Unknown topic.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::READ, $topic);

        $page = $request->query->getInt('page', 1);
        $posts = $this->paginator->paginate($this->posts->createTopicQuery($topic), $page, $this->postsPerPage);

        // Readers count, authors do not: a member re-reading their own topic
        // is not an audience.
        if ($this->getUser() !== $topic->getAuthor()) {
            $this->topics->incrementViews($topic);
        }

        [$outline, $outlineIndex] = $this->outline($topic);

        $reply = null;
        if ($this->isGranted(ForumVoter::REPLY, $topic)) {
            $draft = new PostModel();
            if ($quoteId = $request->query->getInt('quote')) {
                $quoted = $this->posts->find($quoteId);
                if ($quoted && $quoted->getTopic() === $topic && !$quoted->isDeleted()) {
                    $draft->content = $this->markdown->quote($quoted->getContent(), (string) $quoted->getAuthor());
                    $draft->replyTo = $quoted->getId();
                }
            } elseif ($answered = $request->query->getInt('reply')) {
                // "Répondre à ce message": the same link as a quote, without the quote.
                if (isset($outlineIndex[$answered]) && !$outlineIndex[$answered]['deleted']) {
                    $draft->replyTo = $answered;
                }
            }
            $reply = $this->createForm(PostType::class, $draft, [
                'action' => $this->generateUrl('forum_topic_reply', ['slug' => $topic->getSlug()]),
            ])->createView();
        }

        return $this->render('@Forum/client/topic/show.html.twig', [
            'topic' => $topic,
            'posts' => $posts,
            'outline' => $outline,
            'outline_index' => $outlineIndex,
            'topic_previous' => $this->neighbour($topic, false),
            'topic_next' => $this->neighbour($topic, true),
            'reply' => $reply,
            'is_following' => $this->getUser() && $topic->getFollowers()->contains($this->getUser()),
            'is_liked' => $this->getUser() && $topic->isLiked($this->getUser()),
        ]);
    }

    #[Route('/bbs/{slug}/repondre', name: 'forum_topic_reply', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function Reply(Request $request, string $slug): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        if (!$topic) {
            throw $this->createNotFoundException('Unknown topic.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::REPLY, $topic);

        $model = new PostModel();
        $form = $this->createForm(PostType::class, $model);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($wait = $this->floodWait()) {
                $this->addFlash('error', $this->translator->trans('@forum.flash.flood', ['%seconds%' => $wait]));
                return $this->redirectToRoute('forum_topic', ['slug' => $slug]);
            }

            $post = new Post($this->getUser(), $model->content);
            if ($replyTo = $request->request->getInt('reply_to')) {
                $quoted = $this->posts->find($replyTo);
                if ($quoted && $quoted->getTopic() === $topic) {
                    $post->setReplyTo($quoted);
                }
            }

            $topic->addPost($post);
            $this->entityManager->persist($post);
            $this->entityManager->flush();

            return $this->redirectToPost($post);
        }

        foreach ($form->getErrors(true) as $error) {
            $this->addFlash('error', $error->getMessage());
        }

        return $this->redirectToRoute('forum_topic', ['slug' => $slug]);
    }

    #[Route('/bbs/{slug}/modifier', name: 'forum_topic_edit')]
    #[IsGranted('ROLE_USER')]
    public function Edit(Request $request, string $slug): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        if (!$topic) {
            throw $this->createNotFoundException('Unknown topic.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::EDIT, $topic);

        $model = new TopicModel();
        $model->title = $topic->getTitle();
        $model->category = $topic->getCategory();
        $model->tags = $topic->getTags()->toArray();

        // Moving a topic to another board is moderation; an author only
        // retitles and retags.
        $form = $this->createForm(TopicType::class, $model, ['with_content' => false]);
        if (!$this->isGranted(ForumVoter::MODERATE)) {
            $form->remove('category');
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $topic->setTitle($model->title);
            if ($form->has('category') && $model->category) {
                $topic->setCategory($model->category);
            }
            foreach ($topic->getTags()->toArray() as $tag) {
                $topic->removeTag($tag);
            }
            foreach ($model->tags as $tag) {
                $topic->addTag($tag);
            }
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('@forum.flash.topic_updated'));

            return $this->redirectToRoute('forum_topic', ['slug' => $topic->getSlug()]);
        }

        return $this->render('@Forum/client/topic/edit.html.twig', [
            'form' => $form->createView(),
            'topic' => $topic,
        ]);
    }

    /**
     * Pin, lock, delete: one POST endpoint, one switch. CSRF-protected the
     * plain way (a token in the form), no JavaScript needed.
     */
    #[Route('/bbs/{slug}/moderer/{action}', name: 'forum_topic_moderate', methods: ['POST'], requirements: ['action' => 'pin|unpin|lock|unlock|delete'])]
    #[IsGranted('ROLE_USER')]
    public function Moderate(Request $request, string $slug, string $action): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        if (!$topic) {
            throw $this->createNotFoundException('Unknown topic.');
        }
        if (!$this->isCsrfTokenValid('forum_moderate_' . $topic->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        // Authors may delete their own topic; everything else is moderation.
        $this->denyAccessUnlessGranted('delete' === $action ? ForumVoter::EDIT : ForumVoter::MODERATE, $topic);

        switch ($action) {
            case 'pin': $topic->setPinned(true); break;
            case 'unpin': $topic->setPinned(false); break;
            case 'lock': $topic->setLocked(true); break;
            case 'unlock': $topic->setLocked(false); break;
            case 'delete':
                $category = $topic->getCategory();
                $this->entityManager->remove($topic);
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('@forum.flash.topic_deleted'));
                return $this->redirectToRoute('forum_category', ['slug' => $category->getSlug()]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('@forum.flash.moderated'));

        return $this->redirectToRoute('forum_topic', ['slug' => $topic->getSlug()]);
    }

    #[Route('/bbs/message/{id}/modifier', name: 'forum_post_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function EditPost(Request $request, int $id): Response
    {
        $post = $this->posts->find($id);
        if (!$post || $post->isDeleted()) {
            throw $this->createNotFoundException('Unknown post.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::EDIT, $post);

        $model = PostModel::fromPost($post);
        $form = $this->createForm(PostType::class, $model);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $post->setContent($model->content);
            if ($post->getAuthor() === $this->getUser()) {
                $post->markEdited();
            }
            $this->entityManager->flush();

            return $this->redirectToPost($post);
        }

        return $this->render('@Forum/client/post/edit.html.twig', [
            'form' => $form->createView(),
            'post' => $post,
        ]);
    }

    #[Route('/bbs/message/{id}/supprimer', name: 'forum_post_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function DeletePost(Request $request, int $id): Response
    {
        $post = $this->posts->find($id);
        if (!$post) {
            throw $this->createNotFoundException('Unknown post.');
        }
        if (!$this->isCsrfTokenValid('forum_post_' . $post->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::EDIT, $post);

        $topic = $post->getTopic();
        if ($post->isFirst()) {
            // The opening post IS the topic: deleting it deletes the topic.
            $this->denyAccessUnlessGranted(ForumVoter::EDIT, $topic);
            $category = $topic->getCategory();
            $this->entityManager->remove($topic);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('@forum.flash.topic_deleted'));

            return $this->redirectToRoute('forum_category', ['slug' => $category->getSlug()]);
        }

        // A soft delete keeps the numbering; a moderator may restore.
        if ($post->isDeleted() && $this->isGranted(ForumVoter::MODERATE)) {
            $post->restore();
        } else {
            $post->delete($this->getUser());
        }
        $this->entityManager->flush();

        return $this->redirectToPost($post);
    }

    /**
     * The whole topic as a tree, from one light query, for the timeline and
     * the "Arbre à messages" (public/js/forum-thread.js).
     *
     * A post's parent is the message it answers (replyTo). A post answering
     * nothing continues the trunk - and so does one answering the trunk's
     * last message, the way "Citer" on the latest post is still the same
     * conversation. Answering an older message forks a branch from it;
     * answering the tip of a branch extends that branch.
     *
     * Each entry: id, number, page, parent (post id), branch (0 for the
     * trunk, else the id of the post that forked it), level (how many forks
     * from the trunk), depth (position along its branch), author, authorId,
     * at, deleted, replies (how many posts answer it) and firstReply.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<int, array<string, mixed>>} the outline, and the same keyed by post id
     */
    private function outline(Topic $topic): array
    {
        $outline = [];
        $index = [];   // post id => position in $outline
        $tips = [];    // branch id => the id of its last post
        $trunk = null; // the id of the trunk's last post

        foreach ($this->posts->findOutline($topic) as $i => $row) {
            $id = (int) $row['id'];
            // A reply to a post not seen yet (or not in this topic) answers nothing.
            $replyTo = null !== $row['replyTo'] && isset($index[(int) $row['replyTo']]) ? (int) $row['replyTo'] : null;

            if (null === $replyTo || $replyTo === $trunk) {
                $parent = $trunk;
                $branch = 0;
                $level = 0;
                $depth = null === $trunk ? 0 : $outline[$index[$trunk]]['depth'] + 1;
                $trunk = $id;
            } else {
                $from = $outline[$index[$replyTo]];
                $extends = 0 !== $from['branch'] && $tips[$from['branch']] === $replyTo;
                $parent = $replyTo;
                $branch = $extends ? $from['branch'] : $id;
                $level = $extends ? $from['level'] : $from['level'] + 1;
                $depth = $extends ? $from['depth'] + 1 : 1;
                $tips[$branch] = $id;
            }

            if (null !== $replyTo) {
                $answered = &$outline[$index[$replyTo]];
                ++$answered['replies'];
                $answered['firstReply'] ??= $id;
                unset($answered);
            }

            $index[$id] = \count($outline);
            $outline[] = [
                'id' => $id,
                'number' => $i + 1,
                'page' => intdiv($i, max(1, $this->postsPerPage)) + 1,
                'parent' => $parent,
                'branch' => $branch,
                'level' => $level,
                'depth' => $depth,
                'author' => $row['author'],
                'authorId' => null !== $row['authorId'] ? (int) $row['authorId'] : null,
                'at' => $row['at']?->format('c'),
                'deleted' => null !== $row['deletedAt'],
                'replies' => 0,
                'firstReply' => null,
            ];
        }

        return [$outline, array_combine(array_keys($index), $outline)];
    }

    /** The topic before or after this one on its board - 2004's "« Sujet précédent / Sujet suivant »". */
    private function neighbour(Topic $topic, bool $next): ?Topic
    {
        return $this->topics->createQueryBuilder('t')
            ->andWhere('t.category = :category')->setParameter('category', $topic->getCategory())
            ->andWhere($next ? 't.id > :id' : 't.id < :id')->setParameter('id', $topic->getId())
            ->orderBy('t.id', $next ? \SortDirection::Ascending : \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Seconds still to wait before posting again, 0 when clear. */
    private function floodWait(): int
    {
        if ($this->floodInterval <= 0 || $this->isGranted(ForumVoter::MODERATE)) {
            return 0;
        }

        $last = $this->posts->findLastByAuthor($this->getUser());
        if (!$last || !$last->getCreatedAt()) {
            return 0;
        }

        $elapsed = time() - $last->getCreatedAt()->getTimestamp();

        return max(0, $this->floodInterval - $elapsed);
    }

    private function redirectToPost(Post $post): Response
    {
        $page = $this->posts->findPageOf($post, $this->postsPerPage);

        return $this->redirect($this->generateUrl('forum_topic', [
            'slug' => $post->getTopic()->getSlug(),
            'page' => $page > 1 ? $page : null,
        ]) . '#post-' . $post->getId());
    }
}

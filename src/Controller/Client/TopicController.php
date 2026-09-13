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

        $reply = null;
        if ($this->isGranted(ForumVoter::REPLY, $topic)) {
            $draft = new PostModel();
            if ($quoteId = $request->query->getInt('quote')) {
                $quoted = $this->posts->find($quoteId);
                if ($quoted && $quoted->getTopic() === $topic && !$quoted->isDeleted()) {
                    $draft->content = $this->markdown->quote($quoted->getContent(), (string) $quoted->getAuthor());
                    $draft->replyTo = $quoted->getId();
                }
            }
            $reply = $this->createForm(PostType::class, $draft, [
                'action' => $this->generateUrl('forum_topic_reply', ['slug' => $topic->getSlug()]),
            ])->createView();
        }

        return $this->render('@Forum/client/topic/show.html.twig', [
            'topic' => $topic,
            'posts' => $posts,
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

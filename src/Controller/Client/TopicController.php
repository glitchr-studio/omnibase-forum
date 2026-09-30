<?php

namespace Base\Forum\Controller\Client;

use Base\Attributes\Attribute\Sitemap;
use Base\Forum\Entity\Category;
use Base\Forum\Entity\Poll;
use Base\Forum\Entity\PollVote;
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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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
    /**
     * A topic's two looks: the messages as separate cards, or the 2005
     * board's one box with its rows (topic/show.html.twig). Chosen with
     * the reader's choice (ForumController::Display()), remembered per browser in STYLE_COOKIE.
     */
    public const STYLES = ['retro', 'cards'];
    public const STYLE_COOKIE = 'FORUM/TOPIC';

    /**
     * Asked by forum-thread.js for one more page of a topic being read as a
     * single stream (Discourse's way): the page's posts alone, and the
     * reading is not counted again.
     */
    public const STREAM_HEADER = 'X-Forum-Stream';

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

        /*
         * A new topic out of a message (?depuis=<post id>), Discourse's "reply
         * as linked topic": when a message takes the conversation somewhere
         * else, it gets a topic of its own, on the same board unless another
         * is chosen, opening with a link back to where it started.
         */
        $source = null;
        if ($from = $request->query->getInt('depuis')) {
            $post = $this->posts->find($from);
            if ($post && !$post->isDeleted() && $post->getTopic() && $this->isGranted(ForumVoter::READ, $post->getTopic())) {
                $source = $post;
                $model->category ??= $post->getTopic()->getCategory();
                $model->content = $this->translator->trans('@forum.topic.linked_opening', [
                    'title' => $post->getTopic()->getTitle(),
                    'number' => $post->getNumber(),
                    'url' => $this->postUrl($post),
                ]) . "\n\n";
            }
        }

        // When it comes out, for whoever moderates a board; in whose name, for the forum admin.
        $withAuthor = $this->isGranted(ForumVoter::ADMIN);
        if ($withAuthor) {
            $model->author = $this->getUser() instanceof User ? $this->getUser() : null;
        }
        // Started from inside a board the writer may post in: that board, with nothing to choose.
        // (The list leaves locked boards out, so an announcement board - locked to members, open
        // to its admins - could not even be picked from it.)
        $fixed = null !== $category && $model->category instanceof Category && $this->isGranted(ForumVoter::POST, $model->category);

        // Otherwise a board is chosen - from the ones this writer may post in (ForumVoter::POST: a board
        // not locked, readable, an announcement board for its admins, the site's own rules such as a
        // level) and those only. None at all: said, rather than a form that can only be refused.
        $boards = null;
        if (!$fixed) {
            $boards = array_values(array_filter($this->categories->findBoards(), fn (Category $board) => $this->isGranted(ForumVoter::POST, $board)));
            if (!$boards) {
                $this->addFlash('error', $this->translator->trans('@forum.aside.cannot_post'));

                return $this->redirectToRoute('forum_index');
            }
            if ($model->category && !\in_array($model->category, $boards, true)) {
                $model->category = null;
            }
        }
        $form = $this->createForm(TopicType::class, $model, [
            'with_schedule' => $this->isGranted(ForumVoter::SCHEDULE),
            'with_author' => $withAuthor,
            'fixed_category' => $fixed,
            'boards' => $boards,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $author = $this->authorFrom($form, $model);
            $scheduled = $this->scheduleFrom($form, $model, $model->category);

            if (!$author || false === $scheduled) {
                // The field says what is wrong (authorFrom(), scheduleFrom()).
            } elseif (!$this->isGranted(ForumVoter::POST, $model->category)) {
                $this->addFlash('error', $this->translator->trans('@forum.flash.board_locked'));
            } elseif ($wait = $this->floodWait()) {
                $this->addFlash('error', $this->translator->trans('@forum.flash.flood', ['%seconds%' => $wait]));
            } else {
                $topic = new Topic($author, $model->category, $model->title);
                foreach ($model->tags as $tag) {
                    $topic->addTag($tag);
                }
                $post = new Post($author, $model->content);
                $topic->addPost($post);
                if ($model->hasPoll()) {
                    new Poll($topic, trim($model->pollQuestion), $model->pollOptionList(), (int) ($model->pollMax ?: 1));
                }
                $topic->schedule($scheduled);

                $this->entityManager->persist($topic);
                $this->entityManager->flush();

                $this->addFlash('success', $topic->isUpcoming()
                    ? $this->translator->trans('@forum.flash.topic_scheduled', ['date' => $this->when($topic->getPublishedAt(), $request->getLocale())])
                    : $this->translator->trans('@forum.flash.topic_created'));

                return $this->redirectToRoute('forum_topic', ['slug' => $topic->getSlug()]);
            }
        }

        return $this->render('@Forum/client/topic/new.html.twig', [
            'form' => $form->createView(),
            'category' => $model->category,
            'source' => $source,
            'source_url' => $source ? $this->postUrl($source) : null,
        ]);
    }

    #[Sitemap(priority: 0.5, changefreq: 'weekly')]
    #[Route('/bbs/{slug}', name: 'forum_topic')]
    public function Show(Request $request, string $slug): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        // Not out yet: not there at all, for whoever may not see it (ForumVoter) - not "forbidden", which would say it exists.
        if (!$topic || ($topic->isUpcoming() && !$this->isGranted(ForumVoter::READ, $topic))) {
            throw $this->createNotFoundException('Unknown topic.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::READ, $topic);

        $page = $request->query->getInt('page', 1);
        $posts = $this->paginator->paginate($this->posts->createTopicQuery($topic), $page, $this->postsPerPage);
        $stream = $request->headers->has(self::STREAM_HEADER);

        // Readers count, authors do not: a member re-reading their own topic
        // is not an audience. Nor is a page the stream loads as they scroll,
        // nor the same reader again - switching between the 2005 and the cards
        // style reloads the topic, and each reload was a view.
        if (!$stream && $this->getUser() !== $topic->getAuthor() && !$topic->isUpcoming() && $this->firstViewInSession($request, $topic)) {
            $this->topics->incrementViews($topic);
        }

        [$outline, $outlineIndex] = $this->outline($topic);

        if ($stream) {
            $style = (string) $request->cookies->get(self::STYLE_COOKIE, self::STYLES[0]);
            $response = $this->render('@Forum/client/topic/_stream.html.twig', [
                'topic' => $topic,
                'posts' => $posts,
                'outline_index' => $outlineIndex,
                'retro' => 'cards' !== $style,
            ]);
            $response->setVary(self::STREAM_HEADER, false);
            $response->setPrivate();

            return $response;
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

        // The style this browser last chose (ForumController::Display()).
        $style = (string) $request->cookies->get(self::STYLE_COOKIE, self::STYLES[0]);
        if (!in_array($style, self::STYLES, true)) {
            $style = self::STYLES[0];
        }

        $response = $this->render('@Forum/client/topic/show.html.twig', [
            'topic_style' => $style,
            'topic' => $topic,
            'posts' => $posts,
            'outline' => $outline,
            'outline_index' => $outlineIndex,
            'topic_previous' => $this->neighbour($topic, false),
            'topic_next' => $this->neighbour($topic, true),
            'jump_boards' => $this->jumpBoards(),
            'reply' => $reply,
            'is_following' => $this->getUser() && $topic->getFollowers()->contains($this->getUser()),
            'is_liked' => $this->getUser() && $topic->isLiked($this->getUser()),
        ]);

        // The same address answers the stream with a fragment: a cache must
        // not hand one for the other.
        $response->setVary(self::STREAM_HEADER, false);

        return $response;
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

    /**
     * A vote on the topic's poll: one answer, or on a poll that allows several
     * (Poll::$maxChoices) up to that many, sent together. Once per member and
     * final once cast, as the 2004 BBS had it; none on a locked topic.
     */
    #[Route('/bbs/{slug}/voter', name: 'forum_topic_vote', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function Vote(Request $request, string $slug): Response
    {
        $topic = $this->topics->findOneBySlug($slug);
        $poll = $topic?->getPoll();
        if (!$topic || !$poll) {
            throw $this->createNotFoundException('Unknown poll.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::READ, $topic);

        if (!$this->isCsrfTokenValid('forum_vote_' . $poll->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $back = $this->redirect($this->generateUrl('forum_topic', ['slug' => $slug]) . '#poll');

        // `choice` (a single-answer poll's button) or `choice[]` (the boxes of one that allows several).
        $sent = $request->request->all()['choice'] ?? [];
        $choices = array_values(array_unique(array_map('intval', is_array($sent) ? $sent : [$sent])));
        $valid = $choices && count(array_filter($choices, [$poll, 'hasOption'])) === count($choices);
        if (!$poll->isOpen()) {
            $this->addFlash('error', $this->translator->trans('@forum.poll.flash.closed'));
        } elseif (!$valid) {
            $this->addFlash('error', $this->translator->trans('@forum.poll.flash.no_choice'));
        } elseif (count($choices) > $poll->getMaxChoices()) {
            $this->addFlash('error', $this->translator->trans('@forum.poll.flash.too_many', ['max' => $poll->getMaxChoices()]));
        } elseif ($poll->getChoicesOf($this->getUser())) {
            $this->addFlash('error', $this->translator->trans('@forum.poll.flash.already'));
        } else {
            try {
                // All the picks in one flush: a member's vote is whole, or not there.
                foreach ($choices as $choice) {
                    $this->entityManager->persist(new PollVote($poll, $this->getUser(), $choice));
                }
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('@forum.poll.flash.voted'));
            } catch (UniqueConstraintViolationException) {
                // A second click that arrived with the first: the first one counted.
                $this->addFlash('error', $this->translator->trans('@forum.poll.flash.already'));
            }
        }

        return $back;
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
        // Not in this form (the opening post is edited as a post), but the
        // model requires it: left empty, every edit failed on a field the page
        // does not show, and was sent back without a word.
        $model->content = $topic->getFirstPost()?->getContent();

        // Its hour can still move while it is to come, and the forum admin can
        // change whose name it is under.
        $withSchedule = $topic->isUpcoming() && $this->isGranted(ForumVoter::SCHEDULE, $topic->getCategory());
        $withAuthor = $this->isGranted(ForumVoter::ADMIN);
        if ($withSchedule) {
            // In the reader's timezone: a date comes back from the database in
            // UTC, and the form prints a DateTime's own wall time as it is.
            $model->publishedAt = \DateTime::createFromInterface($topic->getPublishedAt())
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }
        if ($withAuthor) {
            $model->author = $topic->getAuthor();
        }

        // Moving a topic to another board is moderation; an author only
        // retitles and retags.
        $form = $this->createForm(TopicType::class, $model, [
            'with_content' => false,
            'with_schedule' => $withSchedule,
            'with_author' => $withAuthor,
        ]);
        if (!$this->isGranted(ForumVoter::MODERATE, $topic)) {
            $form->remove('category');
        }
        $form->handleRequest($request);

        $author = null;
        $scheduled = null;
        if ($form->isSubmitted() && $form->isValid()
            && ($author = $this->authorFrom($form, $model, $topic->getAuthor()))
            && false !== ($scheduled = $this->scheduleFrom($form, $model, $model->category ?? $topic->getCategory()))) {
            $topic->setTitle($model->title);
            if ($author !== $topic->getAuthor()) {
                $topic->setAuthor($author);
            }
            if ($form->has('publishedAt')) {
                $topic->schedule($scheduled);
            }
            // Only into a board its mover moderates too - not a moderator's topic into the announcements.
            if ($form->has('category') && $model->category && $this->isGranted(ForumVoter::MODERATE, $model->category)) {
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

    /** A message's own address: its place in its topic, on whichever page it is. */
    #[Route('/bbs/message/{id}', name: 'forum_post', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ShowPost(int $id): Response
    {
        $post = $this->posts->find($id);
        if (!$post || !$post->getTopic()) {
            throw $this->createNotFoundException('Unknown post.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::READ, $post->getTopic());

        return $this->redirectToPost($post);
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
        if ($post->isDeleted() && $this->isGranted(ForumVoter::MODERATE, $post)) {
            $post->restore();
        } else {
            $post->delete($this->getUser());
        }
        $this->entityManager->flush();

        return $this->redirectToPost($post);
    }

    /**
     * The whole topic as a tree, from one light query: the timeline
     * (public/js/forum-thread.js), and the hierarchy the posts are read with -
     * the "en réponse à" line, the branch's indent, the ">>n" links.
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

    /**
     * The boards the reader may read, by group: the "Sauter vers" at the
     * foot of a topic (topic/show.html.twig).
     *
     * @return array<string, list<Category>>
     */
    private function jumpBoards(): array
    {
        $boards = [];
        foreach ($this->categories->findTree() as $group) {
            foreach ($group->getChildren() as $board) {
                if ($this->isGranted(ForumVoter::READ, $board)) {
                    $boards[$group->getTitle()][] = $board;
                }
            }
        }

        return $boards;
    }
    private function neighbour(Topic $topic, bool $next): ?Topic
    {
        return $this->topics->whereOut($this->topics->createQueryBuilder('t'))
            ->andWhere('t.category = :category')->setParameter('category', $topic->getCategory())
            ->andWhere($next ? 't.id > :id' : 't.id < :id')->setParameter('id', $topic->getId())
            ->orderBy('t.id', $next ? \SortDirection::Ascending : \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Whose name the topic goes out under: the member picked in the form, for
     * the forum admin (ForumVoter::ADMIN); anyone else, themselves - or, on an
     * edit, whoever it already was. The picker only offers members that exist,
     * so there is no name to look up and nothing to refuse.
     */
    private function authorFrom(FormInterface $form, TopicModel $model, ?User $current = null): ?User
    {
        $me = $this->getUser();
        if ($form->has('author') && $model->author instanceof User) {
            return $model->author;
        }

        return $current ?? ($me instanceof User ? $me : null);
    }

    /**
     * When the topic comes out: the hour in the form - null for "now" - or
     * false, with the field saying why, when it was given one for a board its
     * writer may not schedule on.
     */
    private function scheduleFrom(FormInterface $form, TopicModel $model, ?Category $board): \DateTimeInterface|false|null
    {
        if (!$form->has('publishedAt') || !$model->publishedAt) {
            return null;
        }
        if ($model->publishedAt <= new \DateTime()) {
            return null;
        }
        if (!$board || !$this->isGranted(ForumVoter::SCHEDULE, $board)) {
            $form->get('publishedAt')->addError(new FormError($this->translator->trans('@forum.form.schedule_denied')));

            return false;
        }

        return $model->publishedAt;
    }

    /** A date as the reader says it, in their own timezone (base-bundle sets it per request): "24 sept. 2026, 18:00". */
    private function when(\DateTimeInterface $at, string $locale): string
    {
        return (string) \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, date_default_timezone_get())->format($at);
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

    /**
     * A message's address: its topic, at the page it is on, at the message.
     * The page is added by hand: the router's own query string came out as
     * "/bbs/un-sujet/?page=3", a different address from the one every link
     * of the topic writes.
     */
    private function postUrl(Post $post): string
    {
        $page = $this->posts->findPageOf($post, $this->postsPerPage);

        return rtrim($this->generateUrl('forum_topic', ['slug' => $post->getTopic()->getSlug()]), '/')
            . ($page > 1 ? '?page=' . $page : '') . '#post-' . $post->getId();
    }

    private function redirectToPost(Post $post): Response
    {
        $page = $this->posts->findPageOf($post, $this->postsPerPage);

        return $this->redirect($this->generateUrl('forum_topic', [
            'slug' => $post->getTopic()->getSlug(),
            'page' => $page > 1 ? $page : null,
        ]) . '#post-' . $post->getId());
    }

    /** Topics this session has already been counted on, newest last; kept short. */
    private const SEEN_SESSION_KEY = 'forum_topics_seen';
    private const SEEN_MAX = 200;

    /** True the first time this session opens the topic, and remembers it. */
    private function firstViewInSession(Request $request, Topic $topic): bool
    {
        if (!$request->hasSession()) {
            return true;
        }
        $session = $request->getSession();
        $seen = (array) $session->get(self::SEEN_SESSION_KEY, []);
        if (in_array($topic->getId(), $seen, true)) {
            return false;
        }
        $seen[] = $topic->getId();
        $session->set(self::SEEN_SESSION_KEY, array_slice($seen, -self::SEEN_MAX));

        return true;
    }
}

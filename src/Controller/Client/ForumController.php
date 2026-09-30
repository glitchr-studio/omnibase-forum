<?php

namespace Base\Forum\Controller\Client;

use Base\Attributes\Attribute\Sitemap;
use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Form\Model\CategoryModel;
use Base\Forum\Form\Type\CategoryType;
use Base\Forum\Repository\CategoryRepository;
use Base\Forum\Repository\TopicRepository;
use Base\Forum\Security\ForumVoter;
use Base\Service\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reading side of the forum: the board index, a board, a tag, the
 * search. Writing lives in TopicController.
 *
 * Route names are `forum_*`; paths stay under /bbs, the name the site has
 * always given its forum ("BBS (forums)" in the menu).
 */
class ForumController extends AbstractController
{
    private readonly CategoryRepository $categories;
    private readonly TopicRepository $topics;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
        private readonly TranslatorInterface $translator,
        #[Autowire('%forum.topics_per_page%')] private readonly int $topicsPerPage = 20,
        // phpBB's hot_threshold: the replies from which a topic is popular,
        // and with it the board that holds it.
        #[Autowire('%forum.hot_threshold%')] private readonly int $hotThreshold = 25,
    ) {
        $this->categories = $entityManager->getRepository(Category::class);
        $this->topics = $entityManager->getRepository(\Base\Forum\Entity\Topic::class);
    }

    /**
     * The index's views. "classic" is the phpBB board list, grouped; "feed"
     * is the flat, Discourse-like feed with the announcements and the pinned
     * topics as post-its on top. A view no longer offered - a cookie from before
     * (the French names these once had, the "arbre" the BBS was once grown as) -
     * falls back to the first.
     */
    public const VIEWS = ['classic', 'feed'];

    /** The feed's two orders: the latest activity, or what is busiest lately. */
    public const SORTS = ['recent', 'popular'];
    public const VIEW_COOKIE = 'FORUM/VIEW';
    public const SORT_COOKIE = 'FORUM/SORT';

    /**
     * How a reader likes the BBS drawn, each choice remembered per browser for a year - never carried in
     * the address: the index's view, the feed's order, a topic's style. What the buttons send
     * (display()), name => [cookie, allowed values].
     */
    public const DISPLAY = [
        'view' => [self::VIEW_COOKIE, self::VIEWS],
        'sort' => [self::SORT_COOKIE, self::SORTS],
        'style' => [TopicController::STYLE_COOKIE, TopicController::STYLES],
    ];

    /**
     * A display choice (DISPLAY) remembered, then back to the page it was made on. A small POST form per
     * button rather than a query parameter: the address stays the page's own, and reloading it,
     * sharing it or coming back to it later shows what the reader last chose. Any other name or value is
     * ignored; `back` is only ever a path on this site.
     */
    #[Route('/bbs/display', name: 'forum_display', methods: ['POST'])]
    public function Display(Request $request): Response
    {
        $back = (string) $request->request->get('back', '');
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = $this->generateUrl('forum_index');
        }
        $response = $this->redirect($back);

        foreach (self::DISPLAY as $name => [$cookie, $allowed]) {
            $value = (string) $request->request->get($name, '');
            if (in_array($value, $allowed, true)) {
                $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create($cookie, $value, new \DateTimeImmutable('+1 year'), '/', null, $request->isSecure(), true, false, 'lax'));
            }
        }

        return $response;
    }

    #[Sitemap(priority: 0.7, changefreq: 'daily')]
    #[Route('/bbs', name: 'forum_index')]
    public function Index(Request $request): Response
    {
        $groups = $this->categories->findTree();
        $counts = $this->topics->countPerCategory();

        // Boards the visitor may read; the latest-activity feed is filtered
        // to them so a private board never leaks its titles.
        $readable = [];
        $announcing = [];
        foreach ($groups as $group) {
            foreach ($group->getChildren() as $board) {
                if ($this->isGranted(ForumVoter::READ, $board)) {
                    $readable[] = $board->getId();
                    if ($board->isAnnouncement()) {
                        $announcing[] = $board->getId();
                    }
                }
            }
        }

        // The order this browser last chose (Display()); the feed shows the switch, and the classic view's
        // own "latest" list follows it.
        $sort = (string) $request->cookies->get(self::SORT_COOKIE, '');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = self::SORTS[0];
        }

        $query = 'popular' === $sort
            ? $this->topics->createTrendingQuery($readable)
            : $this->topics->createLatestQuery($readable);
        $latest = $this->paginator->paginate($query, $request->query->getInt('page', 1), $this->topicsPerPage);

        // The view this browser last chose (Display()).
        $view = (string) $request->cookies->get(self::VIEW_COOKIE, self::VIEWS[0]);
        if (!in_array($view, self::VIEWS, true)) {
            $view = self::VIEWS[0];
        }

        // One more page of the feed for its stream (forum-feed.js): the rows
        // alone, and the view it was asked from is not remembered again.
        if ('feed' === $view && $request->headers->has(TopicController::STREAM_HEADER)) {
            $response = $this->render('@Forum/client/_feed_rows.html.twig', ['latest' => $latest]);
            $response->setVary(TopicController::STREAM_HEADER, false);
            $response->setPrivate();

            return $response;
        }

        if ('feed' === $view) {
            $response = $this->render('@Forum/client/index_fil.html.twig', [
                'view' => $view,
                'sort' => $sort,
                'groups' => $groups,
                'announcements' => $this->topics->findAnnouncements($announcing),
                'pinned' => $this->topics->findPinned($readable),
                'latest' => $latest,
                'tags' => $this->topics->findTagUsage(),
            ]);
        } else {
            $response = $this->render('@Forum/client/index.html.twig', [
                'view' => $view,
                'groups' => $groups,
                'counts' => $counts,
                // The boards holding a popular topic, so a board row wears the
                // same badge its topics do. One grouped query for the page.
                'hot' => $this->topics->findHotCategoryIds($this->hotThreshold),
                // Each board's last message: who, when, in which topic.
                'last' => $this->topics->findLastPerCategory(),
                'latest' => $latest,
                'tags' => $this->topics->findTagUsage(),
            ]);
        }

        $response->setVary(TopicController::STREAM_HEADER, false);

        return $response;
    }

    #[Sitemap(priority: 0.6, changefreq: 'daily')]
    #[Route('/bbs/c/{slug}', name: 'forum_category')]
    public function Category(Request $request, string $slug): Response
    {
        $category = $this->categories->findOneBySlug($slug);
        if (!$category) {
            throw $this->createNotFoundException('Unknown board.');
        }
        $this->denyAccessUnlessGranted(ForumVoter::READ, $category);

        // A group has no topics of its own: show its boards instead.
        if ($category->isGroup()) {
            return $this->render('@Forum/client/group.html.twig', [
                'group' => $category,
                'counts' => $this->topics->countPerCategory(),
                'hot' => $this->topics->findHotCategoryIds($this->hotThreshold),
                // Each board's latest message, which wakes its folder badge.
                'last' => $this->topics->findLastPerCategory(),
            ]);
        }

        // Its moderators see what is scheduled on it too, marked as such (_topic_row.html.twig).
        $topics = $this->paginator->paginate($this->topics->createCategoryQuery($category, $this->isGranted(ForumVoter::SCHEDULE, $category)), $request->query->getInt('page', 1), $this->topicsPerPage);
        if ($rows = $this->streamRows($request, $topics, false)) {
            return $rows;
        }

        return $this->streamed($this->render('@Forum/client/category.html.twig', [
            'category' => $category,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
            // The head's folder badge reads as the board's row does on the index.
            'board_last' => $this->topics->findLastPerCategory()[$category->getId()] ?? null,
            'board_hot' => (bool) ($this->topics->findHotCategoryIds($this->hotThreshold)[$category->getId()] ?? false),
        ]));
    }

    /**
     * "Nouveau forum": a board opened from the BBS itself, for FORUM_ADMIN
     * (forum.admin_role). ?dans=<group slug> chooses its group; left without
     * one, it is a new group. It lands last in its group - the order page
     * (/admin/bbs/ordre) moves it, and the back office's CRUD has the rest.
     *
     * Declared with a priority: "/bbs/{slug}" would take "nouveau-forum" for a topic.
     */
    #[Route('/bbs/nouveau-forum', name: 'forum_category_new', priority: 5)]
    public function CategoryNew(Request $request): Response
    {
        $this->denyAccessUnlessGranted(ForumVoter::ADMIN);

        $model = new CategoryModel();
        $within = $this->categories->findOneBySlug((string) $request->query->get('dans', ''));
        if ($within && $within->isGroup()) {
            $model->parent = $within;
        }

        $form = $this->createForm(CategoryType::class, $model);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = new Category(trim($model->title), $model->parent);
            $category->setDescription(trim((string) $model->description) ?: null);
            $category->setAnnouncement($model->announcement);
            $category->setPolls($model->polls);
            $siblings = $category->getParent()?->getChildren()->toArray() ?? $this->categories->findBy(['parent' => null]);
            // Tens, as the order page numbers them (OrderController): room to slot one in between later.
            $category->setPosition(10 + max([0, ...array_map(fn (Category $c) => $c->getPosition(), $siblings)]));

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans($category->isGroup() ? '@forum.flash.group_created' : '@forum.flash.board_created', ['title' => $category->getTitle()]));

            return $this->redirectToRoute('forum_category', ['slug' => $category->getSlug()]);
        }

        return $this->render('@Forum/client/category_new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/bbs/t/{slug}', name: 'forum_tag')]
    public function Tag(Request $request, string $slug): Response
    {
        $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['slug' => $slug]);
        if (!$tag) {
            throw $this->createNotFoundException('Unknown tag.');
        }

        $topics = $this->paginator->paginate($this->topics->createTagQuery($tag), $request->query->getInt('page', 1), $this->topicsPerPage);
        if ($rows = $this->streamRows($request, $topics, true)) {
            return $rows;
        }

        return $this->streamed($this->render('@Forum/client/tag.html.twig', [
            'tag' => $tag,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
        ]));
    }

    #[Route('/bbs/recherche', name: 'forum_search')]
    public function Search(Request $request): Response
    {
        $term = trim((string) $request->query->get('q', ''));
        $topics = null;

        if (mb_strlen($term) >= 3) {
            $topics = $this->paginator->paginate($this->topics->createSearchQuery($term), $request->query->getInt('page', 1), $this->topicsPerPage);
            if ($rows = $this->streamRows($request, $topics, true)) {
                return $rows;
            }
        }

        return $this->streamed($this->render('@Forum/client/search.html.twig', [
            'term' => $term,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
        ]));
    }

    /**
     * One more page of a topic list for its stream (forum-feed.js): the rows
     * alone (_topic_rows.html.twig), when the stream asks with its header.
     */
    private function streamRows(Request $request, $topics, bool $showCategory): ?Response
    {
        if (!$request->headers->has(TopicController::STREAM_HEADER)) {
            return null;
        }

        $response = $this->render('@Forum/client/_topic_rows.html.twig', ['topics' => $topics, 'show_category' => $showCategory]);
        $response->setPrivate();

        return $this->streamed($response);
    }

    /** The same address answers the stream with rows alone: a cache must not hand one for the other. */
    private function streamed(Response $response): Response
    {
        $response->setVary(TopicController::STREAM_HEADER, false);

        return $response;
    }
}

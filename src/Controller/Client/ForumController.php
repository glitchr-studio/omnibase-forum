<?php

namespace Base\Forum\Controller\Client;

use Base\Attributes\Attribute\Sitemap;
use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
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
        #[Autowire('%forum.topics_per_page%')] private readonly int $topicsPerPage = 20,
        // phpBB's hot_threshold: the replies from which a topic is popular,
        // and with it the board that holds it.
        #[Autowire('%forum.hot_threshold%')] private readonly int $hotThreshold = 25,
    ) {
        $this->categories = $entityManager->getRepository(Category::class);
        $this->topics = $entityManager->getRepository(\Base\Forum\Entity\Topic::class);
    }

    /**
     * The index's views. "classique" is the phpBB board list, grouped; "fil"
     * is the flat, Discourse-like feed with the announcements and the pinned
     * topics as post-its on top. A view no longer offered - a remembered
     * cookie, an old ?vue= link, the "arbre" the BBS was once grown as -
     * falls back to the first.
     */
    public const VIEWS = ['classique', 'fil'];

    /** The feed's two orders: the latest activity, or what is busiest lately (?tri=). */
    public const SORTS = ['recent', 'populaire'];
    public const VIEW_COOKIE = 'FORUM/VIEW';

    #[Sitemap(priority: 0.7, changefreq: 'daily')]
    #[Route('/bbs', name: 'forum_index')]
    public function Index(Request $request): Response
    {
        $groups = $this->categories->findTree();
        $counts = $this->topics->countPerCategory();

        // Boards the visitor may read; the latest-activity feed is filtered
        // to them so a private board never leaks its titles.
        $readable = [];
        $locked = [];
        foreach ($groups as $group) {
            foreach ($group->getChildren() as $board) {
                if ($this->isGranted(ForumVoter::READ, $board)) {
                    $readable[] = $board->getId();
                    if ($board->isLocked()) {
                        $locked[] = $board->getId();
                    }
                }
            }
        }

        // The order asked for (?tri=); the feed shows the switch, and the
        // classic view's own "latest" list follows it when it is asked for.
        $sort = (string) $request->query->get('tri', '');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = self::SORTS[0];
        }

        $query = 'populaire' === $sort
            ? $this->topics->createTrendingQuery($readable)
            : $this->topics->createLatestQuery($readable);
        $latest = $this->paginator->paginate($query, $request->query->getInt('page', 1), $this->topicsPerPage);

        // The view asked for (?vue=), or the one this browser last chose.
        $asked = (string) $request->query->get('vue', '');
        $view = in_array($asked, self::VIEWS, true) ? $asked : (string) $request->cookies->get(self::VIEW_COOKIE, self::VIEWS[0]);
        if (!in_array($view, self::VIEWS, true)) {
            $view = self::VIEWS[0];
        }

        // One more page of the feed for its stream (forum-feed.js): the rows
        // alone, and the view it was asked from is not remembered again.
        if ('fil' === $view && $request->headers->has(TopicController::STREAM_HEADER)) {
            $response = $this->render('@Forum/client/_feed_rows.html.twig', ['latest' => $latest]);
            $response->setVary(TopicController::STREAM_HEADER, false);
            $response->setPrivate();

            return $response;
        }

        if ('fil' === $view) {
            $response = $this->render('@Forum/client/index_fil.html.twig', [
                'view' => $view,
                'sort' => $sort,
                'groups' => $groups,
                'announcements' => $this->topics->findAnnouncements($locked),
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

        // Remembered per browser, a year: the choice holds for a reader who
        // is not signed in as much as for a member.
        $response->setVary(TopicController::STREAM_HEADER, false);
        if ($asked === $view) {
            $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create(self::VIEW_COOKIE, $view, new \DateTimeImmutable('+1 year'), '/', null, $request->isSecure(), true, false, 'lax'));
        }

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
            ]);
        }

        $topics = $this->paginator->paginate($this->topics->createCategoryQuery($category), $request->query->getInt('page', 1), $this->topicsPerPage);
        if ($rows = $this->streamRows($request, $topics, false)) {
            return $rows;
        }

        return $this->streamed($this->render('@Forum/client/category.html.twig', [
            'category' => $category,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
        ]));
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

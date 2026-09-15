<?php

namespace Base\Forum\Controller\Client;

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
    ) {
        $this->categories = $entityManager->getRepository(Category::class);
        $this->topics = $entityManager->getRepository(\Base\Forum\Entity\Topic::class);
    }

    /**
     * The index's views. "classique" is the phpBB board list, grouped; "fil"
     * is the flat, Discourse-like feed with the announcements and the pinned
     * topics as post-its on top. A third one (the historical message tree)
     * slots in here with its own template.
     */
    public const VIEWS = ['classique', 'fil'];
    public const VIEW_COOKIE = 'FORUM/VIEW';

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

        $latest = $this->paginator->paginate($this->topics->createLatestQuery($readable), $request->query->getInt('page', 1), $this->topicsPerPage);

        // The view asked for (?vue=), or the one this browser last chose.
        $asked = (string) $request->query->get('vue', '');
        $view = in_array($asked, self::VIEWS, true) ? $asked : (string) $request->cookies->get(self::VIEW_COOKIE, self::VIEWS[0]);
        if (!in_array($view, self::VIEWS, true)) {
            $view = self::VIEWS[0];
        }

        if ('fil' === $view) {
            $response = $this->render('@Forum/client/index_fil.html.twig', [
                'view' => $view,
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
                // Each board's last message: who, when, in which topic.
                'last' => $this->topics->findLastPerCategory(),
                'latest' => $latest,
                'tags' => $this->topics->findTagUsage(),
            ]);
        }

        // Remembered per browser, a year: the choice holds for a reader who
        // is not signed in as much as for a member.
        if ($asked === $view) {
            $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create(self::VIEW_COOKIE, $view, new \DateTimeImmutable('+1 year'), '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }

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
            ]);
        }

        $topics = $this->paginator->paginate($this->topics->createCategoryQuery($category), $request->query->getInt('page', 1), $this->topicsPerPage);

        return $this->render('@Forum/client/category.html.twig', [
            'category' => $category,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
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

        return $this->render('@Forum/client/tag.html.twig', [
            'tag' => $tag,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
        ]);
    }

    #[Route('/bbs/recherche', name: 'forum_search')]
    public function Search(Request $request): Response
    {
        $term = trim((string) $request->query->get('q', ''));
        $topics = null;

        if (mb_strlen($term) >= 3) {
            $topics = $this->paginator->paginate($this->topics->createSearchQuery($term), $request->query->getInt('page', 1), $this->topicsPerPage);
        }

        return $this->render('@Forum/client/search.html.twig', [
            'term' => $term,
            'topics' => $topics,
            'tags' => $this->topics->findTagUsage(),
        ]);
    }
}

<?php

namespace Base\Forum\Repository;

use Base\Entity\Thread\Tag;
use Base\Forum\Entity\Category;
use Base\Forum\Entity\Topic;
use Base\Repository\ThreadRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/**
 * Extends ThreadRepository (not ServiceEntityRepository) on purpose: Thread
 * is #[Hierarchify], which requires HierarchifyTrait on the repository of
 * every subclass, and that trait comes with the parent.
 *
 * @method Topic|null find($id, $lockMode = null, $lockVersion = null)
 * @method Topic|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Topic[]    findAll()
 * @method Topic[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class TopicRepository extends ThreadRepository
{
    /**
     * A listing query: pinned first, then by last activity. Soft-deleted
     * topics are filtered by base-bundle's Trasheable filter, so nothing to
     * do here about them.
     */
    public function createListQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->orderBy('t.pinned', \SortDirection::Descending)
            ->addOrderBy('t.lastPostAt', \SortDirection::Descending)
            ->addOrderBy('t.createdAt', \SortDirection::Descending);
    }

    public function createCategoryQuery(Category $category): Query
    {
        return $this->createListQueryBuilder()
            ->andWhere('t.category = :category')->setParameter('category', $category)
            ->getQuery();
    }

    public function createTagQuery(Tag $tag): Query
    {
        return $this->createListQueryBuilder()
            ->innerJoin('t.tags', 'tag')
            ->andWhere('tag = :tag')->setParameter('tag', $tag)
            ->getQuery();
    }

    /**
     * Latest activity across the whole forum, for the front page and the
     * "unread" style feeds - never pinned first here, that only makes sense
     * inside a board.
     */
    public function createLatestQuery(?array $readableCategoryIds = null): Query
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->orderBy('t.lastPostAt', \SortDirection::Descending)
            ->addOrderBy('t.createdAt', \SortDirection::Descending);

        if (null !== $readableCategoryIds) {
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $readableCategoryIds ?: [0]);
        }

        return $qb->getQuery();
    }

    /**
     * The pinned topics of the given boards, most recently active first: the
     * post-its of the flat view. Readable boards only - the caller passes
     * them, as for createLatestQuery().
     *
     * @param int[] $boardIds
     * @return Topic[]
     */
    public function findPinned(array $boardIds, int $limit = 6): array
    {
        if (!$boardIds) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->andWhere('t.pinned = true')
            ->andWhere('c.id IN (:ids)')->setParameter('ids', $boardIds)
            ->orderBy('t.lastPostAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * The announcements of the flat view: the latest topics of the boards
     * only the staff write in (locked boards, such as "Messages Officiels").
     *
     * @param int[] $boardIds the readable locked boards
     * @return Topic[]
     */
    public function findAnnouncements(array $boardIds, int $limit = 2): array
    {
        if (!$boardIds) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->andWhere('c.id IN (:ids)')->setParameter('ids', $boardIds)
            ->orderBy('t.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Plain title/content search: a LIKE over the topic title and its posts.
     * Small forums do not need an index; when the site wants Typesense, the
     * Topic entity is where a mapping goes.
     */
    public function createSearchQuery(string $term): Query
    {
        $like = '%' . mb_strtolower(trim($term)) . '%';

        return $this->createListQueryBuilder()
            ->leftJoin('t.translations', 'i')
            ->leftJoin('t.posts', 'p')
            ->andWhere('LOWER(i.title) LIKE :term OR LOWER(p.content) LIKE :term')
            ->setParameter('term', $like)
            ->distinct()
            ->getQuery();
    }

    public function findOneBySlug(string $slug): ?Topic
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->andWhere('t.slug = :slug')->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * A view is not an edit: bump the counter with a direct UPDATE so the
     * Thread's updatedAt (Timestamp on update) is not touched by readers.
     */
    public function incrementViews(Topic $topic): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Topic::class . ' t SET t.views = t.views + 1 WHERE t.id = :id'
        )->setParameter('id', $topic->getId())->execute();
    }

    /**
     * Per-board counters for the index page: topics and replies, in one
     * query. Keyed by category id.
     *
     * @return array<int, array{topics:int, replies:int}>
     */
    public function countPerCategory(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) AS category, COUNT(t.id) AS topics, COALESCE(SUM(t.replies), 0) AS replies')
            ->groupBy('t.category')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['category']] = ['topics' => (int) $row['topics'], 'replies' => (int) $row['replies']];
        }

        return $counts;
    }

    /**
     * The boards holding at least one popular topic, for the folder badge of
     * a board row. One grouped query for the whole index rather than a count
     * per row, and the very threshold a topic row uses, so "populaire" means
     * the same thing at both levels of the hierarchy.
     *
     * @return array<int, true> keyed by category id, to be read with
     *                          hot[board.id]|default(false) in Twig
     */
    public function findHotCategoryIds(int $threshold): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) AS category')
            ->andWhere('t.replies >= :threshold')->setParameter('threshold', $threshold)
            ->groupBy('t.category')
            ->getQuery()->getArrayResult();

        $hot = [];
        foreach ($rows as $row) {
            $hot[(int) $row['category']] = true;
        }

        return $hot;
    }

    /**
     * The most recently active topic of each board, for the classic index's
     * "last message" column (who wrote last, when, in which topic), as
     * phpBB's forum list had it. One query: a topic whose last activity is
     * its board's latest. Keyed by category id.
     *
     * @return array<int, Topic>
     */
    public function findLastPerCategory(): array
    {
        $topics = $this->createQueryBuilder('t')
            ->leftJoin('t.lastPoster', 'lp')->addSelect('lp')
            ->andWhere('t.lastPostAt = (SELECT MAX(t2.lastPostAt) FROM ' . Topic::class . ' t2 WHERE t2.category = t.category)')
            ->getQuery()->getResult();

        $last = [];
        foreach ($topics as $topic) {
            $id = $topic->getCategory()?->getId();
            if (null !== $id && !isset($last[$id])) {
                $last[$id] = $topic;
            }
        }

        return $last;
    }

    /**
     * Tags in use on the forum - the tag cloud. Rows of
     * ['tag' => Tag, 'nb' => int]. Queried from the Tag side: DQL will not
     * select a joined entity without its root alias, and the count is what
     * the join is for.
     *
     * Highest Tag::$priority first, as the admin set it on /admin/bbs/ordre;
     * usage only decides between tags of equal priority - which, until an
     * admin orders them, is all of them (priority 0).
     */
    public function findTagUsage(int $limit = 30): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('tag, COUNT(t.id) AS nb')
            ->from(Tag::class, 'tag')
            ->innerJoin('tag.threads', 't')
            ->andWhere('t INSTANCE OF ' . Topic::class)
            ->groupBy('tag.id')
            ->orderBy('tag.priority', \SortDirection::Descending)
            ->addOrderBy('nb', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        // A mixed entity + scalar result comes back as [0 => Tag, 'nb' => n].
        return array_map(fn ($row) => ['tag' => $row[0], 'nb' => (int) $row['nb']], $rows);
    }
}

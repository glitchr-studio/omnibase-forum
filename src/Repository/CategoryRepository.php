<?php

namespace Base\Forum\Repository;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Forum\Entity\Category;

/**
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Category[]    findAll()
 * @method Category[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRepository extends ServiceEntityRepository
{
    /**
     * The whole tree in display order: groups first, each followed by its
     * boards - one query, then assembled in memory.
     *
     * @return Category[] root groups, with their children loaded
     */
    public function findTree(): array
    {
        $all = $this->createQueryBuilder('c')
            ->leftJoin('c.parent', 'p')->addSelect('p')
            ->orderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.title', \SortDirection::Ascending)
            ->getQuery()->getResult();

        return array_values(array_filter($all, fn (Category $c) => $c->isGroup()));
    }

    /** @return Category[] the boards (leaves) topics can be posted in */
    public function findBoards(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.parent', 'p')->addSelect('p')
            ->orderBy('p.position', \SortDirection::Ascending)->addOrderBy('c.position', \SortDirection::Ascending)->addOrderBy('c.title', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}

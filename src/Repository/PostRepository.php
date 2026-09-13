<?php

namespace Base\Forum\Repository;

use App\Entity\User;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Forum\Entity\Post;
use Base\Forum\Entity\Topic;
use Doctrine\ORM\Query;

/**
 * @method Post|null find($id, $lockMode = null, $lockVersion = null)
 * @method Post|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Post[]    findAll()
 * @method Post[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class PostRepository extends ServiceEntityRepository
{
    public function createTopicQuery(Topic $topic): Query
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->andWhere('p.topic = :topic')->setParameter('topic', $topic)
            ->orderBy('p.createdAt', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery();
    }

    /** The member's most recent post, for the flood check. */
    public function findLastByAuthor(User $author): ?Post
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.author = :author')->setParameter('author', $author)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Which page of a topic a post lands on, for permalinks. */
    public function findPageOf(Post $post, int $perPage): int
    {
        $before = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.topic = :topic')->setParameter('topic', $post->getTopic())
            ->andWhere('p.createdAt < :at OR (p.createdAt = :at AND p.id < :id)')
            ->setParameter('at', $post->getCreatedAt())->setParameter('id', $post->getId())
            ->getQuery()->getSingleScalarResult();

        return intdiv($before, max(1, $perPage)) + 1;
    }

    /** How many posts a member wrote - the profile's "Messages sur le forum". */
    public function countByAuthor(User $author): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.author = :author')->setParameter('author', $author)
            ->andWhere('p.deletedAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }
}

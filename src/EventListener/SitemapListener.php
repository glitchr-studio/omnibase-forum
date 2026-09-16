<?php

namespace Base\Forum\EventListener;

use Base\Event\SitemapEvent;
use Base\Forum\Entity\Category;
use Base\Forum\Repository\CategoryRepository;
use Base\Forum\Repository\TopicRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The forum's own pages in /sitemap.xml: base-bundle lists the routes it can
 * generate by itself, and a board or a topic is behind a slug it cannot know.
 *
 * Only what a visitor may read goes in: a board reserved to a role is left
 * out, and so is everything written in it. A topic carries the date of its
 * last message, so a crawler comes back to the ones that move.
 */
#[AsEventListener(event: SitemapEvent::BUILD)]
final class SitemapListener
{
    /** Enough for a busy forum, short of a sitemap's 50 000 URL limit. */
    public const MAX_TOPICS = 5000;

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TopicRepository $topics,
    ) {
    }

    public function __invoke(SitemapEvent $event): void
    {
        $sitemap = $event->getSitemapper();

        $public = array_filter($this->categories->findBoards(), fn (Category $board) => null === $board->getRequiredRole());
        if (!$public) {
            return;
        }

        foreach ($public as $board) {
            $sitemap->register('forum_category', ['slug' => $board->getSlug()], $board->getUpdatedAt()?->format('c'));
        }

        $topics = $this->topics->createLatestQuery(array_map(fn (Category $board) => $board->getId(), $public))
            ->setMaxResults(self::MAX_TOPICS)
            ->getResult();

        foreach ($topics as $topic) {
            $sitemap->register('forum_topic', ['slug' => $topic->getSlug()], $topic->getActivityAt()?->format('c'));
        }
    }
}

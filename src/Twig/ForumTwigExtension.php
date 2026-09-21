<?php

namespace Base\Forum\Twig;

use Base\Forum\Entity\Category;
use Base\Forum\Entity\Topic;
use Base\Forum\Service\MarkdownRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ForumTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly MarkdownRenderer $markdown,
        // phpBB's hot_threshold, as the site configured it
        // (config/packages/forum.yaml); never a number in a template.
        #[Autowire('%forum.hot_threshold%')] private readonly int $hotThreshold = 25,
        // How recent a message keeps a folder awake (forum.active_hours).
        #[Autowire('%forum.active_hours%')] private readonly int $activeHours = 72,
    ) {
    }

    public function getFilters(): array
    {
        return [
            // Output is sanitised by the renderer itself (raw HTML escaped),
            // hence is_safe.
            new TwigFilter('forum_markdown', [$this->markdown, 'render'], ['is_safe' => ['html']]),
            new TwigFilter('forum_excerpt', [$this->markdown, 'excerpt']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('forum_quote', [$this->markdown, 'quote']),
            new TwigFunction('forum_is_hot', $this->isHot(...)),
            new TwigFunction('forum_is_active', $this->isActive(...)),
            new TwigFunction('forum_active_hours', fn (): int => $this->activeHours),
            new TwigFunction('forum_topic_folder', $this->topicFolder(...)),
            new TwigFunction('forum_board_folder', $this->boardFolder(...)),
        ];
    }

    /**
     * phpBB's "popular" flag: a topic is hot from hot_threshold replies on.
     * The templates ask for it instead of carrying the number, so a topic
     * row, a board row and the legend all mean the same thing.
     */
    public function isHot(Topic|int|null $subject): bool
    {
        if (null === $subject) {
            return false;
        }

        return ($subject instanceof Topic ? $subject->getReplies() : $subject) >= $this->hotThreshold;
    }

    public function getHotThreshold(): int
    {
        return $this->hotThreshold;
    }

    /**
     * Awake or asleep: a message within forum.active_hours. The folder art
     * says it - the "zzz" face sleeps, the one with a speech bubble talks.
     * Recent activity, not "unread": base-bundle keeps no per-member read
     * marker, and a badge that meant "unread" could only ever be false.
     */
    public function isActive(Topic|\DateTimeInterface|null $subject): bool
    {
        $at = $subject instanceof Topic ? $subject->getActivityAt() : $subject;

        return null !== $at && $at >= new \DateTimeImmutable(sprintf('-%d hours', $this->activeHours));
    }

    /**
     * A topic row's badge, phpBB's precedence top down: an announcement (a
     * topic of an announcement board), a post-it, a locked topic, a popular
     * one, then the plain folder - the last three awake or asleep.
     *
     * @return array{state: string, title: string} title is a translation key, or ''
     */
    public function topicFolder(Topic $topic): array
    {
        $awake = $this->isActive($topic);
        [$state, $title] = match (true) {
            (bool) $topic->getCategory()?->isAnnouncement() => ['announce', '@forum.folder.announce'],
            $topic->isPinned() => ['sticky', '@forum.topic.pinned'],
            $topic->isLocked() => [$awake ? 'new_lock' : 'lock', '@forum.topic.locked'],
            $this->isHot($topic) => [$awake ? 'new_hot' : 'hot', '@forum.folder.hot'],
            default => [$awake ? 'new' : 'default', $awake ? '@forum.folder.new' : ''],
        };

        return ['state' => $state, 'title' => $title];
    }

    /**
     * A board's badge: an announcement board, a locked one, one holding a
     * popular topic, then the plain folder - the last three awake or asleep
     * after the board's latest message. A board reserved to a rank wears the
     * ordinary badge; the tooltip still says it is reserved.
     *
     * @return array{state: string, title: string}
     */
    public function boardFolder(Category $board, ?Topic $last = null, bool $hot = false): array
    {
        $awake = $this->isActive($last);
        [$state, $title] = match (true) {
            $board->isAnnouncement() => ['announce', '@forum.folder.announce_board'],
            $board->isLocked() => [$awake ? 'new_lock' : 'lock', '@forum.board.locked'],
            $hot => [$awake ? 'new_hot' : 'hot', '@forum.folder.hot_board'],
            default => [$awake ? 'new' : 'default', $awake ? '@forum.folder.new_board' : ''],
        };
        if ('' === $title && $board->getRequiredRole()) {
            $title = '@forum.board.private';
        }

        return ['state' => $state, 'title' => $title];
    }
}

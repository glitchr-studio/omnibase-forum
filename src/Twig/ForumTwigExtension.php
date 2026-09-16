<?php

namespace Base\Forum\Twig;

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
}

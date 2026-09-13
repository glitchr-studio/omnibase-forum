<?php

namespace Base\Forum\Twig;

use Base\Forum\Service\MarkdownRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ForumTwigExtension extends AbstractExtension
{
    public function __construct(private readonly MarkdownRenderer $markdown)
    {
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
        ];
    }
}

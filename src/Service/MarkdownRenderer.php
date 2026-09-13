<?php

namespace Base\Forum\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Posts are Markdown, rendered here with GitHub flavouring (tables, task
 * lists, strikethrough, autolinks) and NO raw HTML: anything a member types
 * as a tag is escaped, links are never allowed to be javascript:, and
 * nesting is capped so a pathological post cannot stall the renderer.
 *
 * Two forum habits are added on top of CommonMark:
 *   @pseudo    links to the member's profile (if the route exists);
 *   >>12       links to post #12 of the same topic.
 */
class MarkdownRenderer
{
    private ?MarkdownConverter $converter = null;

    public function __construct(private readonly ?RouterInterface $router = null)
    {
    }

    public function render(?string $markdown): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }

        $markdown = $this->linkPostReferences($markdown);
        $html = (string) $this->converter()->convert($markdown);

        return $this->linkMentions($html);
    }

    /** A one-line, tag-free excerpt for lists and previews. */
    public function excerpt(?string $markdown, int $length = 160): string
    {
        $text = strip_tags($this->render($markdown));
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)) . '…' : $text;
    }

    /** Quote a post the way a reply box pre-fills it. */
    public function quote(?string $markdown, ?string $author = null): string
    {
        $lines = preg_split('/\R/u', trim((string) $markdown)) ?: [];
        $quoted = implode("\n", array_map(fn ($l) => '> ' . $l, $lines));

        return ($author ? '> **@' . $author . '** a écrit :' . "\n" : '') . $quoted . "\n\n";
    }

    private function converter(): MarkdownConverter
    {
        if (null !== $this->converter) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());

        return $this->converter = new MarkdownConverter($environment);
    }

    /** ">>12" (at a word boundary) becomes a link to the post anchor "#post-12". */
    private function linkPostReferences(string $markdown): string
    {
        return preg_replace('/(?<![\w>])>>(\d{1,5})\b/u', '[>>$1](#post-$1)', $markdown) ?? $markdown;
    }

    /**
     * "@pseudo" becomes a profile link. Done on the HTML, outside of tags and
     * of already-built anchors, so an @ inside a URL or a code span stays.
     */
    private function linkMentions(string $html): string
    {
        if (null === $this->router || !str_contains($html, '@')) {
            return $html;
        }

        try {
            $route = $this->router->getRouteCollection()->get('user_profileByUsername') ? 'user_profileByUsername' : null;
        } catch (\Throwable) {
            $route = null;
        }
        if (null === $route) {
            return $html;
        }

        $parts = preg_split('/(<code[\s\S]*?<\/code>|<pre[\s\S]*?<\/pre>|<a [\s\S]*?<\/a>|<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $i => $part) {
            if ('' === $part || '<' === $part[0]) {
                continue;
            }

            $parts[$i] = preg_replace_callback('/(?<![\w\/])@([A-Za-z0-9_\-]{2,32})\b/u', function ($m) use ($route) {
                $url = $this->router->generate($route, ['username' => $m[1]], UrlGeneratorInterface::ABSOLUTE_PATH);
                return '<a class="mention" href="' . htmlspecialchars($url, ENT_QUOTES) . '">@' . $m[1] . '</a>';
            }, $part) ?? $part;
        }

        return implode('', $parts);
    }
}

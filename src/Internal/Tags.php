<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Internal;

use WP_HTML_Tag_Processor;

/**
 * What `BlockNode` needs to know about the tags of an HTML chunk.
 *
 * Pure functions over a string, so they can be tested with plain markup. They read the chunk
 * with the WordPress HTML API rather than searching the text, which would also match inside
 * comments.
 *
 * @internal Used by `BlockNode`; not part of the supported API.
 */
final class Tags
{
    /**
     * HTML elements that never have a closing tag.
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#void-elements
     */
    public const array VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Elements whose content the WordPress HTML API reads as part of their opening token, so
     * their closing tag is never visited.
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#raw-text-elements
     */
    private const array RAW_TEXT_ELEMENTS = [
        'iframe', 'noembed', 'noframes', 'script', 'style', 'textarea', 'title', 'xmp',
    ];

    private function __construct()
    {
    }

    /**
     * The elements the markup leaves open, outermost first, or null when it opens none.
     *
     * A closer also ends the elements still open inside the one it closes.
     *
     * @return list<string>|null Lowercase tag names.
     */
    public static function openElements(string $html): ?array
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $open = [];
        $found = false;
        while ($processor->next_tag(['tag_closers' => 'visit'])) {
            $tagName = \strtolower((string) $processor->get_tag());

            if ($processor->is_tag_closer()) {
                $index = \array_search($tagName, \array_reverse($open, true), true);
                if ($index !== false) {
                    \array_splice($open, $index);
                }
                continue;
            }

            $found = true;
            if (\in_array($tagName, self::VOID_ELEMENTS, true) || \in_array($tagName, self::RAW_TEXT_ELEMENTS, true)) {
                continue;
            }
            if ($processor->has_self_closing_flag() && self::isForeign($tagName, $open)) {
                continue;
            }
            $open[] = $tagName;
        }

        return $found ? $open : null;
    }

    /**
     * Whether a tag belongs to SVG or MathML content, the only place where HTML honors the
     * self-closing slash: `<path/>` inside an svg opens nothing, `<div/>` opens a div.
     *
     * @param list<string> $open The elements open around the tag.
     */
    private static function isForeign(string $tagName, array $open): bool
    {
        return $tagName === 'svg'
            || $tagName === 'math'
            || \in_array('svg', $open, true)
            || \in_array('math', $open, true);
    }

    /**
     * Where the children of an empty container go in its markup.
     *
     * Inside the last empty `div`, which is the one the editor leaves for the inner blocks of a
     * cover, a media-text or a legacy group (an empty `figure` or `video` after it is media, not a
     * slot); otherwise, as in a details block whose summary holds text, before the last closing
     * tag. Null when the markup has no closing tag.
     */
    public static function emptyPosition(string $html): ?int
    {
        // The processor knows where each token lies but only exposes it through a bookmark, which
        // subclasses can read.
        $processor = new class ($html) extends WP_HTML_Tag_Processor {
            /**
             * @return array{int, int}|null Start offset and length of the current token.
             */
            public function tokenSpan(): ?array
            {
                return $this->set_bookmark('token')
                    ? [$this->bookmarks['token']->start, $this->bookmarks['token']->length]
                    : null;
            }
        };

        $lastCloser = null;
        $lastEmpty = null;
        // The last opener, with the offset right after it, as long as only whitespace followed.
        $opener = null;
        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            if ($type === '#text') {
                if (\trim($processor->get_modifiable_text()) !== '') {
                    $opener = null;
                }
                continue;
            }

            if ($type !== '#tag') {
                $opener = null;
                continue;
            }

            $span = $processor->tokenSpan();
            $tagName = \strtolower((string) $processor->get_tag());
            if ($processor->is_tag_closer()) {
                $lastCloser = $span[0] ?? $lastCloser;
                if ($tagName === 'div' && $opener !== null && $opener[0] === $tagName) {
                    $lastEmpty = $opener[1];
                }
                $opener = null;
                continue;
            }

            $opener = $span !== null && !$processor->has_self_closing_flag() && !\in_array($tagName, self::VOID_ELEMENTS, true)
                ? [$tagName, $span[0] + $span[1]]
                : null;
        }

        return $lastEmpty ?? $lastCloser;
    }
}

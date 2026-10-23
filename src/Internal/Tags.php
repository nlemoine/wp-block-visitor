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
            if (!$processor->has_self_closing_flag() && !\in_array($tagName, self::VOID_ELEMENTS, true)) {
                $open[] = $tagName;
            }
        }

        return $found ? $open : null;
    }

    /**
     * Where the children of an empty container go in its markup: before its last closing tag.
     *
     * Null when the markup has no closing tag.
     */
    public static function emptyPosition(string $html): ?int
    {
        // The processor knows where each tag starts but only exposes it through a bookmark, which
        // subclasses can read.
        $processor = new class ($html) extends WP_HTML_Tag_Processor {
            public function tokenOffset(): ?int
            {
                return $this->set_bookmark('token') ? $this->bookmarks['token']->start : null;
            }
        };

        $position = null;
        while ($processor->next_tag(['tag_closers' => 'visit'])) {
            if ($processor->is_tag_closer()) {
                $position = $processor->tokenOffset();
            }
        }

        return $position;
    }
}

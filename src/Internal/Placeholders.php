<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Internal;

use LogicException;

/**
 * The layout rules of an `innerContent` list, where the k-th `null` is the placeholder of
 * child k and the strings around them are HTML.
 *
 * Pure functions over the list, so they can be tested with plain arrays. They know nothing of
 * the nodes: the caller passes the number of children, and whether the list is a document
 * root, whose separators are freeform blocks rather than chunks.
 *
 * @internal Used by `BlockNode`; not part of the supported API.
 */
final class Placeholders
{
    /**
     * Separator Gutenberg emits between two sibling blocks, used when a list has none to copy.
     */
    public const string SEPARATOR = "\n\n";

    private function __construct()
    {
    }

    public static function isSeparator(?string $chunk): bool
    {
        return \is_string($chunk) && \trim($chunk) === '';
    }

    /**
     * Position of the placeholder of the given child.
     *
     * @param list<string|null> $content
     * @param int $total Number of placeholders in the list.
     *
     * @throws LogicException When the list has no such placeholder.
     */
    public static function position(array $content, int $child, int $total): int
    {
        // Scanning from the nearer end keeps appending and removing in reverse order linear
        // on lists with many children, where scanning from the start is quadratic.
        if ($child * 2 < $total) {
            $seen = -1;
            foreach ($content as $position => $chunk) {
                if ($chunk === null && ++$seen === $child) {
                    return $position;
                }
            }
        } else {
            $seen = $total;
            for ($position = \count($content) - 1; $position >= 0; $position--) {
                if ($content[$position] === null && --$seen === $child) {
                    return $position;
                }
            }
        }

        throw new LogicException(\sprintf('innerContent and innerBlocks are out of sync: no placeholder for child %d.', $child));
    }

    /**
     * Builds `[SEP?] null (SEP null)* [SEP?]`, without any chunk when the separator is empty.
     *
     * @return list<string|null>
     */
    public static function run(int $count, string $separator, bool $leadingSeparator, bool $trailingSeparator): array
    {
        if ($count <= 0) {
            return [];
        }

        $glue = $separator === '' ? [] : [$separator];
        $run = $leadingSeparator ? $glue : [];
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                \array_push($run, ...$glue);
            }
            $run[] = null;
        }
        if ($trailingSeparator) {
            \array_push($run, ...$glue);
        }

        return $run;
    }

    /**
     * The separator a new sibling gets: the one already used between the first two children.
     *
     * Adjacent placeholders mean the list uses none, and a single whitespace chunk is copied as
     * is. Markup between them, or fewer than two children, falls back to the blank line
     * Gutenberg emits. A root always uses the blank line.
     *
     * @param list<string|null> $content
     */
    public static function separator(array $content, bool $root): string
    {
        if ($root) {
            return self::SEPARATOR;
        }

        $between = null;
        foreach ($content as $chunk) {
            if ($chunk !== null) {
                if ($between !== null) {
                    $between[] = $chunk;
                }
                continue;
            }

            if ($between !== null) {
                return self::separatorFrom($between);
            }
            $between = [];
        }

        return self::SEPARATOR;
    }

    /**
     * Where placeholders go in a list without any: before the last chunk when the markup is
     * split in two or more, after it otherwise.
     *
     * @param list<string|null> $content
     */
    public static function emptyPosition(array $content): int
    {
        $count = \count($content);

        return $count >= 2 ? $count - 1 : $count;
    }

    /**
     * Inserts placeholders for `$count` new children before child `$index`, or after the last
     * one when the index equals the number of children.
     *
     * @param list<string|null> $content
     * @param-out list<string|null> $content
     */
    public static function insert(array &$content, int $index, int $count, int $children, bool $root): void
    {
        $separator = self::separator($content, $root);

        if ($children === 0) {
            $position = self::emptyPosition($content);
            $run = self::run($count, $separator, false, false);
        } elseif ($index === $children) {
            $position = self::position($content, $children - 1, $children) + 1;
            $run = self::run($count, $separator, true, false);
        } else {
            $position = self::position($content, $index, $children);
            $run = self::run($count, $separator, false, true);
        }

        \array_splice($content, $position, 0, $run);
    }

    /**
     * Replaces the placeholder of child `$child` by `$count` placeholders: zero removes it with
     * its separator, one leaves the list as is.
     *
     * @param list<string|null> $content
     * @param-out list<string|null> $content
     */
    public static function replace(array &$content, int $child, int $children, int $count, bool $root): void
    {
        if ($count === 1) {
            return;
        }

        $position = self::position($content, $child, $children);
        if ($count === 0) {
            self::remove($content, $position);

            return;
        }

        $run = self::run($count, self::separator($content, $root), false, false);
        \array_splice($content, $position, 1, $run);
    }

    /**
     * The list once each child keyed in `$counts` is replaced by that many placeholders.
     *
     * Same result as calling `replace()` for each entry from the highest child down, in one
     * pass. The list is walked from the end so that, as one by one, everything on the right is
     * already final when a placeholder is removed, and everything on the left is untouched.
     *
     * @param list<string|null> $content
     * @param array<int, int> $counts
     * @return list<string|null>
     */
    public static function replaceMany(array $content, array $counts, int $children, bool $root): array
    {
        // As one by one, an expansion copies the separator between the first two children. Only
        // child 0 can see that pair changed, by the replacements applied after it.
        $separator = self::separator($content, $root);

        $reversed = [];
        $child = $children;
        for ($position = \count($content) - 1; $position >= 0; $position--) {
            $chunk = $content[$position];
            if ($chunk !== null || !\array_key_exists(--$child, $counts)) {
                $reversed[] = $chunk;
                continue;
            }

            if ($counts[$child] > 0) {
                \array_push($reversed, ...self::reversedExpansion($reversed, $child, $counts[$child], $separator, $root));
                continue;
            }

            // Removal: drop the separator that joined the placeholder to a sibling, as remove() does.
            $top = \count($reversed) - 1;
            if ($top >= 1 && self::isSeparator($reversed[$top]) && $reversed[$top - 1] === null) {
                \array_pop($reversed);
                continue;
            }
            if ($position >= 2 && self::isSeparator($content[$position - 1]) && $content[$position - 2] === null) {
                $position--;
            }
        }

        return \array_reverse($reversed);
    }

    /**
     * Replaces everything from the first placeholder to the last by `$count` placeholders,
     * keeping the content before and after.
     *
     * @param list<string|null> $content
     * @param-out list<string|null> $content
     */
    public static function respan(array &$content, int $count, bool $root): void
    {
        $run = self::run($count, self::separator($content, $root), false, false);

        $positions = \array_keys($content, null, true);
        if ($positions === []) {
            \array_splice($content, self::emptyPosition($content), 0, $run);

            return;
        }

        \array_splice($content, $positions[0], \end($positions) - $positions[0] + 1, $run);
    }

    /**
     * Makes the placeholder count match the child count: surplus placeholders are removed from
     * the end, missing ones appended after the last.
     *
     * Takes and returns the list by value, unlike the other edits: it runs for every node built,
     * and a consistent list, which is every parsed one, comes back without being copied.
     *
     * @param list<string|null> $content
     * @return list<string|null>
     */
    public static function reconcile(array $content, int $children, bool $root): array
    {
        $placeholders = \count(\array_keys($content, null, true));

        if ($placeholders === $children) {
            return $content;
        }

        if ($placeholders > $children) {
            for ($i = $placeholders; $i > $children; $i--) {
                self::remove($content, self::position($content, $i - 1, $i));
            }

            return $content;
        }

        $missing = $children - $placeholders;
        $separator = self::separator($content, $root);
        if ($placeholders === 0) {
            \array_splice($content, self::emptyPosition($content), 0, self::run($missing, $separator, false, false));

            return $content;
        }

        $position = self::position($content, $placeholders - 1, $placeholders) + 1;
        \array_splice($content, $position, 0, self::run($missing, $separator, true, false));

        return $content;
    }

    /**
     * Drops the placeholder at the given position, plus the whitespace separator that joined it to a sibling.
     *
     * @param list<string|null> $content
     * @param-out list<string|null> $content
     */
    public static function remove(array &$content, int $position): void
    {
        \array_splice($content, $position, 1);

        $right = $content[$position] ?? null;
        $left = $position > 0 ? $content[$position - 1] : null;

        if (self::isSeparator($right) && \array_key_exists($position + 1, $content) && $content[$position + 1] === null) {
            \array_splice($content, $position, 1);

            return;
        }

        if ($position >= 2 && self::isSeparator($left) && $content[$position - 2] === null) {
            \array_splice($content, $position - 1, 1);
        }
    }

    /**
     * The placeholders of an expansion in `replaceMany()`, reversed like its output.
     *
     * @param list<string|null> $reversed
     * @return list<string|null>
     */
    private static function reversedExpansion(array $reversed, int $child, int $count, string $separator, bool $root): array
    {
        if ($child === 0 && !$root) {
            $separator = self::separatorAfterFirst($reversed);
        }

        return \array_reverse(self::run($count, $separator, false, false));
    }

    /**
     * `separator()` for child 0 in the middle of `replaceMany()`, read from the content already
     * rebuilt on its right, which is stored reversed.
     *
     * @param list<string|null> $reversed
     */
    private static function separatorAfterFirst(array $reversed): string
    {
        $between = [];
        for ($i = \count($reversed) - 1; $i >= 0; $i--) {
            $chunk = $reversed[$i];
            if ($chunk === null) {
                return self::separatorFrom($between);
            }
            $between[] = $chunk;
        }

        return self::SEPARATOR;
    }

    /**
     * @param list<string> $between The chunks between two consecutive placeholders.
     */
    private static function separatorFrom(array $between): string
    {
        if ($between === []) {
            return '';
        }

        return \count($between) === 1 && self::isSeparator($between[0]) ? $between[0] : self::SEPARATOR;
    }
}

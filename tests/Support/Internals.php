<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Support;

use Closure;
use LogicException;
use n5s\BlockVisitor\BlockNode;

/**
 * Reaches the BlockNode members reserved to the traverser, the same way the traverser does.
 */
final class Internals
{
    public static function lockChildren(BlockNode $node): void
    {
        self::bound(static fn (BlockNode $node) => $node->lockChildren())($node);
    }

    public static function unlockChildren(BlockNode $node): void
    {
        self::bound(static fn (BlockNode $node) => $node->unlockChildren())($node);
    }

    /**
     * @param array<int, list<BlockNode>> $replacements
     */
    public static function applyReplacements(BlockNode $node, array $replacements): BlockNode
    {
        return self::bound(static fn (BlockNode $node, array $replacements): BlockNode => $node->applyReplacements($replacements))($node, $replacements);
    }

    private static function bound(Closure $closure): Closure
    {
        return Closure::bind($closure, null, BlockNode::class) ?? throw new LogicException('Cannot bind to BlockNode.');
    }
}

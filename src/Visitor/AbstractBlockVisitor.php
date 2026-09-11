<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

use n5s\BlockVisitor\BlockNode;

/**
 * Visitor doing nothing; override the hook you need.
 */
abstract class AbstractBlockVisitor implements BlockVisitorInterface
{
    public function enter(BlockNode $block): BlockNode|Traversal|null
    {
        return null;
    }

    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        return null;
    }
}

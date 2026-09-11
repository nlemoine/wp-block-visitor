<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\PrioritizedVisitorInterface;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Removes every `core/paragraph` block, before any other visitor runs.
 */
class ParagraphRemoverVisitor extends AbstractBlockVisitor implements PrioritizedVisitorInterface
{
    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        return $block->getBlockName() === 'core/paragraph' ? Traversal::Remove : null;
    }

    public function getPriority(): int
    {
        return 512;
    }
}

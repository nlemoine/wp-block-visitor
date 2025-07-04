<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\BlockVisitorInterface;
use n5s\BlockVisitor\Visitor\PrioritizedVisitorInterface;

class ParagraphRemoverVisitor implements BlockVisitorInterface, PrioritizedVisitorInterface
{
    public function enter(BlockNode $block): BlockNode|array|null
    {
        return $block;
    }

    public function leave(BlockNode $block): BlockNode|array|null
    {
        return $block;
    }

    public function getPriority(): int
    {
        return 512;
    }
}

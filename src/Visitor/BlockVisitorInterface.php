<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

use n5s\BlockVisitor\BlockNode;

interface BlockVisitorInterface
{
    public function enter(BlockNode $block): BlockNode|array|null;

    public function leave(BlockNode $block): BlockNode|array|null;
}

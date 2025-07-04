<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

use n5s\BlockVisitor\BlockNode;

interface TransformingBlockVisitorInterface
{
    public function transform(BlockNode $block): BlockNode|array|null;
}

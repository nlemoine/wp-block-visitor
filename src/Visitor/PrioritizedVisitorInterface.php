<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

interface PrioritizedVisitorInterface
{
    public function getPriority(): int;
}

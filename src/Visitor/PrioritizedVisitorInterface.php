<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

/**
 * Lets a visitor choose its place in the pass order.
 */
interface PrioritizedVisitorInterface
{
    /**
     * Higher values run first. Visitors without a priority run as if they returned 0.
     * Visitors with the same priority keep their registration order.
     */
    public function getPriority(): int;
}

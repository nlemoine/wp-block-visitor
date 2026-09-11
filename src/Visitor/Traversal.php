<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

/**
 * Control values a visitor can return instead of a node.
 */
enum Traversal
{
    /**
     * Removes the current node. Only valid from `leave()`.
     */
    case Remove;

    /**
     * Skips the children of the current node. `leave()` is still called. Only valid from `enter()`.
     */
    case SkipChildren;

    /**
     * Stops the traversal for the current visitor.
     */
    case Stop;
}

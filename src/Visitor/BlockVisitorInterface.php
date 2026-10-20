<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Visitor;

use n5s\BlockVisitor\BlockNode;

/**
 * Visits every node of a block tree in document order.
 *
 * Mutating the current node and its own subtree in place is always allowed.
 * Structural changes to the current node itself (replace, remove, expand, wrap)
 * go through the return value of `leave()`. Ancestors and siblings must never be
 * mutated from a visitor: the traverser rejects any change to the children of an
 * ancestor, as it rejects a returned node that is an ancestor or a sibling of the
 * current one. An exception thrown from a hook propagates and leaves the tree in an
 * undefined state.
 */
interface BlockVisitorInterface
{
    /**
     * Called before the children of the node are visited.
     *
     * Children added to the node here will be visited. So are the children of a node
     * returned here: returning `BlockNode::wrap($block, ...)` would visit `$block` again
     * and wrap it forever, wrap from `leave()` instead.
     *
     * @return BlockNode|Traversal|null `null` to do nothing, a node to replace the current one
     *                                  (its children are then visited), `Traversal::SkipChildren`
     *                                  or `Traversal::Stop`.
     */
    public function enter(BlockNode $block): BlockNode|Traversal|null;

    /**
     * Called after the children of the node have been visited.
     *
     * Children added to the node here will not be visited.
     *
     * @return BlockNode|list<BlockNode>|Traversal|null `null` to do nothing, a node to replace the
     *                                                  current one, a list of nodes to replace it by
     *                                                  zero or more siblings, `Traversal::Remove` or
     *                                                  `Traversal::Stop`.
     */
    public function leave(BlockNode $block): BlockNode|array|Traversal|null;
}

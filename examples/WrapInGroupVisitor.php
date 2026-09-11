<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Wraps every top-level `core/paragraph` block in a `core/group`.
 *
 * Wrapping happens in `leave()`: the wrapper replaces the paragraph in its parent
 * and is not visited again, so no guard against re-wrapping is needed.
 */
class WrapInGroupVisitor extends AbstractBlockVisitor
{
    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        if ($block->getBlockName() !== 'core/paragraph' || $block->getDepth() !== 0) {
            return null;
        }

        return BlockNode::wrap(
            $block,
            'core/group',
            ['layout' => ['type' => 'constrained']],
            "\n<div class=\"wp-block-group\">",
            "</div>\n"
        );
    }
}

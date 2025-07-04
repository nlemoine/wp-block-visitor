<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\TransformingBlockVisitorInterface;

class GalleryVisitor implements TransformingBlockVisitorInterface
{
    public function transform(BlockNode $block): BlockNode|array|null
    {
        if ($block->getBlockName() !== 'vendor/gallery') {
            return $block;
        }

        $ids = array_map(intval(...), (array) $block->getAttribute('ids'));
        if (count($ids) === 0) {
            return null;
        }

        $images = array_map(static fn (int $id): BlockNode => BlockNode::create([
            'blockName' => 'core/image',
            'attrs' => ['id' => $id],
            'innerContent' => [
                sprintf(
                    '<img src="%s" alt="" class="wp-image-%d">',
                    'https://example.com/wp-content/uploads/' . $id . '.jpg',
                    $id
                ),
            ],
        ], $block), $ids);

        return $block->removeAttribute('ids')->appendInnerBlocks($images);
    }
}

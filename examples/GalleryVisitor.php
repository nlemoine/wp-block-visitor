<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Expands the `ids` attribute of a `vendor/gallery` block into `core/image` children.
 *
 * Done in `leave()` so the images are not visited by this same visitor.
 */
class GalleryVisitor extends AbstractBlockVisitor
{
    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        if ($block->getBlockName() !== 'vendor/gallery') {
            return null;
        }

        $ids = array_map(\intval(...), (array) $block->getAttribute('ids'));
        if (\count($ids) === 0) {
            return Traversal::Remove;
        }

        $images = array_map(static fn (int $id): BlockNode => BlockNode::create([
            'blockName' => 'core/image',
            'attrs' => ['id' => $id],
            'innerContent' => [
                \sprintf(
                    "\n<figure class=\"wp-block-image\"><img src=\"%s\" alt=\"\" class=\"wp-image-%d\"/></figure>\n",
                    'https://example.com/wp-content/uploads/' . $id . '.jpg',
                    $id
                ),
            ],
        ]), $ids);

        $block->removeAttribute('ids')
            ->setInnerContent(["\n<figure class=\"wp-block-gallery\">", "</figure>\n"])
            ->appendInnerBlocks($images);

        return null;
    }
}

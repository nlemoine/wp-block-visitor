<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;
use Stringable;

/**
 * Read-only visitor listing every block with its depth.
 */
class DepthVisitor extends AbstractBlockVisitor implements Stringable
{
    /**
     * @var list<array{blockName: string, depth: int}>
     */
    private array $output = [];

    public function enter(BlockNode $block): BlockNode|Traversal|null
    {
        $blockName = $block->getBlockName();
        if ($blockName === null) {
            return null;
        }

        $this->output[] = [
            'blockName' => $blockName,
            'depth' => $block->getDepth(),
        ];

        return null;
    }

    public function __toString(): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => \sprintf(
                '%s->%d|%s',
                str_repeat(' ', $item['depth']),
                $item['depth'],
                $item['blockName']
            ),
            $this->output
        ));
    }
}

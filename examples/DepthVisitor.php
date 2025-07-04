<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\BlockVisitorInterface;
use Stringable;

class DepthVisitor implements BlockVisitorInterface, Stringable
{
    /**
     * @var array<string, array{
     *     prefix: string,
     *     blockName: string,
     *     depth: int
     * }>
     */
    private array $output = [];

    public function enter(BlockNode $block): BlockNode|array|null
    {
        if ($block->getBlockName() === null) {
            return $block;
        }

        $this->output[] = [
            'prefix' => str_repeat(' ', $block->getDepth()),
            'blockName' => $block->getBlockName(),
            'depth' => $block->getDepth(),
        ];

        return $block;
    }

    public function leave(BlockNode $block): BlockNode|array|null
    {
        return $block;
    }

    public function __toString(): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => sprintf("%s->%d|%s", $item['prefix'], $item['depth'], $item['blockName']),
            $this->output
        ));
    }
}

<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Benchmarks;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;
use PhpBench\Attributes as Bench;

/**
 * Read-only scan: count the blocks of each type, the headline use case of WP_Block_Processor.
 */
#[Bench\BeforeMethods('load')]
#[Bench\ParamProviders('documents')]
#[Bench\Revs(1)]
#[Bench\Iterations(10)]
final class CountBlockTypesBench extends AbstractBench
{
    public function benchBlockTraverser(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            /** @var array<string, int> */
            public array $counts = [];

            public function enter(BlockNode $block): BlockNode|Traversal|null
            {
                $name = $block->getBlockName();
                if ($name !== null) {
                    $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
                }

                return null;
            }
        };
        (new BlockTraverser($visitor))->traverse($this->html);
    }

    public function benchBlockProcessor(): void
    {
        $counts = [];
        $processor = new \WP_Block_Processor($this->html);
        while ($processor->next_block()) {
            $name = $processor->get_block_type();
            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
    }

    public function benchParseBlocks(): void
    {
        $counts = [];
        $blocks = \parse_blocks($this->html);
        self::walkParsed($blocks, static function (array $block) use (&$counts): void {
            if ($block['blockName'] !== null) {
                $counts[$block['blockName']] = ($counts[$block['blockName']] ?? 0) + 1;
            }
        });
    }
}

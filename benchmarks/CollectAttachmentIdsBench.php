<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Benchmarks;

use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Examples\AttachmentIdVisitor;
use PhpBench\Attributes as Bench;

/**
 * Read-only scan: collect the attachment IDs referenced by media blocks.
 */
#[Bench\BeforeMethods('load')]
#[Bench\ParamProviders('documents')]
#[Bench\Revs(1)]
#[Bench\Iterations(10)]
final class CollectAttachmentIdsBench extends AbstractBench
{
    public function benchBlockTraverser(): void
    {
        $visitor = new AttachmentIdVisitor();
        (new BlockTraverser($visitor))->traverse($this->html);
        $visitor->getIds();
    }

    public function benchBlockProcessor(): void
    {
        $ids = [];
        $processor = new \WP_Block_Processor($this->html);
        while ($processor->next_block()) {
            $attributes = AttachmentIdVisitor::CORE_ATTRIBUTES[$processor->get_block_type() ?? ''] ?? null;
            if ($attributes === null) {
                continue;
            }
            $parsed = $processor->allocate_and_return_parsed_attributes() ?? [];
            foreach ($attributes as $attribute) {
                foreach ((array) ($parsed[$attribute] ?? []) as $id) {
                    if (\is_int($id) && $id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
        }
        \array_keys($ids);
    }

    public function benchParseBlocks(): void
    {
        $ids = [];
        $blocks = \parse_blocks($this->html);
        self::walkParsed($blocks, static function (array $block) use (&$ids): void {
            foreach (AttachmentIdVisitor::CORE_ATTRIBUTES[$block['blockName'] ?? ''] ?? [] as $attribute) {
                foreach ((array) ($block['attrs'][$attribute] ?? []) as $id) {
                    if (\is_int($id) && $id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
        });
        \array_keys($ids);
    }
}

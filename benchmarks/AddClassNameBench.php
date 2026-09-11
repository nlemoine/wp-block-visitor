<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Benchmarks;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;
use PhpBench\Attributes as Bench;

/**
 * Mutation: add a class name to every core/image and serialize the document back.
 */
#[Bench\BeforeMethods('load')]
#[Bench\ParamProviders('documents')]
#[Bench\Revs(1)]
#[Bench\Iterations(10)]
final class AddClassNameBench extends AbstractBench
{
    public function benchBlockTraverser(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function enter(BlockNode $block): BlockNode|Traversal|null
            {
                if ($block->getBlockName() === 'core/image') {
                    $block->addClassName('is-lazy');
                }

                return null;
            }
        };
        (string) (new BlockTraverser($visitor))->traverse($this->html);
    }

    public function benchBlockProcessor(): void
    {
        $this->rewriteDelimiters('core/image', static function (array $attributes): array {
            $classNames = \array_filter(\explode(' ', (string) ($attributes['className'] ?? '')));
            $classNames[] = 'is-lazy';
            $attributes['className'] = \implode(' ', \array_unique($classNames));

            return $attributes;
        });
    }

    public function benchParseBlocks(): void
    {
        $blocks = \parse_blocks($this->html);
        self::walkParsed($blocks, static function (array &$block): void {
            if ($block['blockName'] !== 'core/image') {
                return;
            }
            $classNames = \array_filter(\explode(' ', (string) ($block['attrs']['className'] ?? '')));
            $classNames[] = 'is-lazy';
            $block['attrs']['className'] = \implode(' ', \array_unique($classNames));
        });
        \serialize_blocks($blocks);
    }
}

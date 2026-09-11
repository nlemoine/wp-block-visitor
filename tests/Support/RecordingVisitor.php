<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Support;

use Closure;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\PrioritizedVisitorInterface;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Records every hook call and delegates the return value to optional closures.
 */
final class RecordingVisitor extends AbstractBlockVisitor implements PrioritizedVisitorInterface
{
    /**
     * @var list<string>
     */
    public array $log = [];

    /**
     * @param (Closure(BlockNode): (BlockNode|Traversal|null))|null $onEnter
     * @param (Closure(BlockNode): (BlockNode|list<BlockNode>|Traversal|null))|null $onLeave
     */
    public function __construct(
        private readonly ?Closure $onEnter = null,
        private readonly ?Closure $onLeave = null,
        private readonly int $priority = 0,
    ) {
    }

    public function enter(BlockNode $block): BlockNode|Traversal|null
    {
        $this->log[] = 'enter ' . ($block->getBlockName() ?? '(freeform)');

        return $this->onEnter instanceof \Closure ? ($this->onEnter)($block) : null;
    }

    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        $this->log[] = 'leave ' . ($block->getBlockName() ?? '(freeform)');

        return $this->onLeave instanceof \Closure ? ($this->onLeave)($block) : null;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }
}

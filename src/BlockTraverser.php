<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use LogicException;
use n5s\BlockVisitor\Visitor\BlockVisitorInterface;
use n5s\BlockVisitor\Visitor\PrioritizedVisitorInterface;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Applies visitors to a block tree.
 *
 * Each visitor gets its own full pass over the tree, in priority order then
 * registration order, so a visitor sees the result of the previous ones.
 * Within a pass, nodes are visited depth-first in document order: `enter()`
 * before the children, `leave()` after them. The root node itself is never visited.
 */
final readonly class BlockTraverser
{
    /**
     * @var list<BlockVisitorInterface>
     */
    private array $visitors;

    public function __construct(BlockVisitorInterface ...$visitors)
    {
        $this->visitors = $this->sortByPriority(\array_values($visitors));
    }

    /**
     * @return list<BlockVisitorInterface>
     */
    public function getVisitors(): array
    {
        return $this->visitors;
    }

    /**
     * Traverses the tree and returns its (mutated) root.
     *
     * The root itself is never visited, only its descendants are. That holds for a
     * node created by `BlockNode::createRoot()` as well as for any other node passed
     * here. If a visitor throws, the tree is left in an undefined state: discard it.
     */
    public function traverse(string|BlockNode $root): BlockNode
    {
        $root = $root instanceof BlockNode ? $root : BlockNode::createRoot($root);

        foreach ($this->visitors as $visitor) {
            $this->traverseChildren($root, $visitor);
        }

        return $root;
    }

    /**
     * @return bool Whether the visitor asked to stop.
     */
    private function traverseChildren(BlockNode $parent, BlockVisitorInterface $visitor): bool
    {
        $stopped = false;

        /** @var list<array{int, list<BlockNode>}> $replacements */
        $replacements = [];

        foreach ($parent->getInnerBlocks() as $index => $child) {
            $result = $visitor->enter($child);

            if ($result === Traversal::Stop) {
                $stopped = true;
                break;
            }

            if ($result === Traversal::Remove) {
                throw new LogicException('enter() cannot return Traversal::Remove, remove nodes from leave().');
            }

            if (($parent->getInnerBlocks()[$index] ?? null) !== $child) {
                throw $this->detached($parent, $index, $child);
            }
            if ($result instanceof BlockNode && $result !== $child) {
                $parent->replaceInnerBlockAt($index, $result);
                $child = $result;
            }

            if ($result !== Traversal::SkipChildren && $this->traverseChildren($child, $visitor)) {
                $stopped = true;
                break;
            }

            $result = $visitor->leave($child);

            if ($result === Traversal::Stop) {
                $stopped = true;
                break;
            }

            if ($result === Traversal::SkipChildren) {
                throw new LogicException('leave() cannot return Traversal::SkipChildren, the children have already been visited.');
            }

            if (($parent->getInnerBlocks()[$index] ?? null) !== $child) {
                throw $this->detached($parent, $index, $child);
            }

            if ($result === Traversal::Remove) {
                $replacements[] = [$index, []];
            } elseif (\is_array($result)) {
                $replacements[] = [$index, \array_values($result)];
            } elseif ($result instanceof BlockNode && $result !== $child) {
                $replacements[] = [$index, [$result]];
            }
        }

        // Applied last to first so that an expansion does not shift the indexes still to apply.
        foreach (\array_reverse($replacements) as [$index, $nodes]) {
            $parent->replaceInnerBlockAt($index, ...$nodes);
        }

        return $stopped;
    }

    private function detached(BlockNode $parent, int $index, BlockNode $child): LogicException
    {
        return new LogicException(\sprintf(
            'Block "%s" is no longer child %d of "%s". Visitors must not mutate ancestors or siblings; return a value from leave() instead.',
            $child->getBlockName() ?? '(freeform)',
            $index,
            $parent->getBlockName() ?? '(freeform)',
        ));
    }

    /**
     * @param list<BlockVisitorInterface> $visitors
     * @return list<BlockVisitorInterface>
     */
    private function sortByPriority(array $visitors): array
    {
        \usort($visitors, static function (BlockVisitorInterface $a, BlockVisitorInterface $b): int {
            $priorityA = $a instanceof PrioritizedVisitorInterface ? $a->getPriority() : 0;
            $priorityB = $b instanceof PrioritizedVisitorInterface ? $b->getPriority() : 0;

            return $priorityB <=> $priorityA;
        });

        return $visitors;
    }
}

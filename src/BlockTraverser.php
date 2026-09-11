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
final class BlockTraverser
{
    /**
     * @var list<BlockVisitorInterface>
     */
    private readonly array $visitors;

    private bool $stopped = false;

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
            $this->stopped = false;
            $this->traverseChildren($root, $visitor);
        }

        return $root;
    }

    private function traverseChildren(BlockNode $parent, BlockVisitorInterface $visitor): void
    {
        /** @var list<array{BlockNode, list<BlockNode>}> $replacements */
        $replacements = [];

        foreach ($parent->getInnerBlocks() as $child) {
            $result = $visitor->enter($child);

            if ($result === Traversal::Stop) {
                $this->stopped = true;
                break;
            }

            if ($result === Traversal::Remove) {
                throw new LogicException('enter() cannot return Traversal::Remove, remove nodes from leave().');
            }

            $index = $this->indexOf($parent, $child);
            if ($result instanceof BlockNode && $result !== $child) {
                $parent->replaceInnerBlockAt($index, $result);
                $child = $result;
            }

            if ($result !== Traversal::SkipChildren) {
                $this->traverseChildren($child, $visitor);

                if ($this->stopped) {
                    break;
                }
            }

            $result = $visitor->leave($child);

            if ($result === Traversal::Stop) {
                $this->stopped = true;
                break;
            }

            if ($result === Traversal::SkipChildren) {
                throw new LogicException('leave() cannot return Traversal::SkipChildren, the children have already been visited.');
            }

            $this->indexOf($parent, $child);

            if ($result === Traversal::Remove) {
                $replacements[] = [$child, []];
            } elseif (\is_array($result)) {
                $replacements[] = [$child, \array_values($result)];
            } elseif ($result instanceof BlockNode && $result !== $child) {
                $replacements[] = [$child, [$result]];
            }
        }

        foreach ($replacements as [$original, $nodes]) {
            $parent->replaceInnerBlockAt($this->indexOf($parent, $original), ...$nodes);
        }
    }

    private function indexOf(BlockNode $parent, BlockNode $child): int
    {
        return $parent->indexOf($child) ?? throw new LogicException(\sprintf(
            'Block "%s" is no longer a child of "%s". Visitors must not mutate ancestors or siblings; return a value from leave() instead.',
            $child->getBlockName() ?? '(freeform)',
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

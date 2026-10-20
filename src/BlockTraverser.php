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

        /** @var array<int, list<BlockNode>> $replacements */
        $replacements = [];

        foreach ($parent->getInnerBlocks() as $index => $child) {
            $revision = $parent->getChildrenRevision();
            $result = $visitor->enter($child);

            if ($result === Traversal::Stop) {
                $stopped = true;
                break;
            }

            if ($result === Traversal::Remove) {
                throw new LogicException('enter() cannot return Traversal::Remove, remove nodes from leave().');
            }

            $this->assertUntouched($parent, $revision, $index, $child);
            if ($result instanceof BlockNode && $result !== $child) {
                $this->assertNotSibling($parent, $result, 'enter');
                $parent->replaceInnerBlockAt($index, $result);
                $child = $result;
                $revision = $parent->getChildrenRevision();
            }

            if ($result !== Traversal::SkipChildren) {
                $stopped = $this->traverseChildren($child, $visitor);
                $this->assertUntouched($parent, $revision, $index, $child);
                if ($stopped) {
                    break;
                }
            }

            $result = $visitor->leave($child);

            if ($result === Traversal::Stop) {
                $stopped = true;
                break;
            }

            if ($result === Traversal::SkipChildren) {
                throw new LogicException('leave() cannot return Traversal::SkipChildren, the children have already been visited.');
            }

            $this->assertUntouched($parent, $revision, $index, $child);

            if ($result === Traversal::Remove) {
                $replacements[$index] = [];
            } elseif (\is_array($result)) {
                $replacements[$index] = $this->replacementList($parent, $result, $child, $visitor);
            } elseif ($result instanceof BlockNode && $result !== $child) {
                $this->assertNotSibling($parent, $result, 'leave');
                $replacements[$index] = [$result];
            }
        }

        $parent->applyReplacements($replacements);

        return $stopped;
    }

    /**
     * Rejects a hook that changed the children of the parent, which would make the indexes of the
     * pass point at the wrong nodes. Checked in O(1) through the parent's revision counter.
     *
     * @throws LogicException
     */
    private function assertUntouched(BlockNode $parent, int $revision, int $index, BlockNode $child): void
    {
        if ($parent->getChildrenRevision() === $revision) {
            return;
        }

        throw new LogicException(\sprintf(
            'The children of "%s" changed while visiting its child %d "%s". Visitors must not mutate ancestors or siblings; return a value from leave() instead.',
            $parent->getBlockName() ?? '(freeform)',
            $index,
            $child->getBlockName() ?? '(freeform)',
        ));
    }

    /**
     * Rejects a returned sibling: moving it would shift the indexes of the pass.
     *
     * @throws LogicException
     */
    private function assertNotSibling(BlockNode $parent, BlockNode $node, string $hook): void
    {
        if ($node->getParent() !== $parent) {
            return;
        }

        throw new LogicException(\sprintf(
            '%s() cannot return a sibling of the current node ("%s" under "%s"), return a copy or a new node instead.',
            $hook,
            $node->getBlockName() ?? '(freeform)',
            $parent->getBlockName() ?? '(freeform)',
        ));
    }

    /**
     * @param array<mixed> $result
     * @return list<BlockNode>
     *
     * @throws LogicException When an item is not a node, or is a sibling of the current one.
     */
    private function replacementList(BlockNode $parent, array $result, BlockNode $child, BlockVisitorInterface $visitor): array
    {
        $nodes = [];
        foreach (\array_values($result) as $position => $node) {
            if (!$node instanceof BlockNode) {
                throw new LogicException(\sprintf(
                    '%s::leave() returned a list holding %s at position %d, only BlockNode instances are allowed.',
                    $visitor::class,
                    \get_debug_type($node),
                    $position,
                ));
            }

            if ($node !== $child) {
                $this->assertNotSibling($parent, $node, 'leave');
            }
            $nodes[] = $node;
        }

        return $nodes;
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

<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use n5s\BlockVisitor\Visitor\BlockVisitorInterface;
use n5s\BlockVisitor\Visitor\PrioritizedVisitorInterface;
use n5s\BlockVisitor\Visitor\TransformingBlockVisitorInterface;

/**
 * Traverses a tree of BlockNode objects and applies visitors to each node.
 */
final class BlockTraverser
{
    /**
     * @var array<BlockVisitorInterface|TransformingBlockVisitorInterface>
     */
    private array $visitors;

    /**
     * @param BlockVisitorInterface|TransformingBlockVisitorInterface ...$visitors The visitors to apply to the nodes.
     */
    public function __construct(BlockVisitorInterface|TransformingBlockVisitorInterface ...$visitors)
    {
        $this->visitors = $visitors;
    }

    /**
     * @return array<BlockVisitorInterface|TransformingBlockVisitorInterface>
     */
    public function getVisitors(): array
    {
        return $this->visitors;
    }

    /**
     * Traverses the block tree and applies the visitors to each node.
     * The root node will not be visited, only its children.
     *
     * @param BlockNode|string $node The root node of the tree to traverse.
     * @return BlockNode The modified root node.
     */
    public function traverse(string|BlockNode $node): BlockNode
    {
        $root = $node instanceof BlockNode ? $node : BlockNode::createRoot($node);

        $this->sortVisitors();

        $innerBlocks = $root->getInnerBlocks();

        foreach ($this->visitors as $visitor) {
            $newInnerBlocks = [];
            foreach ($innerBlocks as $child) {
                $result = $this->traverseWithVisitor($child, $visitor);
                if ($result === null) {
                    continue;
                }

                if (\is_array($result)) {
                    \array_push($newInnerBlocks, ...$result);
                } else {
                    $newInnerBlocks[] = $result;
                }
            }
            $innerBlocks = $newInnerBlocks;
        }

        $root->setInnerBlocks($innerBlocks);

        return $root;
    }

    /**
     * Traverses a node with a single visitor.
     *
     * @param BlockNode                                                 $node    The node to traverse.
     * @param BlockVisitorInterface|TransformingBlockVisitorInterface $visitor The visitor to apply.
     *
     * @return BlockNode|BlockNode[]|null The modified node, an array of nodes, or null if it was removed.
     */
    private function traverseWithVisitor(
        BlockNode $node,
        BlockVisitorInterface|TransformingBlockVisitorInterface $visitor
    ): BlockNode|array|null {
        $result = $visitor instanceof TransformingBlockVisitorInterface
            ? $visitor->transform($node)
            : $visitor->enter($node);

        if ($result === null) {
            return null;
        }

        if (\is_array($result)) {
            $newNodes = [];
            foreach ($result as $newNode) {
                if ($newNode instanceof BlockNode) {
                    // When enter() returns multiple nodes, we can't call leave() on the original node.
                    // Instead, we traverse the children of each new node.
                    $newNode->setInnerBlocks($this->traverseChildrenWithVisitor($newNode, $visitor));
                    $newNodes[] = $newNode;
                }
            }
            return $newNodes;
        }

        $node = $result;

        $node->setInnerBlocks($this->traverseChildrenWithVisitor($node, $visitor));

        if ($visitor instanceof TransformingBlockVisitorInterface) {
            return $node;
        }

        return $visitor->leave($node);
    }

    /**
     * Traverses the children of a node.
     *
     * @param BlockNode                                                 $node    The parent node.
     * @param BlockVisitorInterface|TransformingBlockVisitorInterface $visitor The visitor to apply.
     *
     * @return BlockNode[] The modified list of children.
     */
    private function traverseChildrenWithVisitor(BlockNode $node, BlockVisitorInterface|TransformingBlockVisitorInterface $visitor): array
    {
        $newChildren = [];
        foreach ($node->getInnerBlocks() as $child) {
            $result = $this->traverseWithVisitor($child, $visitor);
            if ($result === null) {
                continue;
            }

            if (\is_array($result)) {
                \array_push($newChildren, ...$result);
            } else {
                $newChildren[] = $result;
            }
        }
        return $newChildren;
    }

    /**
     * Sorts visitors by priority.
     */
    private function sortVisitors(): void
    {
        usort($this->visitors, static function (BlockVisitorInterface|TransformingBlockVisitorInterface $a, BlockVisitorInterface|TransformingBlockVisitorInterface $b): int {
            $priorityA = $a instanceof PrioritizedVisitorInterface ? $a->getPriority() : 0;
            $priorityB = $b instanceof PrioritizedVisitorInterface ? $b->getPriority() : 0;

            return $priorityB <=> $priorityA;
        });
    }
}

<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Unit;

use Closure;
use LogicException;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Tests\Support\RecordingVisitor;
use n5s\BlockVisitor\Tests\TestCase;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;
use RuntimeException;

final class BlockTraverserTest extends TestCase
{
    private const string GROUP = <<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>A</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>B</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML;

    private const string HEADING = <<<'HTML'
<!-- wp:heading -->
<h2>H</h2>
<!-- /wp:heading -->
HTML;

    private const string TWO_TOP_LEVEL = self::GROUP . "\n\n" . self::HEADING;

    // ------------------------------------------------------------------
    //  Construction
    // ------------------------------------------------------------------

    public function testHasNoVisitorByDefault(): void
    {
        $traverser = new BlockTraverser();

        $this->assertSame([], $traverser->getVisitors());
    }

    public function testSortsVisitorsByPriorityThenRegistrationOrder(): void
    {
        $low = $this->prioritized(-10);
        $plainA = new class extends AbstractBlockVisitor {
        };
        $zero = $this->prioritized(0);
        $plainB = new class extends AbstractBlockVisitor {
        };
        $high = $this->prioritized(10);

        $traverser = new BlockTraverser($low, $plainA, $zero, $plainB, $high);

        $this->assertSame([$high, $plainA, $zero, $plainB, $low], $traverser->getVisitors());
    }

    public function testAcceptsMarkupOrRootNode(): void
    {
        $traverser = new BlockTraverser();
        $root = BlockNode::createRoot(self::GROUP);

        $this->assertSame($root, $traverser->traverse($root));
        $this->assertTrue($traverser->traverse(self::GROUP)->isRoot());
    }

    // ------------------------------------------------------------------
    //  Visiting order and control flow
    // ------------------------------------------------------------------

    public function testVisitsDepthFirstInDocumentOrder(): void
    {
        $visitor = $this->logger();

        (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame([
            'enter core/group',
            'enter core/paragraph',
            'leave core/paragraph',
            'enter core/paragraph',
            'leave core/paragraph',
            'leave core/group',
        ], $visitor->log);
    }

    public function testRootNodeIsNotVisited(): void
    {
        $visitor = $this->logger();

        (new BlockTraverser($visitor))->traverse('');
        (new BlockTraverser($visitor))->traverse(BlockNode::createRoot(''));

        $this->assertSame([], $visitor->log);
    }

    public function testNullDoesNothing(): void
    {
        $root = (new BlockTraverser(new class extends AbstractBlockVisitor {
        }))->traverse(self::GROUP);

        $this->assertSame(self::GROUP, (string) $root);
    }

    public function testSkipChildrenSkipsTheSubtreeButStillLeaves(): void
    {
        $visitor = $this->logger(enter: static fn (BlockNode $block): ?Traversal => $block->getBlockName() === 'core/group' ? Traversal::SkipChildren : null);

        (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame(['enter core/group', 'leave core/group'], $visitor->log);
    }

    public function testStopFromEnterEndsThePassImmediately(): void
    {
        $visitor = $this->logger(enter: static fn (BlockNode $block): ?Traversal => $block->getBlockName() === 'core/paragraph' ? Traversal::Stop : null);

        (new BlockTraverser($visitor))->traverse(self::TWO_TOP_LEVEL);

        $this->assertSame(['enter core/group', 'enter core/paragraph'], $visitor->log);
    }

    public function testStopFromLeaveEndsThePassAtEveryLevel(): void
    {
        $visitor = $this->logger(leave: static fn (BlockNode $block): ?Traversal => $block->getBlockName() === 'core/paragraph' ? Traversal::Stop : null);

        (new BlockTraverser($visitor))->traverse(self::TWO_TOP_LEVEL);

        $this->assertSame(['enter core/group', 'enter core/paragraph', 'leave core/paragraph'], $visitor->log);
    }

    public function testStopFromLeaveStillAppliesPendingReplacements(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            private int $paragraphs = 0;

            public function leave(BlockNode $block): ?Traversal
            {
                if ($block->getBlockName() !== 'core/paragraph') {
                    return null;
                }

                return ++$this->paragraphs === 1 ? Traversal::Remove : Traversal::Stop;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertCount(1, $root->getInnerBlocks()[0]->getInnerBlocks());
        $this->assertStringContainsString('<p>B</p>', (string) $root);
        $this->assertStringNotContainsString('<p>A</p>', (string) $root);
    }

    public function testStopIsResetBetweenVisitors(): void
    {
        $stopping = $this->logger(enter: static fn (): Traversal => Traversal::Stop);
        $counting = $this->logger();

        (new BlockTraverser($stopping, $counting))->traverse(self::GROUP);

        $this->assertCount(1, $stopping->log);
        $this->assertCount(6, $counting->log);
    }

    public function testEachVisitorGetsItsOwnPassAndSeesPreviousChanges(): void
    {
        $remover = new class extends AbstractBlockVisitor {
            public function leave(BlockNode $block): ?Traversal
            {
                return $block->getBlockName() === 'core/paragraph' ? Traversal::Remove : null;
            }
        };
        $counting = $this->logger();

        (new BlockTraverser($remover, $counting))->traverse(self::GROUP);

        $this->assertSame(['enter core/group', 'leave core/group'], $counting->log);
    }

    public function testPriorityDecidesThePassOrder(): void
    {
        $order = [];
        $first = $this->prioritized(10, static function () use (&$order): null {
            $order[] = 'first';

            return null;
        });
        $last = $this->prioritized(-10, static function () use (&$order): null {
            $order[] = 'last';

            return null;
        });

        (new BlockTraverser($last, $first))->traverse('<!-- wp:paragraph /-->');

        $this->assertSame(['first', 'last'], $order);
    }

    // ------------------------------------------------------------------
    //  Structural changes from leave()
    // ------------------------------------------------------------------

    public function testRemoveDropsTheNodeAndKeepsTheParentWrapper(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function leave(BlockNode $block): ?Traversal
            {
                return \str_contains($block->getInnerHTML(), '<p>A</p>') ? Traversal::Remove : null;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame(<<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>B</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML
, (string) $root);
    }

    public function testRemovingEveryChildKeepsTheParentWrapper(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function leave(BlockNode $block): ?Traversal
            {
                return $block->getBlockName() === 'core/paragraph' ? Traversal::Remove : null;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame(<<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"></div>
<!-- /wp:group -->
HTML
, (string) $root);
    }

    public function testReplacementFromLeaveIsNotVisitedAgain(): void
    {
        $visitor = $this->logger(leave: static fn (BlockNode $block): ?BlockNode => $block->getBlockName() === 'core/paragraph' ? BlockNode::createFromString(self::HEADING) : null);

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);
        $group = $root->getInnerBlocks()[0];

        $this->assertSame(['core/heading', 'core/heading'], \array_map(static fn (BlockNode $block): ?string => $block->getBlockName(), $group->getInnerBlocks()));
        $this->assertSame($group, $group->getInnerBlocks()[0]->getParent());
        $this->assertSame(1, $group->getInnerBlocks()[0]->getDepth());
        $this->assertNotContains('enter core/heading', $visitor->log);
    }

    public function testListFromLeaveExpandsTheNodeIntoSiblings(): void
    {
        $visitor = $this->logger(leave: static function (BlockNode $block): ?array {
            if (!\str_contains($block->getInnerHTML(), '<p>A</p>')) {
                return null;
            }

            return [$block, BlockNode::createFromString(self::HEADING)];
        });

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);
        $group = $root->getInnerBlocks()[0];

        $this->assertSame(<<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>A</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>H</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>B</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML
, (string) $root);
        foreach ($group->getInnerBlocks() as $child) {
            $this->assertSame($group, $child->getParent());
            $this->assertSame(1, $child->getDepth());
        }
    }

    public function testKeyedListFromLeaveIsNormalized(): void
    {
        $visitor = $this->logger(leave: static fn (BlockNode $block): ?array => $block->getBlockName() === 'core/group' ? [4 => BlockNode::createFromString(self::HEADING)] : null);

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame([0], \array_keys($root->getInnerBlocks()));
        $this->assertSame('core/heading', $root->getInnerBlocks()[0]->getBlockName());
    }

    public function testEmptyListFromLeaveRemovesTheNode(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function leave(BlockNode $block): ?array
            {
                return $block->getBlockName() === 'core/paragraph' ? [] : null;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame([], $root->getInnerBlocks()[0]->getInnerBlocks());
    }

    public function testWrapFromLeaveDoesNotLoop(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public int $calls = 0;

            public function leave(BlockNode $block): ?BlockNode
            {
                $this->calls++;

                if ($block->getBlockName() !== 'core/paragraph') {
                    return null;
                }

                return BlockNode::wrap($block, 'core/cover', [], "\n<div class=\"cover\">", "</div>\n");
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);
        $cover = $root->getInnerBlocks()[0]->getInnerBlocks()[0];

        $this->assertSame(3, $visitor->calls);
        $this->assertSame('core/cover', $cover->getBlockName());
        $this->assertSame($root->getInnerBlocks()[0], $cover->getParent());
        $this->assertSame($cover, $cover->getInnerBlocks()[0]->getParent());
        $this->assertSame(2, $cover->getInnerBlocks()[0]->getDepth());
        $this->assertSame(<<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:cover -->
<div class="cover"><!-- wp:paragraph -->
<p>A</p>
<!-- /wp:paragraph --></div>
<!-- /wp:cover -->

<!-- wp:cover -->
<div class="cover"><!-- wp:paragraph -->
<p>B</p>
<!-- /wp:paragraph --></div>
<!-- /wp:cover --></div>
<!-- /wp:group -->
HTML
, (string) $root);
    }

    public function testTopLevelNodesCanBeReplacedToo(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function leave(BlockNode $block): ?Traversal
            {
                return $block->getBlockName() === 'core/group' ? Traversal::Remove : null;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame([], $root->getInnerBlocks());
        $this->assertSame('', (string) $root);
    }

    // ------------------------------------------------------------------
    //  Replacement from enter()
    // ------------------------------------------------------------------

    public function testReplacementFromEnterHasItsChildrenVisitedAndIsLeft(): void
    {
        $visitor = $this->logger(enter: static function (BlockNode $block): ?BlockNode {
            if ($block->getBlockName() !== 'core/group') {
                return null;
            }

            return BlockNode::createFromString('<!-- wp:columns --><div><!-- wp:column --><div></div><!-- /wp:column --></div><!-- /wp:columns -->');
        });

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame([
            'enter core/group',
            'enter core/column',
            'leave core/column',
            'leave core/columns',
        ], $visitor->log);
        $this->assertSame('core/columns', $root->getInnerBlocks()[0]->getBlockName());
        $this->assertSame($root, $root->getInnerBlocks()[0]->getParent());
    }

    // ------------------------------------------------------------------
    //  In-place mutation of the current node
    // ------------------------------------------------------------------

    public function testChildrenAddedInEnterAreVisitedChildrenAddedInLeaveAreNot(): void
    {
        $visitor = $this->logger(
            enter: static function (BlockNode $block): null {
                if ($block->getBlockName() === 'core/group') {
                    $block->appendInnerBlock(BlockNode::createFromString('<!-- wp:list /-->'));
                }

                return null;
            },
            leave: static function (BlockNode $block): null {
                if ($block->getBlockName() === 'core/group') {
                    $block->appendInnerBlock(BlockNode::createFromString('<!-- wp:quote /-->'));
                }

                return null;
            },
        );

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertContains('enter core/list', $visitor->log);
        $this->assertNotContains('enter core/quote', $visitor->log);
        $this->assertStringContainsString("<!-- wp:list /-->\n\n<!-- wp:quote /--></div>", (string) $root);
    }

    public function testAttributeChangesInPlaceAreSerialized(): void
    {
        $visitor = new class extends AbstractBlockVisitor {
            public function enter(BlockNode $block): BlockNode|Traversal|null
            {
                if ($block->getBlockName() === 'core/paragraph') {
                    $block->setAttribute('dropCap', true);
                }

                return null;
            }
        };

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame(2, \substr_count((string) $root, '<!-- wp:paragraph {"dropCap":true} -->'));
    }

    // ------------------------------------------------------------------
    //  Misuse
    // ------------------------------------------------------------------

    public function testRemoveFromEnterIsRejected(): void
    {
        $visitor = $this->logger(enter: static fn (): Traversal => Traversal::Remove);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('enter() cannot return Traversal::Remove');

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testSkipChildrenFromLeaveIsRejected(): void
    {
        $visitor = $this->logger(leave: static fn (): Traversal => Traversal::SkipChildren);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('leave() cannot return Traversal::SkipChildren');

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testMutatingAnAncestorFromEnterIsRejected(): void
    {
        $visitor = $this->logger(enter: static function (BlockNode $block): null {
            if ($block->getBlockName() === 'core/paragraph') {
                $block->getParent()?->removeInnerBlockAt(0);
            }

            return null;
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('no longer child 0 of "core/group"');

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testMutatingAnAncestorFromLeaveIsRejected(): void
    {
        $visitor = $this->logger(leave: static function (BlockNode $block): null {
            if (\str_contains($block->getInnerHTML(), '<p>B</p>')) {
                $block->getParent()?->setInnerBlocks([]);
            }

            return null;
        });

        $this->expectException(LogicException::class);

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testVisitorExceptionsPropagate(): void
    {
        $visitor = $this->logger(enter: static function (): never {
            throw new RuntimeException('attachment missing');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('attachment missing');

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testLeaveReturningAnAncestorIsRejected(): void
    {
        $visitor = $this->logger(leave: static fn (BlockNode $block): ?BlockNode => $block->getBlockName() === 'core/inner' ? $block->getParent()?->getParent() : null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('it is the block itself or one of its ancestors');

        (new BlockTraverser($visitor))->traverse('<!-- wp:outer --><div><!-- wp:mid --><div><!-- wp:inner /--></div><!-- /wp:mid --></div><!-- /wp:outer -->');
    }

    public function testLeaveReturningTheParentIsRejected(): void
    {
        $visitor = $this->logger(leave: static fn (BlockNode $block): ?BlockNode => $block->getBlockName() === 'core/paragraph' ? $block->getParent() : null);

        $this->expectException(LogicException::class);

        (new BlockTraverser($visitor))->traverse(self::GROUP);
    }

    public function testExceptionFromLeaveLeavesStagedReplacementsUnapplied(): void
    {
        $visitor = $this->logger(leave: static function (BlockNode $block): ?Traversal {
            if (\str_contains($block->getInnerHTML(), '<p>A</p>')) {
                return Traversal::Remove;
            }
            if (\str_contains($block->getInnerHTML(), '<p>B</p>')) {
                throw new RuntimeException('boom');
            }

            return null;
        });
        $root = BlockNode::createRoot(self::GROUP);

        try {
            (new BlockTraverser($visitor))->traverse($root);
            $this->fail('The visitor exception should propagate');
        } catch (RuntimeException) {
        }

        // Documents the undefined state: the staged removal of A was never applied.
        $this->assertCount(2, $root->getInnerBlocks()[0]->getInnerBlocks());
    }

    public function testEnterReturningTheSameInstanceIsANoOp(): void
    {
        $visitor = $this->logger(enter: static fn (BlockNode $block): BlockNode => $block);

        $root = (new BlockTraverser($visitor))->traverse(self::GROUP);

        $this->assertSame(self::GROUP, (string) $root);
        $this->assertSame($root->getInnerBlocks()[0], $root->getInnerBlocks()[0]->getInnerBlocks()[0]->getParent());
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function logger(?Closure $enter = null, ?Closure $leave = null): RecordingVisitor
    {
        return new RecordingVisitor($enter, $leave);
    }

    private function prioritized(int $priority, ?Closure $onEnter = null): RecordingVisitor
    {
        return new RecordingVisitor($onEnter, null, $priority);
    }
}

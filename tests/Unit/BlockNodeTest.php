<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Tests\TestCase;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class BlockNodeTest extends TestCase
{
    public function testCanCreateABlockNode(): void
    {
        $node = BlockNode::create([
            'blockName' => 'core/paragraph',
            'innerContent' => ['<p>Hello Block!</p>'],
        ]);

        $this->assertSame('core/paragraph', $node->getBlockName());
        $this->assertSame([], $node->getAttributes());
        $this->assertSame([], $node->getInnerBlocks());
        $this->assertSame('', $node->getInnerHTML());
        $this->assertSame(0, $node->getDepth());
        $this->assertNull($node->getParent());
        $this->assertFalse($node->isRoot());
    }

    public function testCanCreateABlockNodeFromString(): void
    {
        $html = '<p>Hello Block!</p>';
        $node = BlockNode::createFromString('<!-- wp:paragraph -->' . $html . '<!-- /wp:paragraph -->');

        $this->assertSame('core/paragraph', $node->getBlockName());
        $this->assertSame([], $node->getAttributes());
        $this->assertSame([], $node->getInnerBlocks());
        $this->assertSame($html, $node->getInnerHTML());
        $this->assertSame(0, $node->getDepth());
        $this->assertNull($node->getParent());
        $this->assertFalse($node->isRoot());
    }

    public function testCanCreateARootNode(): void
    {
        $node = BlockNode::createRoot('');
        $this->assertTrue($node->isRoot());
    }

    public function testCanCreateANodeWithAttributes(): void
    {
        $node = new BlockNode('core/image', ['id' => 1, 'size' => 'large']);

        $this->assertSame(['id' => 1, 'size' => 'large'], $node->getAttributes());
        $this->assertSame(1, $node->getAttribute('id'));
        $this->assertSame('large', $node->getAttribute('size'));
        $this->assertNull($node->getAttribute('non-existent'));
    }

    public function testCanCreateANodeWithInnerHtml(): void
    {
        $node = new BlockNode('core/paragraph', [], [], 'Hello World');

        $this->assertSame('Hello World', $node->getInnerHTML());
    }

    public function testCanCreateANodeWithInnerBlocks(): void
    {
        $innerBlock = [
            'blockName' => 'core/heading',
            'attrs' => ['level' => 2],
            'innerBlocks' => [],
            'innerHTML' => 'Sub-heading',
            'innerContent' => [],
        ];
        $node = new BlockNode('core/columns', [], [$innerBlock]);

        $this->assertCount(1, $node->getInnerBlocks());
        $innerBlockNode = $node->getInnerBlocks()[0];
        $this->assertInstanceOf(BlockNode::class, $innerBlockNode);
        $this->assertSame('core/heading', $innerBlockNode->getBlockName());
        $this->assertSame(1, $innerBlockNode->getDepth());
        $this->assertSame($node, $innerBlockNode->getParent());
    }

    public function testSetInnerBlocks(): void
    {
        $node = new BlockNode('core/group');
        $child1 = new BlockNode('core/paragraph');
        $child2 = new BlockNode('core/image');

        $node->setInnerBlocks([$child1, $child2]);

        $this->assertCount(2, $node->getInnerBlocks());
        $this->assertSame($node, $child1->getParent());
        $this->assertSame($node, $child2->getParent());
        $this->assertSame(1, $child1->getDepth());
        $this->assertSame(1, $child2->getDepth());
    }

    public function testToArray(): void
    {
        $innerBlock = [
            'blockName' => 'core/heading',
            'attrs' => ['level' => 2],
            'innerBlocks' => [],
            'innerHTML' => 'Sub-heading',
            'innerContent' => ['<h2 class="wp-block-heading">Sub-heading</h2>', null],
        ];
        $node = new BlockNode(
            'core/columns',
            ['backgroundColor' => 'red'],
            [$innerBlock],
            '',
            ['<div class="wp-block-columns has-background" style="background-color:red">', null, '</div>']
        );

        $expected = [
            'blockName' => 'core/columns',
            'attrs' => ['backgroundColor' => 'red'],
            'innerBlocks' => [
                [
                    'blockName' => 'core/heading',
                    'attrs' => ['level' => 2],
                    'innerBlocks' => [],
                    'innerHTML' => 'Sub-heading',
                    // The surplus placeholder of a childless block is dropped.
                    'innerContent' => ['<h2 class="wp-block-heading">Sub-heading</h2>'],
                ],
            ],
            'innerHTML' => '',
            'innerContent' => ['<div class="wp-block-columns has-background" style="background-color:red">', null, '</div>'],
        ];

        $this->assertSame($expected, $node->toArray());
    }

    public function testRenameBlock(): void
    {
        $node = (new BlockNode('core/paragraph'))->setBlockName('core/text');

        $this->assertSame('core/text', $node->getBlockName());
    }

    public function testAttributeManipulation(): void
    {
        $node = new BlockNode('core/image', ['id' => 5]);

        $this->assertTrue($node->hasAttribute('id'));
        $this->assertFalse($node->hasAttribute('url'));

        $node->renameAttribute('unknown', 'new-name');
        $this->assertFalse($node->hasAttribute('unknown'));

        $node->setAttribute('url', 'http://example.com/img.png');
        $this->assertTrue($node->hasAttribute('url'));
        $this->assertSame('http://example.com/img.png', $node->getAttribute('url'));

        $node->removeAttribute('id');
        $this->assertFalse($node->hasAttribute('id'));

        $node->setAttribute('data-id', 123)->renameAttribute('data-id', 'data-new-id');
        $this->assertFalse($node->hasAttribute('data-id'));
        $this->assertTrue($node->hasAttribute('data-new-id'));
        $this->assertSame(123, $node->getAttribute('data-new-id'));

        $node->removeAttributes(['url', 'data-new-id']);
        $this->assertFalse($node->hasAttribute('url'));
        $this->assertFalse($node->hasAttribute('data-new-id'));

        $node->setAttributes(['id' => 10, 'className' => 'test-class']);
        $this->assertTrue($node->hasAttribute('id'));
        $this->assertTrue($node->hasAttribute('className'));
        $this->assertSame(10, $node->getAttribute('id'));
        $this->assertSame('test-class', $node->getAttribute('className'));
    }

    public function testClearContent(): void
    {
        $node = new BlockNode('core/paragraph', [], [], 'Some content', ['<p>', 'Some content', '</p>']);
        $node->clearContent();

        $this->assertSame('', $node->getInnerHTML());
    }

    public function testStaticCreate(): void
    {
        $blockData = [
            'blockName' => 'core/button',
            'attrs' => ['text' => 'Click me'],
            'innerBlocks' => [],
            'innerContent' => [],
        ];

        $node = BlockNode::create($blockData);

        $this->assertInstanceOf(BlockNode::class, $node);
        $this->assertSame('core/button', $node->getBlockName());
        $this->assertSame('Click me', $node->getAttribute('text'));
    }

    public function testStaticCreateWithParent(): void
    {
        $parent = new BlockNode('core/buttons');
        $blockData = [
            'blockName' => 'core/button',
        ];

        $node = BlockNode::create($blockData, $parent);

        $this->assertSame($parent, $node->getParent());
        $this->assertSame(1, $node->getDepth());
    }

    public function testToStringForSingleBlock(): void
    {
        if (!\function_exists('serialize_block')) {
            $this->markTestSkipped('serialize_block function is not available.');
        }

        $node = new BlockNode(blockName: 'core/paragraph', innerContent: ['Hello']);

        $this->assertStringContainsString('wp:paragraph', (string) $node);
        $this->assertStringContainsString('Hello', (string) $node);
    }

    public function testToStringForRootBlock(): void
    {
        if (!\function_exists('serialize_blocks')) {
            $this->markTestSkipped('serialize_blocks function is not available.');
        }

        $innerBlock = [
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => ['Hello'],
        ];
        $node = new BlockNode('@root', [], [$innerBlock]);

        $this->assertStringContainsString('wp:paragraph', (string) $node);
        $this->assertStringContainsString('Hello', (string) $node);
    }

    public function testAppendInnerBlockSingle(): void
    {
        $block = BlockNode::create([
            'blockName' => 'core/columns',
        ]);

        $block->appendInnerBlock(BlockNode::create([
            'blockName' => 'mind/column',
        ]));

        $this->assertSame([
            "blockName" => "core/columns",
            "attrs" => [],
            "innerBlocks" => [
                [
                    "blockName" => "mind/column",
                    "attrs" => [],
                    "innerBlocks" => [],
                    "innerHTML" => "",
                    "innerContent" => [],
                ],
            ],
            "innerHTML" => "",
            "innerContent" => [null],
        ], $block->toArray());
    }

    public function testAppendInnerBlock(): void
    {
        $columnsNode = $this->getNestedBlocks();

        $columnsNode->appendInnerBlock(BlockNode::createFromString('<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 4</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->'));

        $expected = '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 1</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 2</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 3</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 4</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';

        $this->assertSame($expected, (string) $columnsNode);
    }

    public function testPrependInnerBlock(): void
    {
        $columnsNode = $this->getNestedBlocks();

        $columnsNode->prependInnerBlock(BlockNode::createFromString('<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 0</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->'));
        $columnsNode->prependInnerBlock(BlockNode::createFromString('<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column -1</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->'));

        $expected = '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column -1</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 0</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 1</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 2</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Column 3</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';

        $this->assertSame($expected, (string) $columnsNode);
    }

    public function testPrependInnerBlockSingle(): void
    {
        $block = BlockNode::create([
            'blockName' => 'core/columns',
        ]);

        $block->prependInnerBlock(BlockNode::create([
            'blockName' => 'mind/column',
        ]));

        $this->assertSame([
            "blockName" => "core/columns",
            "attrs" => [],
            "innerBlocks" => [
                [
                    "blockName" => "mind/column",
                    "attrs" => [],
                    "innerBlocks" => [],
                    "innerHTML" => "",
                    "innerContent" => [],
                ],
            ],
            "innerHTML" => "",
            "innerContent" => [null],
        ], $block->toArray());
    }

    public function testWrapInnerBlocks(): void
    {
        $node = BlockNode::create([
            'blockName' => 'core/group',
        ]);
        $node->wrapInnerContent('<div class="wp-block-group">');

        $this->assertStringContainsString('</div>', (string) $node);

        // Wrap nested
        $node = BlockNode::create([
            'blockName' => 'core/group',
        ]);
        $node->wrapInnerContent('<div class="wp-block-group"><h2>');

        $this->assertStringContainsString('</h2></div>', (string) $node);
    }

    private function getNestedBlocks(): BlockNode
    {
        $columns = [];
        for ($index = 1; $index <= 3; $index++) {
            $columns[] = BlockNode::create([
                'blockName' => 'core/column',
                'innerBlocks' => [
                    BlockNode::create([
                        'blockName' => 'core/paragraph',
                        'innerContent' => ["\n<p>Column {$index}</p>\n"],
                    ]),
                ],
                'innerContent' => ["\n<div class=\"wp-block-column\">", null, "</div>\n"],
            ]);
        }

        return BlockNode::create([
            'blockName' => 'core/columns',
            'innerContent' => ["\n<div class=\"wp-block-columns\">", null, "\n\n", null, "\n\n", null, "</div>\n"],
            'innerBlocks' => $columns,
        ]);
    }

    // ------------------------------------------------------------------
    //  Child primitives keep innerContent in sync
    // ------------------------------------------------------------------

    public function testIndexOfUsesIdentity(): void
    {
        $group = $this->group();
        [$a, $b] = $group->getInnerBlocks();

        $this->assertSame(0, $group->indexOf($a));
        $this->assertSame(1, $group->indexOf($b));
        $this->assertNull($group->indexOf(new BlockNode('core/paragraph')));
    }

    public function testReplaceInnerBlockAtWithOneNodeSwapsInPlace(): void
    {
        $group = $this->group();
        $old = $group->getInnerBlocks()[0];
        $new = BlockNode::createFromString("<!-- wp:heading -->\n<h2>H</h2>\n<!-- /wp:heading -->");

        $group->replaceInnerBlockAt(0, $new);

        $this->assertSame([$new, $group->getInnerBlocks()[1]], $group->getInnerBlocks());
        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "\n\n", null, "</div>\n"], $group->getInnerContent());
        $this->assertSame($group, $new->getParent());
        $this->assertNull($old->getParent());
    }

    public function testReplaceInnerBlockAtWithItselfIsANoOp(): void
    {
        $group = $this->group();
        $before = $group->getInnerContent();
        $child = $group->getInnerBlocks()[0];

        $group->replaceInnerBlockAt(0, $child);

        $this->assertSame($before, $group->getInnerContent());
        $this->assertSame($group, $child->getParent());
    }

    public function testReplaceInnerBlockAtWithNothingRemovesTheChildAndItsSeparator(): void
    {
        $group = $this->group();
        $removed = $group->getInnerBlocks()[0];

        $group->replaceInnerBlockAt(0);

        $this->assertCount(1, $group->getInnerBlocks());
        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "</div>\n"], $group->getInnerContent());
        $this->assertNull($removed->getParent());

        $group->removeInnerBlockAt(0);

        $this->assertSame([], $group->getInnerBlocks());
        $this->assertSame(["\n<div class=\"wp-block-group\">", "</div>\n"], $group->getInnerContent());
    }

    public function testReplaceInnerBlockAtWithSeveralNodesExpandsInPlace(): void
    {
        $group = $this->group();
        $x = new BlockNode('core/separator');
        $y = new BlockNode('core/spacer');

        $group->replaceInnerBlockAt(1, $x, $y);

        $this->assertSame(['core/paragraph', 'core/separator', 'core/spacer'], $this->names($group));
        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "</div>\n"], $group->getInnerContent());
        $this->assertSame($group, $x->getParent());
        $this->assertSame($group, $y->getParent());
    }

    public function testReplaceInnerBlockAtRejectsUnknownIndex(): void
    {
        $this->expectException(OutOfRangeException::class);

        $this->group()->replaceInnerBlockAt(2, new BlockNode('core/paragraph'));
    }

    public function testInsertInnerBlockAtStartMiddleAndEnd(): void
    {
        $group = $this->group();

        $group->insertInnerBlockAt(0, new BlockNode('core/first'));
        $group->insertInnerBlockAt(2, new BlockNode('core/middle'));
        $group->insertInnerBlockAt(4, new BlockNode('core/last'));

        $this->assertSame(['core/first', 'core/paragraph', 'core/middle', 'core/paragraph', 'core/last'], $this->names($group));
        $this->assertSame(
            ["\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "\n\n", null, "\n\n", null, "</div>\n"],
            $group->getInnerContent()
        );
    }

    public function testInsertIntoAnEmptiedContainerGoesBetweenTheWrapperChunks(): void
    {
        $group = $this->group()->setInnerBlocks([]);

        $group->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "</div>\n"], $group->getInnerContent());
    }

    public function testInsertThenRemoveRestoresTheOriginalContent(): void
    {
        $group = $this->group();
        $before = $group->getInnerContent();

        $group->insertInnerBlockAt(1, new BlockNode('core/spacer'));
        $group->removeInnerBlockAt(1);

        $this->assertSame($before, $group->getInnerContent());
    }

    public function testSetInnerBlocksKeepsTheWrapperOnGrowth(): void
    {
        $group = $this->group();
        $children = [...$group->getInnerBlocks(), new BlockNode('core/spacer')];

        $group->setInnerBlocks($children);

        $this->assertSame($children, $group->getInnerBlocks());
        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "</div>\n"], $group->getInnerContent());
    }

    public function testSetInnerBlocksDetachesDroppedChildrenAndAdoptsNewOnes(): void
    {
        $group = $this->group();
        [$kept, $dropped] = $group->getInnerBlocks();
        $added = new BlockNode('core/spacer');

        $group->setInnerBlocks([$added, $kept]);

        $this->assertSame($group, $kept->getParent());
        $this->assertSame($group, $added->getParent());
        $this->assertNull($dropped->getParent());
    }

    public function testSetInnerBlocksAcceptsParsedArrays(): void
    {
        $group = $this->group();

        $group->setInnerBlocks([['blockName' => 'core/spacer']]);

        $this->assertSame(['core/spacer'], $this->names($group));
        $this->assertSame($group, $group->getInnerBlocks()[0]->getParent());
    }

    public function testEmptyingThenRestoringChildrenIsAnIdentity(): void
    {
        $group = $this->group();
        $before = $group->getInnerContent();
        $children = $group->getInnerBlocks();

        $group->setInnerBlocks([])->setInnerBlocks($children);

        $this->assertSame($before, $group->getInnerContent());
        $this->assertSame($children, $group->getInnerBlocks());
    }

    public function testClearContentKeepsThePlaceholders(): void
    {
        $group = $this->group();

        $group->clearContent();

        $this->assertSame([null, "\n\n", null], $group->getInnerContent());
        $this->assertSame('', $group->getInnerHTML());
    }

    public function testSetInnerContentWithAStringAppendsPlaceholders(): void
    {
        $group = $this->group();

        $group->setInnerContent('<div>');

        $this->assertSame(['<div>', null, "\n\n", null], $group->getInnerContent());
    }

    public function testSetInnerContentWithAListReconcilesPlaceholders(): void
    {
        $group = $this->group();

        $group->setInnerContent(['<ul>', null, '</ul>']);

        $this->assertSame(['<ul>', null, "\n\n", null, '</ul>'], $group->getInnerContent());

        $group->setInnerContent(['<ul>', null, null, null, '</ul>']);

        $this->assertSame(['<ul>', null, null, '</ul>'], $group->getInnerContent());
    }

    public function testConstructorAddsMissingPlaceholdersBetweenWrapperChunks(): void
    {
        $node = new BlockNode('core/group', [], [['blockName' => 'core/paragraph']], '', ['<div>', '</div>']);

        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testWrapCreatesAParentAroundTheChildWithoutDetachingIt(): void
    {
        $group = $this->group();
        $child = $group->getInnerBlocks()[0];

        $wrapper = BlockNode::wrap($child, 'core/cover', ['dimRatio' => 50], '<div class="cover">', '</div>');

        $this->assertSame('core/cover', $wrapper->getBlockName());
        $this->assertSame(['dimRatio' => 50], $wrapper->getAttributes());
        $this->assertSame(['<div class="cover">', null, '</div>'], $wrapper->getInnerContent());
        $this->assertSame([$child], $wrapper->getInnerBlocks());
        $this->assertSame($wrapper, $child->getParent());
        $this->assertSame(0, $group->indexOf($child), 'the child is still listed by its former parent until it is replaced');

        $group->replaceInnerBlockAt(0, $wrapper);

        $this->assertSame($group, $wrapper->getParent());
        $this->assertSame($wrapper, $child->getParent(), 'replacing the child by its wrapper must not detach it from the wrapper');
        $this->assertSame(2, $child->getDepth());
    }

    public function testWrapWithoutMarkupOnlyHoldsThePlaceholder(): void
    {
        $wrapper = BlockNode::wrap(new BlockNode('core/paragraph'), 'core/group');

        $this->assertSame([null], $wrapper->getInnerContent());
    }

    public function testDepthFollowsReparenting(): void
    {
        $root = BlockNode::createRoot('<!-- wp:group --><div><!-- wp:paragraph /--></div><!-- /wp:group -->');
        $group = $root->getInnerBlocks()[0];
        $paragraph = $group->getInnerBlocks()[0];

        $this->assertSame(0, $group->getDepth());
        $this->assertSame(1, $paragraph->getDepth());

        $root->replaceInnerBlockAt(0, $paragraph);

        $this->assertSame(0, $paragraph->getDepth());
        $this->assertNull($group->getParent());
    }

    public function testToArrayHasNoSideEffect(): void
    {
        $node = new BlockNode('core/paragraph', [], [], '', ['Hello']);

        $node->toArray();

        $this->assertSame(['Hello'], $node->getInnerContent());
    }

    public function testClassNamesAreDeduplicatedAndRemovedCleanly(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'a  b']);

        $this->assertSame('a b c', $node->addClassName('c')->addClassName('a')->getAttribute('className'));
        $this->assertSame('b', $node->removeClassName('a')->removeClassName('c')->getAttribute('className'));
        $this->assertFalse($node->removeClassName('b')->hasAttribute('className'));
    }

    public function testInsertedNodesAreAdopted(): void
    {
        $group = $this->group();
        $first = new BlockNode('core/first');
        $last = new BlockNode('core/last');

        $group->insertInnerBlockAt(0, $first)->appendInnerBlock($last);

        $this->assertSame($group, $first->getParent());
        $this->assertSame($group, $last->getParent());
    }

    public function testInsertRejectsIndexesOutOfBounds(): void
    {
        $group = $this->group();

        try {
            $group->insertInnerBlockAt(-1, new BlockNode('core/spacer'));
            $this->fail('Negative index accepted');
        } catch (OutOfRangeException) {
        }

        $this->expectException(OutOfRangeException::class);
        $group->insertInnerBlockAt(3, new BlockNode('core/spacer'));
    }

    public function testAppendAfterChildrenSeparatedByMarkupKeepsThatMarkup(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], '', ['<div>', null, '<hr>', null, '</div>']);

        $node->appendInnerBlock(new BlockNode('core/c'));

        $this->assertSame(['<div>', null, '<hr>', null, "\n\n", null, '</div>'], $node->getInnerContent());
        $this->assertSame(['core/a', 'core/b', 'core/c'], $this->names($node));
    }

    public function testRemovingAChildKeepsNonWhitespaceMarkupAroundIt(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], '', ['<div>', null, '<hr>', null, '</div>']);

        $node->removeInnerBlockAt(0);

        $this->assertSame(['<div>', '<hr>', null, '</div>'], $node->getInnerContent());
    }

    public function testRemovingTheLastChildDropsTheSeparatorBeforeIt(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], '', ['<div>', null, "\n\n", null, '</div>']);

        $node->removeInnerBlockAt(1);

        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testRemovingAChildWithoutWrapperMarkup(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')]);

        $this->assertSame([null, "\n\n", null], $node->getInnerContent());

        $node->removeInnerBlockAt(1);

        $this->assertSame([null], $node->getInnerContent());

        $node->removeInnerBlockAt(0);

        $this->assertSame([], $node->getInnerContent());
    }

    public function testConstructorLeavesConsistentContentUntouched(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')], '', ['<div>', null, '</div>']);

        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testListsAreNormalizedFromKeyedArrays(): void
    {
        $a = new BlockNode('core/a');
        $b = new BlockNode('core/b');
        $node = new BlockNode('core/group', [], [3 => $a], '', [7 => '<div>', 9 => null, 11 => '</div>']);

        $this->assertSame([$a], $node->getInnerBlocks());
        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());

        $node->setInnerBlocks([5 => $b, 2 => $a]);

        $this->assertSame([$b, $a], $node->getInnerBlocks());

        $node->setInnerContent([4 => '<section>', 8 => null, 9 => null, 10 => '</section>']);

        $this->assertSame(['<section>', null, null, '</section>'], $node->getInnerContent());
    }

    public function testWrapInnerContentPrependsTheMarkupAndAppendsClosingTags(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')]);

        $node->wrapInnerContent('<div class="wp-block-group"><section>');

        $this->assertSame(['<div class="wp-block-group"><section>', null, '</section>', '</div>'], $node->getInnerContent());
    }

    public function testRenameAttributeIsFluentWhenTheAttributeIsMissing(): void
    {
        $node = new BlockNode('core/paragraph');

        $this->assertSame($node, $node->renameAttribute('missing', 'other'));
        $this->assertSame([], $node->getAttributes());
    }

    public function testInsertingNothingIsANoOp(): void
    {
        $group = $this->group();
        $before = $group->getInnerContent();

        $group->insertInnerBlockAt(1);

        $this->assertSame($before, $group->getInnerContent());
    }

    public function testAppendInnerBlocksAcceptsNodesAndParsedArrays(): void
    {
        $group = $this->group();
        $node = new BlockNode('core/spacer');

        $group->appendInnerBlocks([$node, ['blockName' => 'core/separator']]);

        $this->assertSame(['core/paragraph', 'core/paragraph', 'core/spacer', 'core/separator'], $this->names($group));
        $this->assertSame($group, $node->getParent());
        $this->assertSame($group, $group->getInnerBlocks()[3]->getParent());
    }

    public function testWrapInnerContentWithoutTagIsANoOp(): void
    {
        $node = new BlockNode('core/paragraph', [], [], '', ['<p>Hi</p>']);

        $node->wrapInnerContent('plain text');

        $this->assertSame(['<p>Hi</p>'], $node->getInnerContent());
    }

    public function testClearAttributes(): void
    {
        $node = new BlockNode('core/paragraph', ['id' => 1, 'className' => 'x']);

        $this->assertSame($node, $node->clearAttributes());
        $this->assertSame([], $node->getAttributes());
    }

    // ------------------------------------------------------------------
    //  Review fixes
    // ------------------------------------------------------------------

    public function testAddingANodeToItselfIsRejected(): void
    {
        $node = new BlockNode('core/group');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('that would close a cycle');

        $node->setInnerBlocks([$node]);
    }

    public function testAddingAnAncestorAsChildIsRejected(): void
    {
        $root = BlockNode::createRoot('<!-- wp:outer --><div><!-- wp:mid --><div><!-- wp:inner /--></div><!-- /wp:mid --></div><!-- /wp:outer -->');
        $outer = $root->getInnerBlocks()[0];
        $inner = $outer->getInnerBlocks()[0]->getInnerBlocks()[0];

        $this->expectException(LogicException::class);

        $inner->appendInnerBlock($outer);
    }

    public function testWrapInnerContentSkipsVoidElements(): void
    {
        $node = new BlockNode('core/image', [], [], '', ['<img src="a.jpg">']);

        $node->wrapInnerContent('<figure><img class="cover" src="b.jpg"><br>');

        $this->assertSame(['<figure><img class="cover" src="b.jpg"><br>', '<img src="a.jpg">', '</figure>'], $node->getInnerContent());
    }

    public function testRemovingOneSlotOfAnAliasedChildKeepsItsParent(): void
    {
        $twice = new BlockNode('core/paragraph');
        $group = new BlockNode('core/group', [], [$twice, $twice], '', ['<div>', null, null, '</div>']);

        $group->removeInnerBlockAt(0);

        $this->assertSame([$twice], $group->getInnerBlocks());
        $this->assertSame($group, $twice->getParent());

        $group->removeInnerBlockAt(0);

        $this->assertNull($twice->getParent());
    }

    public function testSetInnerBlocksWithTheSameCountKeepsTheContentBetweenChildren(): void
    {
        $a = new BlockNode('core/a');
        $b = new BlockNode('core/b');
        $c = new BlockNode('core/c');
        $node = new BlockNode('core/group', [], [$a, $b], '', ['<div>', null, '<hr>', null, '</div>']);

        $node->setInnerBlocks([$a, $b]);
        $this->assertSame(['<div>', null, '<hr>', null, '</div>'], $node->getInnerContent());

        $node->setInnerBlocks([$c, $a]);
        $this->assertSame(['<div>', null, '<hr>', null, '</div>'], $node->getInnerContent());
        $this->assertSame([$c, $a], $node->getInnerBlocks());
        $this->assertSame($node, $c->getParent());
        $this->assertNull($b->getParent());

        $node->setInnerBlocks([$a]);
        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testCreateFromStringSkipsLeadingFreeformContent(): void
    {
        $node = BlockNode::createFromString("Intro text\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->");

        $this->assertSame('core/paragraph', $node->getBlockName());
    }

    public function testCreateFromStringReturnsTheFirstOfSeveralBlocks(): void
    {
        $node = BlockNode::createFromString('<!-- wp:a /--><!-- wp:b /-->');

        $this->assertSame('core/a', $node->getBlockName());
    }

    public function testCreateFromStringWithoutBlockCommentYieldsAFreeformNode(): void
    {
        $node = BlockNode::createFromString('<p>plain</p>');

        $this->assertNull($node->getBlockName());
        $this->assertSame('<p>plain</p>', $node->getInnerHTML());
    }

    public function testCreateFromStringRejectsEmptyMarkup(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlockNode::createFromString('');
    }

    public function testPrependInnerBlocksAcceptsNodesAndParsedArrays(): void
    {
        $group = $this->group();
        $node = new BlockNode('core/spacer');

        $group->prependInnerBlocks([$node, ['blockName' => 'core/separator']]);

        $this->assertSame(['core/spacer', 'core/separator', 'core/paragraph', 'core/paragraph'], $this->names($group));
        $this->assertSame($group, $node->getParent());
        $this->assertSame($group, $group->getInnerBlocks()[1]->getParent());
        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "\n\n", null, "\n\n", null, "\n\n", null, "</div>\n"], $group->getInnerContent());
    }

    public function testClassNamesCanBeQueried(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'a  b']);

        $this->assertSame(['a', 'b'], $node->getClassNames());
        $this->assertTrue($node->hasClassName('a'));
        $this->assertFalse($node->hasClassName('c'));
        $this->assertSame([], (new BlockNode('core/paragraph'))->getClassNames());
        $this->assertFalse((new BlockNode('core/paragraph'))->hasClassName('a'));
    }

    public function testOutOfRangeMessagesGiveTheValidRange(): void
    {
        $group = $this->group();

        try {
            $group->replaceInnerBlockAt(5, new BlockNode('core/spacer'));
            $this->fail('Expected OutOfRangeException');
        } catch (OutOfRangeException $exception) {
            $this->assertSame('No inner block at index 5, valid indexes are 0 to 1.', $exception->getMessage());
        }

        try {
            $group->insertInnerBlockAt(5, new BlockNode('core/spacer'));
            $this->fail('Expected OutOfRangeException');
        } catch (OutOfRangeException $exception) {
            $this->assertSame('Cannot insert at index 5, valid indexes are 0 to 2.', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    //  Guards against content corruption
    // ------------------------------------------------------------------

    public function testWrapInnerContentKeepsMarkupMadeOnlyOfVoidElements(): void
    {
        $node = new BlockNode('core/separator', [], [], '', ['<p>x</p>']);

        $node->wrapInnerContent('<br>');

        $this->assertSame(['<br>', '<p>x</p>'], $node->getInnerContent());
    }

    public function testWrapInnerContentWithoutAnyTagIsStillANoOp(): void
    {
        $node = new BlockNode('core/group', [], [], '', ['<p>x</p>']);

        $node->wrapInnerContent('no tags here');

        $this->assertSame(['<p>x</p>'], $node->getInnerContent());
    }

    public function testRenamingARootMakesItAnOrdinaryBlock(): void
    {
        $root = BlockNode::createRoot('<!-- wp:paragraph /-->');

        $root->setBlockName('core/group');

        $this->assertFalse($root->isRoot());
        $this->assertSame('core/group', $root->toArray()['blockName']);
        $this->assertSame('<!-- wp:group --><!-- wp:paragraph /--><!-- /wp:group -->', (string) $root);
    }

    #[DataProvider('blankMarkupProvider')]
    public function testCreateFromStringRejectsBlankMarkup(string $markup): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlockNode::createFromString($markup);
    }

    /**
     * @return \Iterator<int<0, max>, array{string}>
     */
    public static function blankMarkupProvider(): \Iterator
    {
        yield [''];
        yield ['   '];
        yield ["\n\n"];
        yield ["\t"];
    }

    public function testAttributesMustBeAMap(): void
    {
        $node = new BlockNode('core/paragraph');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('must be a map');

        $node->setAttributes(['a', 'b']);
    }

    public function testANumericAttributeNameThatWouldMakeAListIsRejected(): void
    {
        $node = new BlockNode('core/paragraph');

        $this->expectException(InvalidArgumentException::class);

        $node->setAttribute('0', 'a');
    }

    public function testAttributesStayAMapWhenAnotherKeyIsPresent(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'x']);

        $node->setAttribute('0', 'a');

        $this->assertSame(['className' => 'x', 0 => 'a'], $node->getAttributes());
    }

    #[DataProvider('invalidBlockNameProvider')]
    public function testABlockNameThatWouldBreakTheDelimiterIsRejected(string $blockName): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BlockNode('core/paragraph'))->setBlockName($blockName);
    }

    /**
     * @return \Iterator<int<0, max>, array{string}>
     */
    public static function invalidBlockNameProvider(): \Iterator
    {
        yield ['paragraph --><script>alert(1)</script><!-- wp:x'];
        yield ['core/Paragraph'];
        yield ['core paragraph'];
        yield ['<script>'];
        yield [''];
        yield ['core/paragraph/extra'];
    }

    #[DataProvider('validBlockNameProvider')]
    public function testOrdinaryBlockNamesAreAccepted(string $blockName): void
    {
        $this->assertSame($blockName, (new BlockNode('core/paragraph'))->setBlockName($blockName)->getBlockName());
    }

    /**
     * @return \Iterator<int<0, max>, array{string}>
     */
    public static function validBlockNameProvider(): \Iterator
    {
        yield ['paragraph'];
        yield ['core/paragraph'];
        yield ['vendor/my-block'];
        yield ['para--graph'];
        yield ['a/b'];
    }

    public function testAFreeformNodeKeepsItsNullName(): void
    {
        $this->assertNull((new BlockNode())->getBlockName());
        $this->assertTrue(BlockNode::createRoot('')->isRoot());
    }

    public function testAttributesThatCannotBeEncodedAreReportedInsteadOfDropped(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'keep-me'], [], '', ['<p>hi</p>']);
        $node->setAttribute('bad', \INF);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('JSON cannot encode');

        $node->__toString();
    }

    // ------------------------------------------------------------------
    //  One parent per block, no cycles
    // ------------------------------------------------------------------

    public function testAttachingABlockTakesItAwayFromItsFormerParent(): void
    {
        $a = BlockNode::createFromString('<!-- wp:group --><div><!-- wp:paragraph /--></div><!-- /wp:group -->');
        $b = BlockNode::createFromString('<!-- wp:columns --><div></div><!-- /wp:columns -->');
        $moved = $a->getInnerBlocks()[0];

        $b->appendInnerBlock($moved);

        $this->assertNull($a->indexOf($moved), 'the former parent must not keep listing it');
        $this->assertSame([], $a->getInnerBlocks());
        $this->assertSame($b, $moved->getParent());
        $this->assertSame([$moved], $b->getInnerBlocks());
    }

    public function testABlockCannotBeAddedUnderOneOfItsOwnDescendants(): void
    {
        $outer = BlockNode::createFromString('<!-- wp:outer --><div><!-- wp:mid --><div><!-- wp:inner /--></div><!-- /wp:mid --></div><!-- /wp:outer -->');
        $inner = $outer->getInnerBlocks()[0]->getInnerBlocks()[0];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('that would close a cycle');

        $inner->appendInnerBlock($outer);
    }

    public function testARefusedChildLeavesTheTreeUntouched(): void
    {
        $outer = BlockNode::createFromString('<!-- wp:outer --><div><!-- wp:inner /--></div><!-- /wp:outer -->');
        $inner = $outer->getInnerBlocks()[0];
        $content = $inner->getInnerContent();

        try {
            $inner->appendInnerBlock($outer);
            $this->fail('Expected a LogicException');
        } catch (LogicException) {
        }

        $this->assertSame([], $inner->getInnerBlocks());
        $this->assertSame($content, $inner->getInnerContent());
        $this->assertSame([$inner], $outer->getInnerBlocks());
    }

    public function testPlaceholdersAreFoundFromEitherEndOfTheContent(): void
    {
        $children = \array_map(static fn (int $i): BlockNode => new BlockNode("core/n{$i}"), \range(0, 9));
        $node = new BlockNode('core/group', [], $children, '', ['<div>', '</div>']);

        // Exercises both scan directions: low indexes scan forward, high indexes scan backward.
        foreach ([0, 1, 4, 5, 8, 9] as $index) {
            $node->replaceInnerBlockAt($index, new BlockNode("core/r{$index}"));
        }

        $this->assertSame(
            ['core/r0', 'core/r1', 'core/n2', 'core/n3', 'core/r4', 'core/r5', 'core/n6', 'core/n7', 'core/r8', 'core/r9'],
            $this->names($node)
        );
        $this->assertCount(10, \array_filter($node->getInnerContent(), static fn (?string $chunk): bool => $chunk === null));
        $this->assertSame('<div>', $node->getInnerContent()[0]);
        $content = $node->getInnerContent();
        $this->assertSame('</div>', \end($content));
    }

    public function testInsertingIntoAContainerTheParserLeftEmptyStaysInsideTheWrapper(): void
    {
        $group = BlockNode::createFromString("<!-- wp:group -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->");

        $this->assertSame(["\n<div class=\"wp-block-group\"></div>\n"], $group->getInnerContent(), 'the parser keeps both tags in one chunk');

        $group->appendInnerBlock(BlockNode::createFromString('<!-- wp:paragraph --><p>in</p><!-- /wp:paragraph -->'));

        $this->assertSame(["\n<div class=\"wp-block-group\">", null, "</div>\n"], $group->getInnerContent());
        $this->assertStringContainsString('<div class="wp-block-group"><!-- wp:paragraph --><p>in</p><!-- /wp:paragraph --></div>', (string) $group);
    }

    public function testInsertingIntoAContainerWithoutAnyClosingTagAppendsAtTheEnd(): void
    {
        $node = new BlockNode('core/group', [], [], '', ['<div>']);

        $node->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame(['<div>', null], $node->getInnerContent());
    }

    public function testInsertingIntoAContainerWithNoContentAtAll(): void
    {
        $node = new BlockNode('core/group');

        $node->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame([null], $node->getInnerContent());
    }

    public function testAChunkThatIsOnlyAClosingTagIsNotSplit(): void
    {
        $node = new BlockNode('core/group', [], [], '', ['</div>']);

        $node->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame(['</div>', null], $node->getInnerContent());
    }

    // ------------------------------------------------------------------
    //  Fixtures
    // ------------------------------------------------------------------

    /**
     * A parsed group with two paragraphs, in the exact shape WordPress produces.
     */
    private function group(): BlockNode
    {
        return BlockNode::createFromString(<<<'HTML'
<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph -->
<p>A</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>B</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
HTML);
    }

    /**
     * @return list<string|null>
     */
    private function names(BlockNode $node): array
    {
        return \array_map(static fn (BlockNode $block): ?string => $block->getBlockName(), $node->getInnerBlocks());
    }
}

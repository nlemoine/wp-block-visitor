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
        $this->assertSame('<p>Hello Block!</p>', $node->getInnerHTML());
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

    public function testCanCreateANodeFromInnerHtmlAlone(): void
    {
        $node = BlockNode::create(['blockName' => 'core/paragraph', 'innerHTML' => '<p>Hello</p>']);

        $this->assertSame(['<p>Hello</p>'], $node->getInnerContent());
        $this->assertSame('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', (string) $node);
    }

    public function testInnerHtmlFollowsTheContent(): void
    {
        $group = $this->group();

        $group->removeInnerBlockAt(0);
        $this->assertSame("\n<div class=\"wp-block-group\"></div>\n", $group->getInnerHTML());

        $group->setInnerContent(['<section>', null, '</section>']);
        $this->assertSame('<section></section>', $group->getInnerHTML());
        $this->assertSame('<section></section>', $group->toArray()['innerHTML']);
    }

    public function testInnerHtmlMatchesTheParserOnEveryBlockOfTheFixture(): void
    {
        $check = function (array $parsedBlock) use (&$check): void {
            $this->assertSame($parsedBlock['innerHTML'], BlockNode::create($parsedBlock)->getInnerHTML());
            \array_map($check, $parsedBlock['innerBlocks']);
        };

        \array_map($check, \parse_blocks(self::fixture('demo.html')));
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
                    'innerHTML' => '<h2 class="wp-block-heading">Sub-heading</h2>',
                    // The surplus placeholder of a childless block is dropped.
                    'innerContent' => ['<h2 class="wp-block-heading">Sub-heading</h2>'],
                ],
            ],
            'innerHTML' => '<div class="wp-block-columns has-background" style="background-color:red"></div>',
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

    public function testRenamingAnAttributeToItsOwnNameKeepsIt(): void
    {
        $node = new BlockNode('core/button', ['url' => 'https://example.com']);

        $node->renameAttribute('url', 'url');

        $this->assertSame(['url' => 'https://example.com'], $node->getAttributes());
    }

    public function testClearContent(): void
    {
        $node = new BlockNode('core/paragraph', [], [], ['<p>', 'Some content', '</p>']);
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

    public function testStaticCreateHasNoParentUntilAttached(): void
    {
        $parent = new BlockNode('core/buttons');
        $node = BlockNode::create(['blockName' => 'core/button']);

        $this->assertNull($node->getParent());

        $parent->appendInnerBlock($node);

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
        $this->assertSame("\n\n", $group->getInnerHTML(), 'only the separator is left');
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
        $node = new BlockNode('core/group', [], [['blockName' => 'core/paragraph']], ['<div>', '</div>']);

        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testWrapTakesTheChildsPlaceInItsParent(): void
    {
        $group = $this->group();
        $child = $group->getInnerBlocks()[0];

        $wrapper = BlockNode::wrap($child, 'core/cover', ['dimRatio' => 50], '<div class="cover">', '</div>');

        $this->assertSame('core/cover', $wrapper->getBlockName());
        $this->assertSame(['dimRatio' => 50], $wrapper->getAttributes());
        $this->assertSame(['<div class="cover">', null, '</div>'], $wrapper->getInnerContent());
        $this->assertSame([$child], $wrapper->getInnerBlocks());
        $this->assertSame($wrapper, $child->getParent());
        $this->assertSame($wrapper, $group->getInnerBlocks()[0]);
        $this->assertSame($group, $wrapper->getParent());
        $this->assertCount(2, $group->getInnerBlocks());
        $this->assertSame(2, $child->getDepth());
        $this->assertSame(1, \substr_count((string) $group, '<p>A</p>'));
    }

    public function testWrapLeavesTheChildListedWhileItsParentIsLocked(): void
    {
        $group = $this->group();
        $child = $group->getInnerBlocks()[0];
        $group->lockChildren();

        $wrapper = BlockNode::wrap($child, 'core/cover');

        $this->assertSame([$child], $wrapper->getInnerBlocks());
        $this->assertSame($wrapper, $child->getParent());
        $this->assertSame(0, $group->indexOf($child), 'the child is still listed by its former parent until the traverser swaps it');

        $group->unlockChildren();
        $group->replaceInnerBlockAt(0, $wrapper);

        $this->assertSame($group, $wrapper->getParent());
        $this->assertSame($wrapper, $child->getParent(), 'replacing the child by its wrapper must not detach it from the wrapper');
    }

    public function testAWrapperCanBeMovedToAnotherParentWithoutDuplicatingTheChild(): void
    {
        $group = $this->group();
        $child = $group->getInnerBlocks()[0];
        $other = new BlockNode('core/column', [], [], ['<div></div>']);

        $other->appendInnerBlock(BlockNode::wrap($child, 'core/cover'));

        $this->assertCount(1, $group->getInnerBlocks());
        $this->assertCount(1, $other->getInnerBlocks());
        $this->assertSame(0, \substr_count((string) $group, '<p>A</p>'));
        $this->assertSame(1, \substr_count((string) $other, '<p>A</p>'));
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
        $node = new BlockNode('core/paragraph', [], [], ['Hello']);

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
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], ['<div>', null, '<hr>', null, '</div>']);

        $node->appendInnerBlock(new BlockNode('core/c'));

        $this->assertSame(['<div>', null, '<hr>', null, "\n\n", null, '</div>'], $node->getInnerContent());
        $this->assertSame(['core/a', 'core/b', 'core/c'], $this->names($node));
    }

    public function testRemovingAChildKeepsNonWhitespaceMarkupAroundIt(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], ['<div>', null, '<hr>', null, '</div>']);

        $node->removeInnerBlockAt(0);

        $this->assertSame(['<div>', '<hr>', null, '</div>'], $node->getInnerContent());
    }

    public function testRemovingTheLastChildDropsTheSeparatorBeforeIt(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], ['<div>', null, "\n\n", null, '</div>']);

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
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')], ['<div>', null, '</div>']);

        $this->assertSame(['<div>', null, '</div>'], $node->getInnerContent());
    }

    public function testListsAreNormalizedFromKeyedArrays(): void
    {
        $a = new BlockNode('core/a');
        $b = new BlockNode('core/b');
        $node = new BlockNode('core/group', [], [3 => $a], [7 => '<div>', 9 => null, 11 => '</div>']);

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
        $node = new BlockNode('core/paragraph', [], [], ['<p>Hi</p>']);

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
        $this->expectExceptionMessage('that would close a cycle');

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
        $node = new BlockNode('core/image', [], [], ['<img src="a.jpg">']);

        $node->wrapInnerContent('<figure><img class="cover" src="b.jpg"><br>');

        $this->assertSame(['<figure><img class="cover" src="b.jpg"><br>', '<img src="a.jpg">', '</figure>'], $node->getInnerContent());
    }

    public function testTheSameChildCannotBeListedTwice(): void
    {
        $twice = new BlockNode('core/paragraph');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('twice');

        new BlockNode('core/group', [], [$twice, $twice], ['<div>', null, null, '</div>']);
    }

    public function testSetInnerBlocksWithTheSameCountKeepsTheContentBetweenChildren(): void
    {
        $a = new BlockNode('core/a');
        $b = new BlockNode('core/b');
        $c = new BlockNode('core/c');
        $node = new BlockNode('core/group', [], [$a, $b], ['<div>', null, '<hr>', null, '</div>']);

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
        $node = new BlockNode('core/separator', [], [], ['<p>x</p>']);

        $node->wrapInnerContent('<br>');

        $this->assertSame(['<br>', '<p>x</p>'], $node->getInnerContent());
    }

    public function testWrapInnerContentWithoutAnyTagIsStillANoOp(): void
    {
        $node = new BlockNode('core/group', [], [], ['<p>x</p>']);

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

    public function testListShapedAttributesSerializeAsAJsonObject(): void
    {
        $node = new BlockNode('core/paragraph', ['a', 'b']);

        $this->assertSame('<!-- wp:paragraph {"0":"a","1":"b"} /-->', (string) $node);
        $this->assertSame(['a', 'b'], \parse_blocks((string) $node)[0]['attrs']);
    }

    public function testRemovingAnAttributeThatLeavesOnlyNumericKeysStillRoundTrips(): void
    {
        $node = BlockNode::createFromString('<!-- wp:paragraph {"0":"a","b":1} /-->');

        $node->removeAttribute('b');

        $this->assertSame('<!-- wp:paragraph {"0":"a"} /-->', (string) $node);
    }

    public function testADocumentWithNumericAttributeKeysIsAccepted(): void
    {
        $root = BlockNode::createRoot('<!-- wp:paragraph {"0":"a"} /-->');

        $this->assertSame([0 => 'a'], $root->getInnerBlocks()[0]->getAttributes());
    }

    public function testAttributesStayAMapWhenAnotherKeyIsPresent(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'x']);

        $node->setAttribute('0', 'a');

        $this->assertSame(['className' => 'x', 0 => 'a'], $node->getAttributes());
        $this->assertSame('<!-- wp:paragraph {"className":"x","0":"a"} /-->', (string) $node);
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
        yield ["core/paragraph\n"];
        yield ['@root'];
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
        yield ['my_plugin/my_block'];
    }

    public function testAFreeformNodeKeepsItsNullName(): void
    {
        $this->assertNull((new BlockNode())->getBlockName());
        $this->assertTrue(BlockNode::createRoot('')->isRoot());
    }

    public function testAttributesThatCannotBeEncodedAreReportedInsteadOfDropped(): void
    {
        $node = new BlockNode('core/paragraph', ['className' => 'keep-me'], [], ['<p>hi</p>']);
        $node->setAttribute('bad', \INF);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON cannot encode');

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
        $this->expectExceptionMessage('that would close a cycle');

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
        $node = new BlockNode('core/group', [], $children, ['<div>', '</div>']);

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
        $node = new BlockNode('core/group', [], [], ['<div>']);

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
        $node = new BlockNode('core/group', [], [], ['</div>']);

        $node->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame(['</div>', null], $node->getInnerContent());
    }

    public function testABlockNameWithAnUnderscoreLoadsAsTheParserReadsIt(): void
    {
        $root = BlockNode::createRoot('<!-- wp:my_plugin/my_block /-->');

        $this->assertSame('my_plugin/my_block', $root->getInnerBlocks()[0]->getBlockName());
        $this->assertSame('<!-- wp:my_plugin/my_block /-->', (string) $root);
    }

    public function testInsertingAChildIntoItsOwnParentMovesIt(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b'), new BlockNode('core/c')]);
        [$a, $b, $c] = $node->getInnerBlocks();

        $node->insertInnerBlockAt(0, $c);

        $this->assertSame([$c, $a, $b], $node->getInnerBlocks());
        $this->assertSame($node, $c->getParent());
        $this->assertSame([null, "\n\n", null, "\n\n", null], $node->getInnerContent());
    }

    public function testInsertingAChildBackAtItsOwnPlaceKeepsTheContent(): void
    {
        $group = $this->group();
        $before = $group->getInnerContent();
        [$a, $b] = $group->getInnerBlocks();

        $group->insertInnerBlockAt(1, $b);

        $this->assertSame([$a, $b], $group->getInnerBlocks());
        $this->assertSame($before, $group->getInnerContent());
    }

    public function testReplacingAChildBySiblingMovesTheSibling(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b'), new BlockNode('core/c')]);
        [$a, $b, $c] = $node->getInnerBlocks();

        $node->replaceInnerBlockAt(2, $a);

        $this->assertSame([$b, $a], $node->getInnerBlocks());
        $this->assertNull($c->getParent());
        $this->assertSame($node, $a->getParent());
    }

    public function testTheSameNodeCannotBeInsertedTwiceAtOnce(): void
    {
        $node = new BlockNode('core/group');
        $child = new BlockNode('core/a');

        $this->expectException(LogicException::class);

        $node->insertInnerBlockAt(0, $child, $child);
    }

    public function testSetInnerContentWithAStringPutsTheChildrenAfterIt(): void
    {
        $group = $this->group();

        $group->setInnerContent('<p class="intro">Intro</p>');

        $this->assertSame(['<p class="intro">Intro</p>', null, "\n\n", null], $group->getInnerContent());
    }

    public function testTheConstructorDoesNotSplitHandWrittenContent(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')], ['<div>Hello</div>']);

        $this->assertSame(['<div>Hello</div>', null], $node->getInnerContent());
    }

    public function testWrapInnerContentOnlyClosesTheElementsLeftOpen(): void
    {
        $node = new BlockNode('core/paragraph', [], [], ['<p>x</p>']);

        $node->wrapInnerContent('<div class="a"><span class="icon"></span><svg viewBox="0 0 1 1"/><em><b>');

        $this->assertSame(['<div class="a"><span class="icon"></span><svg viewBox="0 0 1 1"/><em><b>', '<p>x</p>', '</b>', '</em>', '</div>'], $node->getInnerContent());
    }

    public function testWrapInnerContentTreatsACloserAsClosingTheElementsInsideIt(): void
    {
        $node = new BlockNode('core/paragraph', [], [], ['<p>x</p>']);

        $node->wrapInnerContent('<div><section><span></section>');

        $this->assertSame(['<div><section><span></section>', '<p>x</p>', '</div>'], $node->getInnerContent());
    }

    public function testInsertingIntoAnEmptyContainerIgnoresAClosingTagInsideAComment(): void
    {
        $group = BlockNode::createFromString('<!-- wp:group --><div class="wp-block-group"></div><!-- </div> --><!-- /wp:group -->');

        $group->appendInnerBlock(new BlockNode('core/paragraph'));

        $this->assertSame(['<div class="wp-block-group">', null, '</div><!-- </div> -->'], $group->getInnerContent());
    }

    public function testExpandingATopLevelBlockSeparatesTheSiblings(): void
    {
        $root = BlockNode::createRoot('<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->');
        $a = $root->getInnerBlocks()[0];

        $root->replaceInnerBlockAt(0, $a, BlockNode::createFromString('<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->'));

        $this->assertSame("<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->", (string) $root);
    }

    public function testARootSerializesBackByteForByte(): void
    {
        $html = "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->\n";

        $this->assertSame($html, (string) BlockNode::createRoot($html));
    }

    public function testALockedNodeRejectsEveryChildChangeButNotItsContent(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')], ['<div>', null, '</div>']);
        $node->lockChildren();

        $attempts = [
            'append' => static fn (): BlockNode => $node->appendInnerBlock(new BlockNode('core/b')),
            'replace' => static fn (): BlockNode => $node->replaceInnerBlockAt(0, new BlockNode('core/b')),
            'remove' => static fn (): BlockNode => $node->removeInnerBlockAt(0),
            'set' => static fn (): BlockNode => $node->setInnerBlocks([]),
            'apply' => static fn (): BlockNode => $node->applyReplacements([0 => []]),
            'steal' => static fn (): BlockNode => new BlockNode('core/x', [], [$node->getInnerBlocks()[0]]),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail($name . ' should be rejected');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('"core/group" while a visitor is visiting', $exception->getMessage(), $name);
            }
        }

        $node->setAttribute('x', 1)->wrapInnerContent('<section>')->setInnerContent(['<p>', null, '</p>']);
        $this->assertCount(1, $node->getInnerBlocks());

        $node->unlockChildren();
        $node->removeInnerBlockAt(0);
        $this->assertSame([], $node->getInnerBlocks());
    }

    public function testLocksNest(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')]);
        $node->lockChildren();
        $node->lockChildren();
        $node->unlockChildren();

        $this->expectException(LogicException::class);

        $node->removeInnerBlockAt(0);
    }

    public function testALockedNodeAcceptsAnEmptyBatch(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')]);
        $node->lockChildren();

        $node->applyReplacements([])->appendInnerBlocks([])->prependInnerBlocks([]);

        $this->assertCount(1, $node->getInnerBlocks());
    }

    public function testUnlockingChildrenThatAreNotLockedIsRejected(): void
    {
        $node = new BlockNode('core/group');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('are not locked');

        $node->unlockChildren();
    }

    public function testARefusedCycleThroughAParsedArrayLeavesTheTreeUntouched(): void
    {
        $root = BlockNode::createRoot('<!-- wp:group --><div><!-- wp:paragraph /--></div><!-- /wp:group -->');
        $group = $root->getInnerBlocks()[0];
        $paragraph = $group->getInnerBlocks()[0];

        try {
            $paragraph->setInnerBlocks([['blockName' => 'core/cover', 'innerBlocks' => [$group]]]);
            $this->fail('A cycle through a parsed array should be rejected');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('that would close a cycle', $exception->getMessage());
        }

        $this->assertSame([$group], $root->getInnerBlocks());
        $this->assertSame($root, $group->getParent());
        $this->assertSame([], $paragraph->getInnerBlocks());
    }

    public function testTheConstructorTakesNoChildBeforeEveryGivenNodeIsChecked(): void
    {
        $free = new BlockNode('core/group', [], [new BlockNode('core/a')]);
        $locked = new BlockNode('core/group', [], [new BlockNode('core/b')]);
        $locked->lockChildren();

        try {
            new BlockNode('core/cover', [], [$free->getInnerBlocks()[0], $locked->getInnerBlocks()[0]]);
            $this->fail('Taking a child from a locked node should be rejected');
        } catch (LogicException) {
        }

        $this->assertCount(1, $free->getInnerBlocks());
        $this->assertSame($free, $free->getInnerBlocks()[0]->getParent());
    }

    public function testNodesHiddenInParsedArraysAreAllCheckedBeforeAnyIsMoved(): void
    {
        $target = new BlockNode('core/group', [], [], ['<div></div>']);
        $free = new BlockNode('core/group', [], [new BlockNode('core/a')]);
        $locked = new BlockNode('core/group', [], [new BlockNode('core/b')]);
        $locked->lockChildren();

        try {
            $target->appendInnerBlocks([
                ['blockName' => 'core/cover', 'innerBlocks' => [$free->getInnerBlocks()[0]]],
                ['blockName' => 'core/cover', 'innerBlocks' => [$locked->getInnerBlocks()[0]]],
            ]);
            $this->fail('Taking a child from a locked node should be rejected');
        } catch (LogicException) {
        }

        $this->assertCount(1, $free->getInnerBlocks());
        $this->assertSame($free, $free->getInnerBlocks()[0]->getParent());
        $this->assertSame([], $target->getInnerBlocks());
        $this->assertSame(['<div></div>'], $target->getInnerContent());
    }

    /**
     * @param array<int, int> $plan Index of the child => how many nodes replace it.
     * @param list<string|null> $content
     */
    #[DataProvider('batchReplacementProvider')]
    public function testBatchReplacementMatchesOneByOneReplacement(array $content, array $plan): void
    {
        $build = static function () use ($content): BlockNode {
            $children = \array_map(
                static fn (int $i): BlockNode => new BlockNode('core/c' . $i),
                \array_keys(\array_keys($content, null, true))
            );

            return new BlockNode('core/group', [], $children, $content);
        };
        $replacementsFor = static function (BlockNode $node) use ($plan): array {
            $replacements = [];
            foreach ($plan as $index => $count) {
                $replacements[$index] = [];
                for ($i = 0; $i < $count; $i++) {
                    $replacements[$index][] = $i === 0 && $count > 1 ? $node->getInnerBlocks()[$index] : new BlockNode('core/new');
                }
            }

            return $replacements;
        };

        $oneByOne = $build();
        $replacements = $replacementsFor($oneByOne);
        \krsort($replacements);
        foreach ($replacements as $index => $nodes) {
            $oneByOne->replaceInnerBlockAt($index, ...$nodes);
        }

        $batch = $build();
        $batch->applyReplacements($replacementsFor($batch));

        $this->assertSame($oneByOne->getInnerContent(), $batch->getInnerContent());
        $this->assertSame($this->names($oneByOne), $this->names($batch));
    }

    /**
     * @return \Iterator<string, array{list<string|null>, array<int, int>}>
     */
    public static function batchReplacementProvider(): \Iterator
    {
        $separated = ['<div>', null, "\n\n", null, "\n\n", null, "\n\n", null, '</div>'];
        $tight = ['<div>', null, null, '<hr>', null, ' ', null];

        yield 'remove all' => [$separated, [0 => 0, 1 => 0, 2 => 0, 3 => 0]];
        yield 'remove every other' => [$separated, [0 => 0, 2 => 0]];
        yield 'remove the last two' => [$separated, [2 => 0, 3 => 0]];
        yield 'remove next to an expansion' => [$separated, [1 => 0, 2 => 3]];
        yield 'expand then remove' => [$separated, [1 => 2, 2 => 0]];
        yield 'swap and remove' => [$separated, [0 => 1, 3 => 0]];
        yield 'tight remove all' => [$tight, [0 => 0, 1 => 0, 2 => 0, 3 => 0]];
        yield 'tight remove around markup' => [$tight, [1 => 0, 2 => 0]];
        yield 'tight expand' => [$tight, [3 => 2, 0 => 0]];

        $mixed = ['<div>', null, "\n", null, "\n\n", null, ' ', null, '</div>'];
        yield 'expand the first after removing the second' => [$mixed, [0 => 2, 1 => 0]];
        yield 'expand the first after expanding the second' => [$mixed, [0 => 3, 1 => 2]];
        yield 'expand the second after removing the third' => [$mixed, [1 => 2, 2 => 0]];
        yield 'expand the first of a tight list after removing the rest' => [$tight, [0 => 2, 1 => 0, 2 => 0]];
    }

    public function testBatchReplacementRejectsASibling(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')]);

        $this->expectException(LogicException::class);

        $node->applyReplacements([0 => [$node->getInnerBlocks()[1]]]);
    }

    public function testRemovingEveryChildOfAWideParentStaysLinear(): void
    {
        $build = static fn (int $count): BlockNode => new BlockNode('core/group', [], \array_map(static fn (): BlockNode => new BlockNode('core/a'), \range(1, $count)));
        $time = static function (BlockNode $node): float {
            $start = \hrtime(true);
            $node->applyReplacements(\array_fill(0, \count($node->getInnerBlocks()), []));

            return (float) (\hrtime(true) - $start);
        };

        $small = $time($build(5000));
        $large = $time($build(20000));

        // Quadratic would be 16x; leave room for noise.
        $this->assertLessThan(8 * $small, $large);
    }

    public function testTheConstructorMovesAChildThatAlreadyHasAParent(): void
    {
        $group = $this->group();
        [$a, $b] = $group->getInnerBlocks();

        $cover = new BlockNode('core/cover', [], [$a]);

        $this->assertSame([$b], $group->getInnerBlocks());
        $this->assertSame($cover, $a->getParent());
        $this->assertStringNotContainsString('<p>A</p>', (string) $group);
    }

    public function testCreateMovesTheChildrenOfAnotherNode(): void
    {
        $group = $this->group();
        $children = $group->getInnerBlocks();

        $copy = BlockNode::create(['blockName' => 'core/columns', 'innerBlocks' => $children]);

        $this->assertSame($children, $copy->getInnerBlocks());
        $this->assertSame([], $group->getInnerBlocks());
    }

    public function testACloneIsADetachedCopyOfTheWholeSubtree(): void
    {
        $root = BlockNode::createRoot('<!-- wp:group --><div><!-- wp:columns --><div><!-- wp:column /--></div><!-- /wp:columns --></div><!-- /wp:group -->');
        $group = $root->getInnerBlocks()[0];
        $before = (string) $group;

        $copy = clone $group;
        $columns = $copy->getInnerBlocks()[0];

        $this->assertNull($copy->getParent());
        $this->assertSame($before, (string) $copy);
        $this->assertNotSame($group->getInnerBlocks()[0], $columns);
        $this->assertSame($copy, $columns->getParent());
        $this->assertNotSame($group->getInnerBlocks()[0]->getInnerBlocks()[0], $columns->getInnerBlocks()[0]);
        $this->assertSame($columns, $columns->getInnerBlocks()[0]->getParent());
    }

    public function testChangingACloneLeavesTheOriginalAlone(): void
    {
        $group = $this->group();
        $before = (string) $group;

        $copy = clone $group;
        $copy->getInnerBlocks()[0]->setAttribute('align', 'wide');
        (new BlockNode('core/column'))->appendInnerBlock($copy->getInnerBlocks()[1]);

        $this->assertSame($before, (string) $group);
        $this->assertCount(1, $copy->getInnerBlocks());
        $this->assertSame($group, $group->getInnerBlocks()[1]->getParent());
    }

    public function testACloneTakenDuringATraversalIsNotLocked(): void
    {
        $group = $this->group();
        $group->lockChildren();

        try {
            $copy = clone $group;
        } finally {
            $group->unlockChildren();
        }

        $copy->appendInnerBlock(new BlockNode('core/separator'));

        $this->assertCount(3, $copy->getInnerBlocks());
    }

    public function testANewSiblingCopiesTheSeparatorOfTheList(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b'), new BlockNode('core/c')], ["<div>\n", null, "\n", null, "\n", null, "\n</div>"]);

        $node->insertInnerBlockAt(1, new BlockNode('core/x'));
        $node->appendInnerBlock(new BlockNode('core/y'));
        $node->replaceInnerBlockAt(0, ...[$node->getInnerBlocks()[0], new BlockNode('core/z')]);

        $this->assertSame(["<div>\n", null, "\n", null, "\n", null, "\n", null, "\n", null, "\n", null, "\n</div>"], $node->getInnerContent());
    }

    public function testATightListStaysTight(): void
    {
        $node = new BlockNode('core/buttons', [], [new BlockNode('core/a'), new BlockNode('core/b')], ['<div>', null, null, '</div>']);

        $node->appendInnerBlock(new BlockNode('core/c'));
        $node->insertInnerBlockAt(0, new BlockNode('core/d'));

        $this->assertSame(['<div>', null, null, null, null, '</div>'], $node->getInnerContent());
    }

    public function testSiblingsSeparatedByMarkupGetABlankLine(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a'), new BlockNode('core/b')], ['<div>', null, '<hr>', null, '</div>']);

        $node->appendInnerBlock(new BlockNode('core/c'));

        $this->assertSame(['<div>', null, '<hr>', null, "\n\n", null, '</div>'], $node->getInnerContent());
    }

    public function testAnOnlyChildGetsABlankLine(): void
    {
        $node = new BlockNode('core/group', [], [new BlockNode('core/a')], ['<div>', null, '</div>']);

        $node->appendInnerBlock(new BlockNode('core/b'));

        $this->assertSame(['<div>', null, "\n\n", null, '</div>'], $node->getInnerContent());
    }

    public function testATopLevelSiblingGetsABlankLine(): void
    {
        $root = BlockNode::createRoot("<!-- wp:a /-->\n\n<!-- wp:b /-->");

        $root->appendInnerBlock(new BlockNode('core/c'));

        $this->assertSame("<!-- wp:a /-->\n\n<!-- wp:b /-->\n\n<!-- wp:c /-->", (string) $root);
    }

    public function testSerializationMatchesWordPressOnEveryBlockOfTheFixture(): void
    {
        $check = function (array $parsedBlock) use (&$check): void {
            $this->assertSame(\serialize_block($parsedBlock), (string) BlockNode::create($parsedBlock));
            \array_map($check, $parsedBlock['innerBlocks']);
        };

        \array_map($check, \parse_blocks(self::fixture('demo.html')));
    }

    /**
     * @param array<string, mixed> $parsedBlock
     */
    #[DataProvider('serializationEdgeCaseProvider')]
    public function testSerializationMatchesWordPressOnEdgeCases(array $parsedBlock): void
    {
        $this->assertSame(\serialize_block($parsedBlock), (string) BlockNode::create($parsedBlock));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function serializationEdgeCaseProvider(): \Iterator
    {
        $block = static fn (?string $name, array $attrs, array $innerContent): array => [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerBlocks' => [],
            'innerHTML' => \implode('', $innerContent),
            'innerContent' => $innerContent,
        ];

        yield 'void block' => [$block('core/separator', [], [])];
        yield 'void block with attributes' => [$block('core/spacer', ['height' => '10px'], [])];
        yield 'content "0" is void' => [$block('core/paragraph', ['a' => 1], ['0'])];
        yield 'third-party namespace' => [$block('my_plugin/my_block', [], ['<p>x</p>'])];
        yield 'characters WordPress escapes' => [$block('core/html', ['content' => '<b>--&"\\'], ['<b>x</b>'])];
        yield 'freeform' => [$block(null, [], ["\n\n"])];
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

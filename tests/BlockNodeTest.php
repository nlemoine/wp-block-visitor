<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests;

use Mantle\Testkit\Unit_Test_Case;
use n5s\BlockVisitor\BlockNode;

class BlockNodeTest extends Unit_Test_Case
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
                    'innerContent' => ['
<h2 class="wp-block-heading">Sub-heading</h2>', null],
                ],
            ],
            'innerHTML' => '',
            'innerContent' => ['
<div class="wp-block-columns has-background" style="background-color:red">', null, '</div>
'],
        ];

        $this->assertEquals($expected, $node->toArray());
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
        if (!function_exists('serialize_block')) {
            $this->markTestSkipped('serialize_block function is not available.');
        }

        $node = new BlockNode(blockName: 'core/paragraph', innerContent: ['Hello']);
        $expected = '<!-- wp:paragraph -->
<p>Hello</p>
<!-- /wp:paragraph -->';

        $this->assertStringContainsString('wp:paragraph', (string) $node);
        $this->assertStringContainsString('Hello', (string) $node);
    }

    public function testToStringForRootBlock(): void
    {
        if (!function_exists('serialize_blocks')) {
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

    public function testWrapInnerBlocks(): void
    {
        $node = BlockNode::create([
            'blockName' => 'core/group',
        ]);
        $node->wrapInnerContent('<div class="wp-block-group">');

        $this->assertStringContainsString('<div class="wp-block-group">', (string) $node);

        $node = BlockNode::create([
            'blockName' => 'core/group',
        ]);
        $node->wrapInnerContent('<div class="wp-block-group"><div>');

        $this->assertStringContainsString('<div class="wp-block-group"><div>', (string) $node);

        $node = BlockNode::create([
            'blockName' => 'core/group',
        ]);

        // $node->wrapInnerContent('<div');

        // $this->assertTrue('' === (string) $node);
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
                        'innerContent' => ["<p>Column {$index}</p>"],
                    ]),
                ],
                'innerContent' => ["<div class=\"wp-block-column\">", ...array_fill(0, 1, null), '</div>'],
            ]);
        }

        return BlockNode::create([
            'blockName' => 'core/columns',
            'innerContent' => ['<div class="wp-block-columns">', ...array_fill(0, count($columns), null), '</div>'],
            'innerBlocks' => $columns,
        ]);
    }
}

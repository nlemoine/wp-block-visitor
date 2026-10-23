<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Unit\Internal;

use n5s\BlockVisitor\Internal\Tags;
use n5s\BlockVisitor\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TagsTest extends TestCase
{
    /**
     * @param list<string>|null $expected
     */
    #[DataProvider('openElementsProvider')]
    public function testOpenElementsAreTheOnesLeftUnclosed(string $html, ?array $expected): void
    {
        $this->assertSame($expected, Tags::openElements($html));
    }

    /**
     * @return \Iterator<string, array{string, list<string>|null}>
     */
    public static function openElementsProvider(): \Iterator
    {
        yield 'one opener' => ['<div class="wp-block-group">', ['div']];
        yield 'nested openers' => ['<section><div>', ['section', 'div']];
        yield 'closed child' => ['<div><p></p>', ['div']];
        yield 'a closer ends the elements open inside it' => ['<div><span></div>', []];
        yield 'uppercase name is lowered' => ['<DIV>', ['div']];
        yield 'void element' => ['<br>', []];
        yield 'self-closing flag' => ['<img src="x"/>', []];
        yield 'closer only' => ['</div>', null];
        yield 'no tag' => ['no tags here', null];
    }

    #[DataProvider('emptyPositionProvider')]
    public function testEmptyPositionIsWhereTheChildrenGo(string $html, ?int $expected): void
    {
        $this->assertSame($expected, Tags::emptyPosition($html));
    }

    /**
     * @return \Iterator<string, array{string, int|null}>
     */
    public static function emptyPositionProvider(): \Iterator
    {
        yield 'empty group' => ['<div class="wp-block-group"></div>', 28];
        yield 'paragraph with text' => ['<p>x</p>', 4];
        yield 'closer inside a comment does not count' => ['<!-- </div> --><div></div>', 20];
        yield 'void element' => ['<br>', null];
        yield 'no tag' => ['text', null];
    }
}

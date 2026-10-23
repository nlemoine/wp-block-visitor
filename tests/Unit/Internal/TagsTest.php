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
        yield 'self-closing flag on a void element' => ['<img src="x"/>', []];
        yield 'self-closing flag on an HTML element is ignored, as browsers do' => ['<div class="x"/>', ['div']];
        yield 'self-closing flag on svg' => ['<svg/>', []];
        yield 'self-closing flag inside svg' => ['<svg><path d="M0 0"/>', ['svg']];
        yield 'self-closing flag inside math' => ['<math><mi/>', ['math']];
        yield 'closer only' => ['</div>', null];
        yield 'no tag' => ['no tags here', null];
    }

    #[DataProvider('emptyPositionProvider')]
    public function testEmptyPositionIsWhereTheChildrenGo(string $html, ?int $expected): void
    {
        $this->assertSame($expected, Tags::emptyPosition($html));
    }

    /**
     * Each case is the markup split where the children go, so the expected position reads as
     * the length of the first half.
     *
     * @return \Iterator<string, array{string, int|null}>
     */
    public static function emptyPositionProvider(): \Iterator
    {
        $inside = static fn (string $before, string $after): array => [$before . $after, \strlen($before)];

        yield 'empty group' => $inside('<div class="wp-block-group">', '</div>');
        yield 'legacy group' => $inside('<div class="wp-block-group"><div class="wp-block-group__inner-container">', '</div></div>');
        yield 'empty cover' => $inside(
            '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><div class="wp-block-cover__inner-container">',
            '</div></div>',
        );
        yield 'empty media-text' => $inside(
            '<div class="wp-block-media-text"><figure class="wp-block-media-text__media"></figure><div class="wp-block-media-text__content">',
            '</div></div>',
        );
        yield 'details keep the summary before the children' => $inside('<details class="wp-block-details"><summary>Title</summary>', '</details>');
        yield 'empty quote' => $inside('<blockquote class="wp-block-quote">', '</blockquote>');
        yield 'whitespace inside still counts as empty' => $inside('<div class="wp-block-group">', "\n</div>");
        yield 'the last empty element wins over a filled one after it' => $inside(
            '<div class="wp-block-media-text"><figure class="wp-block-media-text__media">',
            '</figure><div class="wp-block-media-text__content"><p>raw</p></div></div>',
        );
        yield 'paragraph with text' => $inside('<p>x', '</p>');
        yield 'closer inside a comment does not count' => $inside('<!-- </div> --><div>', '</div>');
        yield 'void element' => ['<br>', null];
        yield 'no tag' => ['text', null];
    }
}

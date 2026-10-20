<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Unit\Internal;

use LogicException;
use n5s\BlockVisitor\Internal\Placeholders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Plain arrays in, plain arrays out: nothing here needs WordPress.
 */
final class PlaceholdersTest extends TestCase
{
    private const array GROUP = ['<div>', null, "\n\n", null, "\n\n", null, '</div>'];

    public function testPositionIsFoundFromEitherEnd(): void
    {
        $this->assertSame(1, Placeholders::position(self::GROUP, 0, 3));
        $this->assertSame(3, Placeholders::position(self::GROUP, 1, 3));
        $this->assertSame(5, Placeholders::position(self::GROUP, 2, 3));
    }

    public function testAMissingPlaceholderIsReported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('out of sync');

        Placeholders::position(['<div>', '</div>'], 0, 1);
    }

    /**
     * @param list<string|null> $expected
     */
    #[DataProvider('runProvider')]
    public function testRun(int $count, string $separator, bool $leading, bool $trailing, array $expected): void
    {
        $this->assertSame($expected, Placeholders::run($count, $separator, $leading, $trailing));
    }

    /**
     * @return \Iterator<string, array{int, string, bool, bool, list<string|null>}>
     */
    public static function runProvider(): \Iterator
    {
        yield 'none' => [0, "\n\n", true, true, []];
        yield 'one' => [1, "\n\n", false, false, [null]];
        yield 'three' => [3, "\n", false, false, [null, "\n", null, "\n", null]];
        yield 'leading' => [2, "\n\n", true, false, ["\n\n", null, "\n\n", null]];
        yield 'trailing' => [1, "\n\n", false, true, [null, "\n\n"]];
        yield 'empty separator adds no chunk' => [3, '', true, true, [null, null, null]];
    }

    /**
     * @param list<string|null> $content
     */
    #[DataProvider('separatorProvider')]
    public function testSeparatorIsTheOneBetweenTheFirstTwoChildren(array $content, bool $root, string $expected): void
    {
        $this->assertSame($expected, Placeholders::separator($content, $root));
    }

    /**
     * @return \Iterator<string, array{list<string|null>, bool, string}>
     */
    public static function separatorProvider(): \Iterator
    {
        yield 'blank line' => [self::GROUP, false, "\n\n"];
        yield 'single newline' => [['<div>', null, "\n", null, "\n\n", null], false, "\n"];
        yield 'adjacent' => [[null, null, "\n\n", null], false, ''];
        yield 'markup between' => [['<ul>', null, '<hr>', null, '</ul>'], false, "\n\n"];
        yield 'two chunks between' => [[null, ' ', ' ', null], false, "\n\n"];
        yield 'only child' => [['<div>', null, '</div>'], false, "\n\n"];
        yield 'no child' => [['<div></div>'], false, "\n\n"];
        yield 'root' => [[null, null], true, "\n\n"];
    }

    /**
     * @param list<string|null> $content
     * @param list<string|null> $expected
     */
    #[DataProvider('insertProvider')]
    public function testInsert(array $content, int $index, int $count, int $children, array $expected): void
    {
        Placeholders::insert($content, $index, $count, $children, false);

        $this->assertSame($expected, $content);
    }

    /**
     * @return \Iterator<string, array{list<string|null>, int, int, int, list<string|null>}>
     */
    public static function insertProvider(): \Iterator
    {
        yield 'into an empty wrapper' => [['<div>', '</div>'], 0, 2, 0, ['<div>', null, "\n\n", null, '</div>']];
        yield 'into a single chunk' => [['<div></div>'], 0, 1, 0, ['<div></div>', null]];
        yield 'into nothing' => [[], 0, 1, 0, [null]];
        yield 'at the end' => [self::GROUP, 3, 1, 3, ['<div>', null, "\n\n", null, "\n\n", null, "\n\n", null, '</div>']];
        yield 'at the start' => [self::GROUP, 0, 1, 3, ['<div>', null, "\n\n", null, "\n\n", null, "\n\n", null, '</div>']];
        yield 'in a tight list' => [['<p>', null, null, '</p>'], 1, 2, 2, ['<p>', null, null, null, null, '</p>']];
    }

    /**
     * @param list<string|null> $expected
     */
    #[DataProvider('replaceProvider')]
    public function testReplace(int $child, int $count, array $expected): void
    {
        $content = self::GROUP;

        Placeholders::replace($content, $child, 3, $count, false);

        $this->assertSame($expected, $content);
    }

    /**
     * @return \Iterator<string, array{int, int, list<string|null>}>
     */
    public static function replaceProvider(): \Iterator
    {
        yield 'swap leaves the list as is' => [1, 1, self::GROUP];
        yield 'remove the first drops the separator on its right' => [0, 0, ['<div>', null, "\n\n", null, '</div>']];
        yield 'remove the last drops the separator on its left' => [2, 0, ['<div>', null, "\n\n", null, '</div>']];
        yield 'expand' => [1, 2, ['<div>', null, "\n\n", null, "\n\n", null, "\n\n", null, '</div>']];
    }

    public function testRemoveKeepsMarkupAroundThePlaceholder(): void
    {
        $content = ['<div>', null, '<hr>', null, '</div>'];

        Placeholders::remove($content, 3);

        $this->assertSame(['<div>', null, '<hr>', '</div>'], $content);
    }

    public function testRespanKeepsTheContentAroundTheChildren(): void
    {
        $content = ['<div>', null, '<hr>', null, '</div>'];

        Placeholders::respan($content, 3, false);

        $this->assertSame(['<div>', null, "\n\n", null, "\n\n", null, '</div>'], $content);
    }

    /**
     * @param list<string|null> $content
     * @param list<string|null> $expected
     */
    #[DataProvider('reconcileProvider')]
    public function testReconcile(array $content, int $children, array $expected): void
    {
        $this->assertSame($expected, Placeholders::reconcile($content, $children, false));
    }

    /**
     * @return \Iterator<string, array{list<string|null>, int, list<string|null>}>
     */
    public static function reconcileProvider(): \Iterator
    {
        yield 'consistent' => [self::GROUP, 3, self::GROUP];
        yield 'surplus' => [self::GROUP, 1, ['<div>', null, '</div>']];
        yield 'missing after the last' => [['<div>', null, '</div>'], 3, ['<div>', null, "\n\n", null, "\n\n", null, '</div>']];
        yield 'none between wrapper chunks' => [['<div>', '</div>'], 1, ['<div>', null, '</div>']];
        yield 'none after a single chunk' => [['<p>Intro</p>'], 2, ['<p>Intro</p>', null, "\n\n", null]];
    }

    /**
     * The batch must match one replacement at a time from the highest child down, on lists
     * mixing every kind of chunk between placeholders. Seeded, so a failure reproduces.
     */
    public function testReplaceManyMatchesReplacingOneByOne(): void
    {
        $random = new Randomizer(new Mt19937(20261020));

        for ($round = 0; $round < 3000; $round++) {
            $children = $random->getInt(0, 6);
            $content = $this->randomContent($random, $children);
            $counts = $this->randomCounts($random, $children);

            $oneByOne = $content;
            $total = $children;
            \krsort($counts);
            foreach ($counts as $child => $count) {
                Placeholders::replace($oneByOne, $child, $total, $count, false);
                $total += $count - 1;
            }

            $batch = Placeholders::replaceMany($content, $counts, $children, false);

            $this->assertSame($oneByOne, $batch, \sprintf('round %d: %s with %s', $round, \json_encode($content), \json_encode($counts)));
            $this->assertCount($total, \array_keys($batch, null, true));
        }
    }

    /**
     * @return list<string|null>
     */
    private function randomContent(Randomizer $random, int $children): array
    {
        $chunks = ['', "\n", "\n\n", ' ', '<hr>', '</p><p>'];

        $content = ['<div>'];
        for ($i = 0; $i < $children; $i++) {
            $between = $i === 0 ? '' : $chunks[$random->getInt(0, \count($chunks) - 1)];
            if ($between !== '') {
                $content[] = $between;
            }
            $content[] = null;
        }
        $content[] = '</div>';

        return $content;
    }

    /**
     * @return array<int, int>
     */
    private function randomCounts(Randomizer $random, int $children): array
    {
        $counts = [];
        for ($i = 0; $i < $children; $i++) {
            if ($random->getInt(0, 1) === 1) {
                $counts[$i] = $random->getInt(0, 3);
            }
        }

        return $counts;
    }
}

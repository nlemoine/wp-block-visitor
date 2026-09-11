<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Integration;

use Generator;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Tests\Support\RecordingVisitor;
use n5s\BlockVisitor\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A traversal that changes nothing must serialize exactly like WordPress does.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @return Generator<string, array{string}>
     */
    public static function documentProvider(): Generator
    {
        foreach (['demo.html', 'demo-simple.html'] as $fixture) {
            yield $fixture => [self::fixture($fixture)];
        }

        yield 'inline markup' => ['<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->'];
        yield 'freeform only' => ["<p>Not a block</p>\n"];
        yield 'self-closing block' => ['<!-- wp:separator /-->'];
        yield 'empty' => [''];
    }

    #[DataProvider('documentProvider')]
    public function testNoOpTraversalMatchesWordPressSerialization(string $document): void
    {
        $expected = \serialize_blocks(\parse_blocks($document));

        $this->assertSame($expected, (string) (new BlockTraverser())->traverse($document));
        $this->assertSame($expected, (string) (new BlockTraverser(new RecordingVisitor()))->traverse($document));
        $this->assertSame($expected, (string) BlockNode::createRoot($document));
    }

    #[DataProvider('documentProvider')]
    public function testToArrayMatchesParseBlocks(string $document): void
    {
        $this->assertSame(\parse_blocks($document), BlockNode::createRoot($document)->toArray()['innerBlocks']);
    }

    public function testEveryNodeIsVisitedOnce(): void
    {
        $document = self::fixture('demo.html');
        $visitor = new RecordingVisitor();

        (new BlockTraverser($visitor))->traverse($document);

        $enters = \array_filter($visitor->log, static fn (string $entry): bool => \str_starts_with($entry, 'enter '));
        $leaves = \array_filter($visitor->log, static fn (string $entry): bool => \str_starts_with($entry, 'leave '));

        $this->assertCount(self::countBlocks(\parse_blocks($document)), $enters);
        $this->assertCount(\count($enters), $leaves);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            $count += 1 + self::countBlocks($block['innerBlocks']);
        }

        return $count;
    }
}

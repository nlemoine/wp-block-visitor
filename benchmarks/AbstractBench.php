<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Benchmarks;

use Generator;

abstract class AbstractBench
{
    protected string $html = '';

    /**
     * @return Generator<string, array{repeat: int}>
     */
    public function documents(): Generator
    {
        yield 'demo x1 (~100 blocks)' => ['repeat' => 1];
        yield 'demo x20 (~2000 blocks)' => ['repeat' => 20];
    }

    /**
     * @param array{repeat: int} $params
     */
    public function load(array $params): void
    {
        $fixture = (string) \file_get_contents(__DIR__ . '/../tests/fixtures/demo.html');
        $this->html = \rtrim(\str_repeat($fixture . "\n\n", $params['repeat']));
    }

    /**
     * Rewrites the delimiter of every block of the given type, WP_Block_Processor style.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $update
     */
    protected function rewriteDelimiters(string $blockType, callable $update): string
    {
        $processor = new \WP_Block_Processor($this->html);
        $output = '';
        $cursor = 0;
        $printable = \str_starts_with($blockType, 'core/') ? \substr($blockType, 5) : $blockType;

        while ($processor->next_block($blockType)) {
            $span = $processor->get_span();
            $attributes = $update($processor->allocate_and_return_parsed_attributes() ?? []);
            $delimiter = '<!-- wp:' . $printable . ' ' . \serialize_block_attributes($attributes) . ($processor->has_closing_flag() ? ' /-->' : ' -->');

            $output .= \substr($this->html, $cursor, $span->start - $cursor) . $delimiter;
            $cursor = $span->start + $span->length;
        }

        return $output . \substr($this->html, $cursor);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param callable(array<string, mixed>): void $visit
     */
    protected static function walkParsed(array &$blocks, callable $visit): void
    {
        foreach ($blocks as &$block) {
            $visit($block);
            if ($block['innerBlocks'] !== []) {
                self::walkParsed($block['innerBlocks'], $visit);
            }
        }
    }
}

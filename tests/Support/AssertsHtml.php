<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Support;

/**
 * Shared HTML assertion methods for test cases.
 */
trait AssertsHtml
{
    /**
     * Assert that two HTML strings are semantically equivalent.
     *
     * Uses WP_HTML_Processor to normalize both strings into tree structures,
     * tolerating differences in attribute ordering, class name ordering,
     * style whitespace, tag name capitalization, and block comment attribute ordering.
     *
     * @see https://developer.wordpress.org/news/2026/02/a-better-way-to-test-html-in-wordpress-with-assertequalhtml/
     */
    protected function assertEqualHTML(
        string $expected,
        string $actual,
        ?string $fragment_context = '<body>',
        string $message = 'HTML markup was not equivalent.',
    ): void {

        require_once __DIR__ . '/build-visual-html-tree.php';

        $treeExpected = \build_visual_html_tree($expected, $fragment_context);
        $treeActual = \build_visual_html_tree($actual, $fragment_context);

        $this->assertSame($treeExpected, $treeActual, $message);
    }

    /**
     * Assert that two block markup strings are equivalent.
     *
     * Normalizes whitespace to avoid brittle failures from formatting differences
     * while preserving the meaningful structure of block comments and HTML.
     */
    protected function assertBlocksEqual(string $expected, string $actual, string $message = ''): void
    {
        $normalize = static function (string $s): string {
            $s = \str_replace("\r\n", "\n", $s);
            $s = \preg_replace('/\n\s*\n/', "\n", $s);

            return \trim($s);
        };

        $this->assertSame(
            $normalize($expected),
            $normalize($actual),
            $message ?: \sprintf(
                "Block output mismatch.\n\nExpected:\n%s\n\nActual:\n%s",
                $expected,
                $actual,
            ),
        );
    }
}

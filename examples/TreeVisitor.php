<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use JsonException;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\BlockVisitorInterface;
use Stringable;
use WP_CLI;

class TreeVisitor implements BlockVisitorInterface, Stringable
{
    private const JSON_HIGHLIGHT_REGEX = '/(?<key>"[^"]*")\s*:\s*|(?<string>"[^"\\\\]*(?:\\\\.[^"\\\\]*)*")|(?<number>-?\d+(?:\.\d+)?)|\b(?<bool>true|false)\b|\b(?<null>null)\b|(?<brackets>[{}[\]])|(?<colon>:)|(?<comma>,)/';

    private const COLOR_SCHEMES = [
        'key' => '%g',
        'string' => '%y',
        'number' => '%b',
        'bool' => '%b',
        'null' => '%g',
        'brackets' => '%w',
        'colon' => '%w',
        'comma' => '%w',
    ];

    /**
     * @var array<string>
     */
    private array $output = [];

    private bool $useColors;

    /**
     * @var bool[]
     */
    private array $isLastChildStack = [];

    public function __construct(bool $useColors = true)
    {
        $this->useColors = $useColors;
    }

    public function enter(BlockNode $node): BlockNode|array|null
    {
        // We need to manage the stack for all nodes to keep the tree structure correct for children.
        $parent = $node->getParent();
        if ($parent) { // Root has no parent, so stack is empty for its direct children
            $siblings = $parent->getInnerBlocks();
            $isLast = !empty($siblings) && end($siblings) === $node;
            $this->isLastChildStack[] = $isLast;
        }

        // Only print nodes with a name.
        if (!empty($node->getBlockName())) {
            $prefix = $this->getPrefix();
            $blockName = $node->getBlockName();

            $coloredBlockName = $this->colorize("%m" . $blockName . "%n");
            $attrs = $node->getAttributes();
            $attrsPreview = !empty($attrs) ? " " . $this->highlightJson($attrs) : "";

            $this->output[] = $prefix . $coloredBlockName . $attrsPreview . "\n";
        }

        return $node;
    }

    public function leave(BlockNode $node): BlockNode|array|null
    {
        // Pop from stack for any node that had a parent
        if ($node->getParent()) {
            array_pop($this->isLastChildStack);
        }
        return $node;
    }

    private function getPrefix(): string
    {
        $prefix = '';
        $depth = count($this->isLastChildStack);

        if ($depth === 0) {
            return '';
        }

        // Build the preceding vertical lines
        for ($i = 0; $i < $depth - 1; $i++) {
            $prefix .= $this->isLastChildStack[$i] ? '    ' : '│   ';
        }

        // Add the connector for the current node
        $isCurrentNodeLast = $this->isLastChildStack[$depth - 1];
        $prefix .= $isCurrentNodeLast ? '└── ' : '├── ';

        return $prefix;
    }

    public function __toString(): string
    {
        return implode('', $this->output);
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @throws JsonException
     */
    private function highlightJson(array $attrs): string
    {
        return (string) preg_replace_callback(
            self::JSON_HIGHLIGHT_REGEX,
            $this->replaceCallback(...),
            json_encode($attrs, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, string> $matches
     * @return string
     */
    private function replaceCallback(array $matches): string
    {
        $token = array_filter(
            array_keys(self::COLOR_SCHEMES),
            static fn (string $type): bool => !empty($matches[$type])
        )[0] ?? null;

        if ($token === null) {
            return $matches[array_key_first($matches)] ?? '';
        }

        return $this->colorize(self::COLOR_SCHEMES[$token] . $matches[$token] . '%n') . $this->colorize('%w: %n');
    }

    private function colorize(string $string): string
    {
        if (!$this->useColors || !class_exists(WP_CLI::class)) {
            return (string) preg_replace('/%[a-zA-Z]/', '', $string);
        }

        return WP_CLI::colorize($string);
    }
}

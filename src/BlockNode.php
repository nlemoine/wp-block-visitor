<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use InvalidArgumentException;
use LogicException;
use OutOfRangeException;
use Stringable;
use WP_HTML_Tag_Processor;

/**
 * A node in a tree of parsed WordPress blocks.
 *
 * Invariants maintained by every mutator:
 * - `innerBlocks` is a list.
 * - The k-th `null` chunk of `innerContent` is the placeholder of `innerBlocks[k]`.
 * - Content that came from the parser is only rewritten when the children of this
 *   very node are mutated, so an untouched node serializes byte for byte.
 * - Every inserted child gets this node as parent; every removed child is detached.
 *
 * `innerHTML` is kept as parsed and not updated by mutators, WordPress ignores it when
 * serializing; only `clearContent()` resets it. A visitor exception leaves the tree in an
 * undefined state: discard it.
 *
 * @phpstan-type ParsedBlock array{
 *  blockName?: string|null,
 *  attrs?: array<string, mixed>,
 *  innerBlocks?: list<array<string, mixed>|BlockNode>,
 *  innerHTML?: string,
 *  innerContent?: list<string|null>,
 * }
 * @phpstan-type SerializedBlock array{
 *  blockName: string|null,
 *  attrs: array<string, mixed>,
 *  innerBlocks: list<array<string, mixed>>,
 *  innerHTML: string,
 *  innerContent: list<string|null>,
 * }
 */
final class BlockNode implements Stringable
{
    public const string ROOT_BLOCK_NAME = '@root';

    /**
     * Separator Gutenberg emits between two sibling blocks.
     */
    private const string BLOCK_SEPARATOR = "\n\n";

    /**
     * HTML elements that never have a closing tag.
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#void-elements
     */
    private const array VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private readonly bool $isRoot;

    /**
     * @var list<BlockNode>
     */
    private array $innerBlocks;

    /**
     * @var list<string|null>
     */
    private array $innerContent;

    /**
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>|BlockNode> $innerBlocks
     * @param list<string|null> $innerContent
     */
    public function __construct(
        private ?string $blockName = null,
        private array $attrs = [],
        array $innerBlocks = [],
        // phpcs:ignore Syde.NamingConventions.VariableName.SnakeCaseVar
        private string $innerHTML = '',
        array $innerContent = [],
        private ?BlockNode $parent = null,
    ) {

        $this->isRoot = $this->blockName === self::ROOT_BLOCK_NAME;
        $this->innerContent = \array_values($innerContent);
        $this->innerBlocks = \array_values(\array_map(
            fn (array|BlockNode $block): BlockNode => $block instanceof BlockNode
                ? $this->adopt($block)
                : self::create($block, $this),
            $innerBlocks
        ));

        $this->reconcilePlaceholders();
    }

    /**
     * @param array<string, mixed> $parsedBlock A `parse_blocks()` array, see the `ParsedBlock` shape.
     */
    public static function create(array $parsedBlock, ?BlockNode $parent = null): self
    {
        /** @var ParsedBlock $parsedBlock */
        return new self(
            $parsedBlock['blockName'] ?? null,
            $parsedBlock['attrs'] ?? [],
            $parsedBlock['innerBlocks'] ?? [],
            $parsedBlock['innerHTML'] ?? '',
            $parsedBlock['innerContent'] ?? [],
            $parent,
        );
    }

    /**
     * Creates a node from the first named block found in the given markup.
     *
     * Freeform content before the first block comment is skipped; markup without any
     * block comment yields a freeform node.
     *
     * @throws InvalidArgumentException When the markup is empty.
     */
    public static function createFromString(string $html, ?BlockNode $parent = null): self
    {
        $parsedBlocks = \parse_blocks($html);
        if ($parsedBlocks === []) {
            throw new InvalidArgumentException('No block found in the given markup.');
        }

        foreach ($parsedBlocks as $parsedBlock) {
            if ($parsedBlock['blockName'] !== null) {
                return self::create($parsedBlock, $parent);
            }
        }

        return self::create($parsedBlocks[0], $parent);
    }

    /**
     * Creates a root node holding every top-level block of a document.
     */
    public static function createRoot(string $html): self
    {
        return self::create([
            'blockName' => self::ROOT_BLOCK_NAME,
            'innerBlocks' => \parse_blocks($html),
        ]);
    }

    /**
     * Creates a new block wrapping the given node.
     *
     * The child is not removed from its current parent: when used from a visitor,
     * the traverser replaces the child by the wrapper afterwards.
     *
     * @param array<string, mixed> $attrs
     * @param string $before HTML emitted before the wrapped block, e.g. `<div class="wp-block-group">`.
     * @param string $after HTML emitted after the wrapped block, e.g. `</div>`.
     */
    public static function wrap(
        BlockNode $child,
        string $blockName,
        array $attrs = [],
        string $before = '',
        string $after = ''
    ): self {

        $innerContent = [];
        if ($before !== '') {
            $innerContent[] = $before;
        }
        $innerContent[] = null;
        if ($after !== '') {
            $innerContent[] = $after;
        }

        return new self($blockName, $attrs, [$child], '', $innerContent);
    }

    public function getBlockName(): ?string
    {
        return $this->blockName;
    }

    public function setBlockName(string $newBlockName): self
    {
        $this->blockName = $newBlockName;

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function getParent(): ?BlockNode
    {
        return $this->parent;
    }

    /**
     * Depth of the node, top-level blocks being at depth 0.
     */
    public function getDepth(): int
    {
        if (!$this->parent instanceof self || $this->parent->isRoot()) {
            return 0;
        }

        return $this->parent->getDepth() + 1;
    }

    // -------------------------------------------------------------------------
    //  Inner blocks
    // -------------------------------------------------------------------------

    /**
     * @return list<BlockNode>
     */
    public function getInnerBlocks(): array
    {
        return $this->innerBlocks;
    }

    /**
     * Index of the given child, compared by identity.
     */
    public function indexOf(BlockNode $child): ?int
    {
        $index = \array_search($child, $this->innerBlocks, true);

        return $index === false ? null : $index;
    }

    /**
     * Replaces the child at the given index by zero, one or more nodes.
     *
     * Zero nodes removes the child and its placeholder, one node swaps it in place,
     * several nodes expand it into siblings separated by a blank line.
     */
    public function replaceInnerBlockAt(int $index, BlockNode ...$replacements): self
    {
        $count = \count($this->innerBlocks);
        if ($index < 0 || $index >= $count) {
            throw new OutOfRangeException(\sprintf('No inner block at index %d, valid indexes are 0 to %d.', $index, $count - 1));
        }

        $replacements = \array_values($replacements);
        $old = $this->innerBlocks[$index];
        $position = $this->placeholderPosition($index);

        \array_splice($this->innerBlocks, $index, 1, $replacements);

        if ($replacements === []) {
            $this->removePlaceholderAt($position);
        } else {
            \array_splice($this->innerContent, $position, 1, $this->placeholderRun(\count($replacements), false, false));
        }

        if (!\in_array($old, $replacements, true)) {
            $this->detach($old);
        }
        foreach ($replacements as $node) {
            $this->adopt($node);
        }

        return $this;
    }

    /**
     * Inserts nodes before the child at the given index, or at the end when the index equals the count.
     */
    public function insertInnerBlockAt(int $index, BlockNode ...$nodes): self
    {
        $count = \count($this->innerBlocks);
        if ($index < 0 || $index > $count) {
            throw new OutOfRangeException(\sprintf('Cannot insert at index %d, valid indexes are 0 to %d.', $index, $count));
        }

        $nodes = \array_values($nodes);
        if ($nodes === []) {
            return $this;
        }

        if ($count === 0) {
            $position = $this->emptyContainerPosition();
            $run = $this->placeholderRun(\count($nodes), false, false);
        } elseif ($index === $count) {
            $position = $this->placeholderPosition($count - 1) + 1;
            $run = $this->placeholderRun(\count($nodes), true, false);
        } else {
            $position = $this->placeholderPosition($index);
            $run = $this->placeholderRun(\count($nodes), false, true);
        }

        \array_splice($this->innerContent, $position, 0, $run);
        \array_splice($this->innerBlocks, $index, 0, $nodes);

        foreach ($nodes as $node) {
            $this->adopt($node);
        }

        return $this;
    }

    public function removeInnerBlockAt(int $index): self
    {
        return $this->replaceInnerBlockAt($index);
    }

    public function appendInnerBlock(BlockNode $block): self
    {
        return $this->insertInnerBlockAt(\count($this->innerBlocks), $block);
    }

    /**
     * @param list<array<string, mixed>|BlockNode> $blocks
     */
    public function appendInnerBlocks(array $blocks): self
    {
        return $this->insertInnerBlockAt(\count($this->innerBlocks), ...$this->toNodes($blocks));
    }

    public function prependInnerBlock(BlockNode $block): self
    {
        return $this->insertInnerBlockAt(0, $block);
    }

    /**
     * @param list<array<string, mixed>|BlockNode> $blocks
     */
    public function prependInnerBlocks(array $blocks): self
    {
        return $this->insertInnerBlockAt(0, ...$this->toNodes($blocks));
    }

    /**
     * Replaces every child.
     *
     * When the number of children does not change, the content is left untouched.
     * Otherwise the content before the first placeholder and after the last one is
     * kept and everything in between is replaced by one placeholder per new child.
     *
     * @param list<array<string, mixed>|BlockNode> $innerBlocks
     */
    public function setInnerBlocks(array $innerBlocks): self
    {
        $new = $this->toNodes($innerBlocks);
        $old = $this->innerBlocks;

        if (\count($new) !== \count($old)) {
            $positions = \array_keys($this->innerContent, null, true);
            if ($positions === []) {
                $start = $this->emptyContainerPosition();
                $length = 0;
            } else {
                $start = $positions[0];
                $length = \end($positions) - $start + 1;
            }

            \array_splice($this->innerContent, $start, $length, $this->placeholderRun(\count($new), false, false));
        }

        $this->innerBlocks = $new;

        foreach ($old as $node) {
            if (!\in_array($node, $new, true)) {
                $this->detach($node);
            }
        }
        foreach ($new as $node) {
            $this->adopt($node);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    //  Content
    // -------------------------------------------------------------------------

    public function getInnerHTML(): string
    {
        return $this->innerHTML;
    }

    /**
     * @return list<string|null>
     */
    public function getInnerContent(): array
    {
        return $this->innerContent;
    }

    /**
     * Sets the content, keeping one placeholder per child.
     *
     * A string is used as the sole HTML chunk, placeholders are appended after it.
     * A list may contain `null` placeholders; missing ones are appended, surplus ones dropped.
     *
     * @param string|list<string|null> $content
     */
    public function setInnerContent(string|array $content): self
    {
        $this->innerContent = \is_string($content) ? [$content] : \array_values($content);
        $this->reconcilePlaceholders();

        return $this;
    }

    /**
     * Wraps the content with the given opening HTML; closing tags are generated.
     */
    public function wrapInnerContent(string $html): self
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $tagClosers = [];
        while ($processor->next_tag()) {
            $tagName = \strtolower((string) $processor->get_tag());
            if (!\in_array($tagName, self::VOID_ELEMENTS, true)) {
                $tagClosers[] = $tagName;
            }
        }

        if (\count($tagClosers) === 0) {
            return $this;
        }

        \array_unshift($this->innerContent, $html);

        foreach (\array_reverse($tagClosers) as $tagName) {
            $this->innerContent[] = \sprintf('</%s>', $tagName);
        }

        return $this;
    }

    /**
     * Drops every HTML chunk, keeping the placeholders of the children.
     */
    public function clearContent(): self
    {
        $this->innerHTML = '';
        $this->innerContent = $this->placeholderRun(\count($this->innerBlocks), false, false);

        return $this;
    }

    // -------------------------------------------------------------------------
    //  Attributes
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attrs;
    }

    public function getAttribute(string $attribute): mixed
    {
        return $this->attrs[$attribute] ?? null;
    }

    public function hasAttribute(string $attribute): bool
    {
        return \array_key_exists($attribute, $this->attrs);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): self
    {
        $this->attrs = $attributes;

        return $this;
    }

    public function setAttribute(string $attribute, mixed $value): self
    {
        $this->attrs[$attribute] = $value;

        return $this;
    }

    public function removeAttribute(string $attribute): self
    {
        unset($this->attrs[$attribute]);

        return $this;
    }

    /**
     * @param list<string> $attributes
     */
    public function removeAttributes(array $attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->removeAttribute($attribute);
        }

        return $this;
    }

    public function renameAttribute(string $from, string $to): self
    {
        if (!$this->hasAttribute($from)) {
            return $this;
        }

        $this->setAttribute($to, $this->getAttribute($from));
        $this->removeAttribute($from);

        return $this;
    }

    public function clearAttributes(): self
    {
        $this->attrs = [];

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getClassNames(): array
    {
        return $this->classNames();
    }

    public function hasClassName(string $className): bool
    {
        return \in_array($className, $this->classNames(), true);
    }

    public function addClassName(string $className): self
    {
        $classNames = $this->classNames();
        if (!\in_array($className, $classNames, true)) {
            $classNames[] = $className;
        }

        return $this->setClassNames($classNames);
    }

    public function removeClassName(string $className): self
    {
        return $this->setClassNames(\array_values(\array_filter(
            $this->classNames(),
            static fn (string $name): bool => $name !== $className
        )));
    }

    // -------------------------------------------------------------------------
    //  Serialization
    // -------------------------------------------------------------------------

    /**
     * @return SerializedBlock
     */
    public function toArray(): array
    {
        return [
            'blockName' => $this->blockName,
            'attrs' => $this->attrs,
            'innerBlocks' => \array_map(static fn (BlockNode $block): array => $block->toArray(), $this->innerBlocks),
            'innerHTML' => $this->innerHTML,
            'innerContent' => $this->innerContent,
        ];
    }

    public function __toString(): string
    {
        if ($this->isRoot) {
            return \implode('', \array_map(static fn (BlockNode $block): string => (string) $block, $this->innerBlocks));
        }

        return (string) \serialize_block($this->toArray());
    }

    // -------------------------------------------------------------------------
    //  Internals
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function classNames(): array
    {
        $className = $this->getAttribute('className');

        return \is_string($className)
            ? \array_values(\array_filter(\explode(' ', $className), static fn (string $name): bool => $name !== ''))
            : [];
    }

    /**
     * @param list<string> $classNames
     */
    private function setClassNames(array $classNames): self
    {
        return $classNames === []
            ? $this->removeAttribute('className')
            : $this->setAttribute('className', \implode(' ', $classNames));
    }

    /**
     * @param list<array<string, mixed>|BlockNode> $blocks
     * @return list<BlockNode>
     */
    private function toNodes(array $blocks): array
    {
        return \array_values(\array_map(
            fn (array|BlockNode $block): BlockNode => $block instanceof BlockNode ? $block : self::create($block, $this),
            $blocks
        ));
    }

    /**
     * @throws LogicException When the node is this node or one of its ancestors.
     */
    private function adopt(BlockNode $node): BlockNode
    {
        for ($ancestor = $this; $ancestor instanceof self; $ancestor = $ancestor->parent) {
            if ($ancestor === $node) {
                throw new LogicException(\sprintf(
                    'Cannot add block "%s" to "%s": it is the block itself or one of its ancestors.',
                    $node->getBlockName() ?? '(freeform)',
                    $this->getBlockName() ?? '(freeform)',
                ));
            }
        }

        $node->parent = $this;

        return $node;
    }

    /**
     * Forgets the parent of a node that no longer appears among the children.
     */
    private function detach(BlockNode $node): void
    {
        if ($node->parent === $this && !\in_array($node, $this->innerBlocks, true)) {
            $node->parent = null;
        }
    }

    /**
     * Position in `innerContent` of the placeholder of the child at the given index.
     */
    private function placeholderPosition(int $index): int
    {
        $seen = -1;
        foreach ($this->innerContent as $position => $chunk) {
            if ($chunk === null && ++$seen === $index) {
                return $position;
            }
        }

        throw new LogicException(\sprintf('innerContent and innerBlocks are out of sync: no placeholder for child %d.', $index));
    }

    /**
     * Where placeholders go in a node without children: between the wrapper's opening and closing chunks
     * when there are at least two, at the end otherwise.
     */
    private function emptyContainerPosition(): int
    {
        $count = \count($this->innerContent);

        return $count >= 2 ? $count - 1 : $count;
    }

    /**
     * Builds `[SEP?] null (SEP null)* [SEP?]`.
     *
     * @return list<string|null>
     */
    private function placeholderRun(int $count, bool $leadingSeparator, bool $trailingSeparator): array
    {
        if ($count <= 0) {
            return [];
        }

        $run = $leadingSeparator ? [self::BLOCK_SEPARATOR] : [];
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $run[] = self::BLOCK_SEPARATOR;
            }
            $run[] = null;
        }
        if ($trailingSeparator) {
            $run[] = self::BLOCK_SEPARATOR;
        }

        return $run;
    }

    /**
     * Drops the placeholder at the given position, plus the whitespace separator that joined it to a sibling.
     */
    private function removePlaceholderAt(int $position): void
    {
        \array_splice($this->innerContent, $position, 1);

        $right = $this->innerContent[$position] ?? null;
        $left = $position > 0 ? $this->innerContent[$position - 1] : null;

        if (
            \is_string($right)
            && \trim($right) === ''
            && \array_key_exists($position + 1, $this->innerContent)
            && $this->innerContent[$position + 1] === null
        ) {
            \array_splice($this->innerContent, $position, 1);

            return;
        }

        if (
            $position >= 2
            && \is_string($left)
            && \trim($left) === ''
            && $this->innerContent[$position - 2] === null
        ) {
            \array_splice($this->innerContent, $position - 1, 1);
        }
    }

    /**
     * Makes the placeholder count match the child count for hand-built nodes.
     * Parsed nodes are already consistent and left untouched.
     */
    private function reconcilePlaceholders(): void
    {
        $placeholders = \count(\array_keys($this->innerContent, null, true));
        $blocks = \count($this->innerBlocks);

        if ($placeholders === $blocks) {
            return;
        }

        if ($placeholders > $blocks) {
            for ($i = $placeholders; $i > $blocks; $i--) {
                $this->removePlaceholderAt($this->placeholderPosition($i - 1));
            }

            return;
        }

        $missing = $blocks - $placeholders;
        if ($placeholders === 0) {
            \array_splice($this->innerContent, $this->emptyContainerPosition(), 0, $this->placeholderRun($missing, false, false));

            return;
        }

        $position = $this->placeholderPosition($placeholders - 1) + 1;
        \array_splice($this->innerContent, $position, 0, $this->placeholderRun($missing, true, false));
    }
}

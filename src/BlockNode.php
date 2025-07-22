<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use Stringable;
use WP_HTML_Tag_Processor;

/**
 * @phpstan-type ParsedBlock array{
 *  blockName?: string|null,
 *  attrs?: array<string, mixed>,
 *  innerBlocks?: list<self>,
 *  innerHTML?: string,
 *  innerContent?: array<string|null>,
 *  parent?: BlockNode|null,
 * }
 */
final class BlockNode implements Stringable
{
    public const ROOT_BLOCK_NAME = '@root';

    private bool $isRoot;
    /**
     * @var BlockNode[]
     */
    private array $innerBlocks;

    /**
     * @param string|null $blockName
     * @param array<string, mixed> $attrs
     * @param array<BlockNode|array> $innerBlocks
     * @param string $innerHTML
     * @param array<string|null> $innerContent
     * @param BlockNode|null $parent
     * @param int $depth
     */
    public function __construct(
        private ?string $blockName = null,
        private array $attrs = [],
        array $innerBlocks = [],
        // phpcs:disable Syde.NamingConventions.VariableName.SnakeCaseVar
        private string $innerHTML = '',
        private array $innerContent = [],
        private ?BlockNode $parent = null,
        private int $depth = 0
    ) {

        $this->isRoot = $this->blockName === self::ROOT_BLOCK_NAME;
        $this->depth = $parent instanceof BlockNode && !$parent->isRoot() ? $parent->getDepth() + 1 : 0;
        $this->innerBlocks = array_map(function (array|BlockNode $block): BlockNode {
            if ($block instanceof BlockNode) {
                $block->setParent($this);
                return $block;
            }

            return BlockNode::create($block, $this);
        }, $innerBlocks);

        $this->updateInnerContent();
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

    public function getInnerHTML(): string
    {
        return $this->innerHTML;
    }

    /**
     * @return BlockNode[]
     */
    public function getInnerBlocks(): array
    {
        return $this->innerBlocks;
    }

    /**
     * @param BlockNode[] $innerBlocks
     */
    public function setInnerBlocks(array $innerBlocks): self
    {
        $oldBlocks = $this->innerBlocks;
        $newBlocks = $innerBlocks;

        $this->innerBlocks = array_map(function (BlockNode $block): BlockNode {
            $block->setParent($this);
            return $block;
        }, $newBlocks);

        // This logic only handles removals and preserves order.
        if (count($newBlocks) < count($oldBlocks)) {
            $newBlockHashes = array_map('spl_object_hash', $newBlocks);
            $newInnerContent = [];
            $blockIndex = 0;

            foreach ($this->innerContent as $chunk) {
                if ($chunk !== null) {
                    // It's an HTML string.
                    // If the last item added to newInnerContent was also a string, merge them.
                    if (count($newInnerContent) > 0 && is_string(end($newInnerContent))) {
                        $newInnerContent[key($newInnerContent)] .= $chunk;
                    } else {
                        $newInnerContent[] = $chunk;
                    }
                } else {
                    // It's a block placeholder.
                    if (isset($oldBlocks[$blockIndex])) {
                        $oldBlockHash = spl_object_hash($oldBlocks[$blockIndex]);
                        // Check if this block exists in the new set of blocks.
                        if (in_array($oldBlockHash, $newBlockHashes, true)) {
                            // The block was kept. Add its placeholder.
                            $newInnerContent[] = null;
                            // Remove from hash list to handle duplicate blocks correctly.
                            $key = array_search($oldBlockHash, $newBlockHashes, true);
                            if ($key !== false) {
                                unset($newBlockHashes[$key]);
                            }
                        } else {
                            // The block was removed. Do nothing, its placeholder is skipped.
                            // The string merging logic above will handle concatenating HTML chunks.
                        }
                    }
                    $blockIndex++;
                }
            }
            $this->innerContent = $newInnerContent;
            return $this;
        }

        if (count($oldBlocks) !== count($newBlocks)) {
            $this->innerContent = [];
            $this->updateInnerContent();
        }

        return $this;
    }

    /**
     * Prepends a block to the beginning of the inner block list.
     */
    public function prependInnerBlock(BlockNode $block): self
    {
        $block->setParent($this);
        array_unshift($this->innerBlocks, $block);

        if (count($this->innerBlocks) === 1) {
            array_unshift($this->innerContent, null);

            return $this;
        }

        foreach ($this->innerContent as $i => $chunk) {
            if ($chunk === null) {
                array_splice($this->innerContent, $i, 0, [null]);

                return $this;
            }
        }

        return $this;
    }

    public function appendInnerBlocks(array $blocks): self
    {
        foreach ($blocks as $block) {
            if (!$block instanceof BlockNode) {
                $block = self::create($block, $this);
            }
            $this->appendInnerBlock($block);
        }

        return $this;
    }

    /**
     * Appends a block to the end of the inner block list.
     */
    public function appendInnerBlock(BlockNode $block): self
    {
        $block->setParent($this);
        $this->innerBlocks[] = $block;

        if (count($this->innerBlocks) === 1) {
            $this->innerContent[] = null;

            return $this;
        }

        // We need to find the null placeholder for the block that was previously the last one.
        // Since the new block has already been added, the index of the previous last block is count - 2.
        $blockToFind = count($this->innerBlocks) - 2;
        $nullCounter = 0;
        foreach ($this->innerContent as $i => $chunk) {
            if ($chunk === null) {
                if ($nullCounter === $blockToFind) {
                    array_splice($this->innerContent, $i + 1, 0, [null]);

                    return $this;
                }
                ++$nullCounter;
            }
        }

        return $this;
    }

    public function setInnerContent(string $html): self
    {
        $this->innerContent = [$html];
        $this->updateInnerContent();

        return $this;
    }

    /**
     * Wraps the inner content of the block with the provided HTML.
     *
     * @param string $html Opening HTML tag to wrap the inner content with.
     */
    public function wrapInnerContent(string $html): self
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $tagClosers = [];
        while ($processor->next_tag()) {
            $tagClosers[] = strtolower((string) $processor->get_tag());
        }

        if (count($tagClosers) === 0) {
            return $this;
        }

        array_unshift($this->innerContent, $html);

        foreach (array_reverse($tagClosers) as $tagName) {
            $this->innerContent[] = sprintf("</%s>", $tagName);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attrs;
    }

    /**
     * @return array<string, mixed>|string|int|float|bool|null
     */
    public function getAttribute(string $attribute): array|string|int|float|bool|null
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

    /**
     * @param int|float|string|bool|array<mixed>|null $value
     */
    public function setAttribute(string $attribute, int|float|string|bool|array|null $value): self
    {
        $this->attrs[$attribute] = $value;

        return $this;
    }

    public function removeAttribute(string $attribute): self
    {
        unset($this->attrs[$attribute]);

        return $this;
    }

    public function renameAttribute(string $prevAttribute, string $nextAttribute): self
    {
        if (!$this->hasAttribute($prevAttribute)) {
            return $this;
        }

        $this->setAttribute($nextAttribute, $this->getAttribute($prevAttribute));
        $this->removeAttribute($prevAttribute);

        return $this;
    }

    /**
     * @param array<string> $attributes
     *
     * @return void
     */
    public function removeAttributes(array $attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->removeAttribute($attribute);
        }

        return $this;
    }

    public function clearAttributes(): self
    {
        $this->attrs = [];

        return $this;
    }

    public function addClassName(string $className): self
    {
        $existingClassNames = $this->getAttribute('className') ?? '';
        $classNames = array_filter(array_merge(explode(' ', $existingClassNames), [$className]));
        $this->setAttribute('className', implode(' ', $classNames));

        return $this;
    }

    public function removeClassName(string $className): self
    {
        $existingClassNames = $this->getAttribute('className') ?? '';
        $classNames = array_filter(explode(' ', $existingClassNames), static fn (string $name): bool => $name !== $className);
        $this->setAttribute('className', implode(' ', $classNames));

        return $this;
    }

    public function getParent(): ?BlockNode
    {
        return $this->parent;
    }

    private function setParent(?BlockNode $parent): void
    {
        $this->parent = $parent;
        $this->depth = $parent instanceof BlockNode && !$parent->isRoot() ? $parent->getDepth() + 1 : 0;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function clearContent(): self
    {
        $this->innerHTML = '';
        $this->innerContent = [];

        return $this;
    }

    public function __toString(): string
    {
        if ($this->isRoot) {
            return serialize_blocks($this->toArray()['innerBlocks']);
        }

        return serialize_block($this->toArray());
    }

    public function toArray(): array
    {
        $this->normalizeInnerContent();

        return [
            'blockName' => $this->blockName,
            'attrs' => $this->attrs,
            'innerBlocks' => array_map(fn (BlockNode $block): array => $block->toArray(), $this->innerBlocks),
            'innerHTML' => $this->innerHTML,
            'innerContent' => $this->innerContent,
        ];
    }

    private function updateInnerContent(): void
    {
        $blockPlaceholders = array_filter($this->innerContent, static fn (?string $chunk): bool => $chunk === null);
        $innerBlockCount = count($this->innerBlocks);
        if (count($blockPlaceholders) === $innerBlockCount) {
            return;
        }

        foreach ($this->innerBlocks as $block) {
            array_push($this->innerContent, null);
        }
    }

    private function normalizeInnerContent(): void
    {
        if (count($this->innerContent) === 1) {
            $this->innerContent = ["\n" . trim($this->innerContent[0] ?? '', "\n") . "\n"];
            return;
        }

        $innerContent = [];
        foreach ($this->innerContent as $i => $chunk) {
            if ($chunk !== null) {
                // Add one line break before the first item if it doesn't start with a newline
                if (array_key_first($this->innerContent) === $i && !str_starts_with($chunk, "\n")) {
                    $innerContent[$i] = "\n" . $chunk;
                    continue;
                }

                // Add one line break after the last item if it doesn't end with a newline
                if (array_key_last($this->innerContent) === $i && !str_ends_with($chunk, "\n")) {
                    $innerContent[] = $chunk . "\n";
                    continue;
                }

                $innerContent[] = $chunk;
                continue;
            }

            $innerContent[] = null;
            // Check if next item exists and is also null and insert double line break if needed
            if (array_key_exists($i + 1, $this->innerContent) && $this->innerContent[$i + 1] === null) {
                $innerContent[] = "\n\n";
            }
        }

        $this->innerContent = $innerContent;
    }

    public static function create(array $parsedBlock, ?BlockNode $parent = null): static
    {
        return new static(...[...$parsedBlock, 'parent' => $parent]);
    }

    public static function createFromString(string $html, ?BlockNode $parent = null): static
    {
        $parsedBlock = parse_blocks($html);

        // Otherwise, we assume it's a single block.
        return static::create($parsedBlock[0], $parent);
    }

    public static function createRoot(string $html): static
    {
        return static::create([
            'blockName' => self::ROOT_BLOCK_NAME,
            'innerBlocks' => parse_blocks($html),
        ]);
    }
}

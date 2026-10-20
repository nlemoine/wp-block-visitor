<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use InvalidArgumentException;
use LogicException;
use OutOfRangeException;
use RuntimeException;
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
 * `innerHTML` is not stored: like the WordPress parser, it is the concatenation of the HTML
 * chunks of `innerContent`, so it never goes stale. A visitor exception leaves the tree in an
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
     * Separator Gutenberg emits between two sibling blocks, used when a list has none to copy.
     */
    private const string BLOCK_SEPARATOR = "\n\n";

    /**
     * Block names that can be written into a delimiter without breaking out of it, the same
     * set the WordPress block parser accepts.
     */
    private const string BLOCK_NAME_PATTERN = '#^[a-z][a-z0-9_-]*(/[a-z][a-z0-9_-]*)?\z#';

    /**
     * HTML elements that never have a closing tag.
     *
     * @see https://html.spec.whatwg.org/multipage/syntax.html#void-elements
     */
    private const array VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * @var list<BlockNode>
     */
    private array $innerBlocks;

    /**
     * @var list<string|null>
     */
    private array $innerContent;

    private ?BlockNode $parent = null;

    /**
     * Bumped by every change to the children list, so the traverser can tell in O(1)
     * whether a hook touched it.
     */
    private int $childrenRevision = 0;

    /**
     * A child that already has a parent is moved here, as with the child mutators.
     *
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>|BlockNode> $innerBlocks
     * @param list<string|null> $innerContent
     *
     * @throws InvalidArgumentException When the name or the attributes would not survive serialization.
     * @throws LogicException When the same node is listed twice.
     */
    public function __construct(
        private ?string $blockName = null,
        private array $attrs = [],
        array $innerBlocks = [],
        array $innerContent = [],
    ) {

        $this->assertBlockName($blockName);
        $this->assertAttributes($attrs);

        $this->innerContent = \array_values($innerContent);
        $this->innerBlocks = $this->toNodes(\array_values($innerBlocks));
        $this->assertDistinct($this->innerBlocks);

        // No cycle check needed: a node under construction cannot be anyone's descendant yet.
        foreach ($this->innerBlocks as $node) {
            $this->adopt($node);
        }

        $this->reconcilePlaceholders();
    }

    /**
     * Without `innerContent`, an `innerHTML` key is taken as the only HTML chunk, so a
     * hand-written array with just `innerHTML` keeps its markup.
     *
     * @param array<string, mixed> $parsedBlock A `parse_blocks()` array, see the `ParsedBlock` shape.
     */
    public static function create(array $parsedBlock): self
    {
        /** @var ParsedBlock $parsedBlock */
        $html = $parsedBlock['innerHTML'] ?? '';

        return new self(
            $parsedBlock['blockName'] ?? null,
            $parsedBlock['attrs'] ?? [],
            $parsedBlock['innerBlocks'] ?? [],
            $parsedBlock['innerContent'] ?? ($html === '' ? [] : [$html]),
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
    public static function createFromString(string $html): self
    {
        $parsedBlocks = \trim($html) === '' ? [] : \parse_blocks($html);
        if ($parsedBlocks === []) {
            throw new InvalidArgumentException('No block found in the given markup.');
        }

        foreach ($parsedBlocks as $parsedBlock) {
            if ($parsedBlock['blockName'] !== null) {
                return self::create($parsedBlock);
            }
        }

        return self::create($parsedBlocks[0]);
    }

    /**
     * Creates a root node holding every top-level block of a document.
     *
     * The parser keeps the whitespace between top-level blocks as freeform blocks, so the
     * root content starts as bare placeholders and serializes back byte for byte.
     */
    public static function createRoot(string $html): self
    {
        $parsedBlocks = \parse_blocks($html);

        return self::create([
            'blockName' => self::ROOT_BLOCK_NAME,
            'innerBlocks' => $parsedBlocks,
            'innerContent' => \array_fill(0, \count($parsedBlocks), null),
        ]);
    }

    /**
     * Creates a new block wrapping the given node.
     *
     * Unlike the constructor, this leaves the child listed by its current parent until the
     * wrapper takes its place: returned from `leave()`, the traverser makes the swap. Outside
     * a visitor, make it yourself with `replaceInnerBlockAt()`, otherwise the child is listed
     * twice.
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

        // Filled by hand because the constructor would take the child away from its parent,
        // and the traverser rejects a hook that changed the parent's children.
        $wrapper = new self($blockName, $attrs);
        $wrapper->innerBlocks = [$child];
        $wrapper->innerContent = $innerContent;
        $child->parent = $wrapper;

        return $wrapper;
    }

    public function getBlockName(): ?string
    {
        return $this->blockName;
    }

    /**
     * @throws InvalidArgumentException When the name is invalid or is the root name.
     */
    public function setBlockName(string $newBlockName): self
    {
        if ($newBlockName === self::ROOT_BLOCK_NAME) {
            throw new InvalidArgumentException(\sprintf(
                'Cannot rename a block to "%s": that would drop its delimiters, use BlockNode::createRoot() for a document root.',
                self::ROOT_BLOCK_NAME,
            ));
        }

        $this->assertBlockName($newBlockName);

        $this->blockName = $newBlockName;

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->blockName === self::ROOT_BLOCK_NAME;
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
     * Number of changes made to the children list so far.
     *
     * @internal Lets the traverser tell whether a hook touched the children of an ancestor.
     */
    public function getChildrenRevision(): int
    {
        return $this->childrenRevision;
    }

    /**
     * Replaces the child at the given index by zero, one or more nodes.
     *
     * Zero nodes removes the child and its placeholder, one node swaps it in place,
     * several nodes expand it into siblings joined by the separator of the list. A replacement
     * that is already another child of this node is moved, not duplicated.
     */
    public function replaceInnerBlockAt(int $index, BlockNode ...$replacements): self
    {
        $count = \count($this->innerBlocks);
        if ($index < 0 || $index >= $count) {
            throw new OutOfRangeException(\sprintf('No inner block at index %d, valid indexes are 0 to %d.', $index, $count - 1));
        }

        $replacements = \array_values($replacements);
        $this->assertDistinct($replacements);
        foreach ($replacements as $node) {
            $this->assertAdoptable($node);
        }

        $old = $this->innerBlocks[$index];
        $index = $this->removeOwnChildren($replacements, $index, $old);
        $this->spliceChild($index, $replacements);
        $this->childrenRevision++;

        if (!\in_array($old, $replacements, true)) {
            $this->detach($old);
        }
        foreach ($replacements as $node) {
            $this->adopt($node);
        }

        return $this;
    }

    /**
     * Replaces several children at once, in a single pass over the lists.
     *
     * Gives the same result as calling `replaceInnerBlockAt()` for each entry from the highest
     * index down, without its quadratic cost on a node with many children. Unlike it, a
     * replacement cannot be another child of this node.
     *
     * @internal Used by the traverser to apply what `leave()` returned.
     *
     * @param array<int, list<BlockNode>> $replacements Replacement nodes, keyed by the index of the child they replace.
     *
     * @throws LogicException When a replacement is another child of this node, or appears twice.
     */
    public function applyReplacements(array $replacements): self
    {
        if ($replacements === []) {
            return $this;
        }

        $adopted = $this->assertReplacements($replacements);
        $this->innerContent = $this->replacedContent($replacements);

        $old = $this->innerBlocks;
        $this->innerBlocks = [];
        foreach ($old as $index => $node) {
            if (!\array_key_exists($index, $replacements)) {
                $this->innerBlocks[] = $node;
                continue;
            }

            \array_push($this->innerBlocks, ...$replacements[$index]);
            if (!\in_array($node, $replacements[$index], true)) {
                $this->detach($node);
            }
        }
        $this->childrenRevision++;

        foreach ($adopted as $node) {
            $this->adopt($node);
        }

        return $this;
    }

    /**
     * Inserts nodes before the child at the given index, or at the end when the index equals the count.
     *
     * A node that is already a child of this node is moved, not duplicated.
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

        $this->assertDistinct($nodes);
        foreach ($nodes as $node) {
            $this->assertAdoptable($node);
        }

        $index = $this->removeOwnChildren($nodes, $index);
        $count = \count($this->innerBlocks);
        $separator = $this->siblingSeparator();

        if ($count === 0) {
            $position = $this->emptyContainerPosition(true);
            $run = $this->placeholderRun(\count($nodes), $separator, false, false);
        } elseif ($index === $count) {
            $position = $this->placeholderPosition($count - 1) + 1;
            $run = $this->placeholderRun(\count($nodes), $separator, true, false);
        } else {
            $position = $this->placeholderPosition($index);
            $run = $this->placeholderRun(\count($nodes), $separator, false, true);
        }

        \array_splice($this->innerContent, $position, 0, $run);
        \array_splice($this->innerBlocks, $index, 0, $nodes);
        $this->childrenRevision++;

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
        $this->assertDistinct($new);
        foreach ($new as $node) {
            $this->assertAdoptable($node);
        }

        $old = $this->innerBlocks;

        if (\count($new) !== \count($old)) {
            $positions = \array_keys($this->innerContent, null, true);
            if ($positions === []) {
                $start = $this->emptyContainerPosition(true);
                $length = 0;
            } else {
                $start = $positions[0];
                $length = \end($positions) - $start + 1;
            }

            $run = $this->placeholderRun(\count($new), $this->siblingSeparator(), false, false);
            \array_splice($this->innerContent, $start, $length, $run);
        }

        $this->innerBlocks = $new;
        $this->childrenRevision++;

        $kept = [];
        foreach ($new as $node) {
            $kept[\spl_object_id($node)] = true;
        }
        foreach ($old as $node) {
            if (!isset($kept[\spl_object_id($node)])) {
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

    /**
     * The HTML chunks of the content joined together, as the WordPress parser builds it.
     */
    public function getInnerHTML(): string
    {
        return \implode('', \array_filter($this->innerContent, \is_string(...)));
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
     * Wraps the content with the given opening HTML; closing tags are generated for the
     * elements it leaves open.
     */
    public function wrapInnerContent(string $html): self
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $open = [];
        $found = false;
        while ($processor->next_tag(['tag_closers' => 'visit'])) {
            $tagName = \strtolower((string) $processor->get_tag());

            if ($processor->is_tag_closer()) {
                // A closer also ends the elements still open inside the one it closes.
                $index = \array_search($tagName, \array_reverse($open, true), true);
                if ($index !== false) {
                    \array_splice($open, $index);
                }
                continue;
            }

            $found = true;
            if (!$processor->has_self_closing_flag() && !\in_array($tagName, self::VOID_ELEMENTS, true)) {
                $open[] = $tagName;
            }
        }

        if (!$found) {
            return $this;
        }

        \array_unshift($this->innerContent, $html);

        foreach (\array_reverse($open) as $tagName) {
            $this->innerContent[] = \sprintf('</%s>', $tagName);
        }

        return $this;
    }

    /**
     * Drops every HTML chunk, keeping the placeholders of the children and the separator between them.
     */
    public function clearContent(): self
    {
        $this->innerContent = $this->placeholderRun(\count($this->innerBlocks), $this->siblingSeparator(), false, false);

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
        $this->assertAttributes($attributes);

        $this->attrs = $attributes;

        return $this;
    }

    public function setAttribute(string $attribute, mixed $value): self
    {
        $attributes = $this->attrs;
        $attributes[$attribute] = $value;
        $this->assertAttributes($attributes);

        $this->attrs = $attributes;

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
            'innerHTML' => $this->getInnerHTML(),
            'innerContent' => $this->innerContent,
        ];
    }

    /**
     * Serializes like `serialize_block()`, straight from the nodes.
     */
    public function __toString(): string
    {
        $content = '';
        $index = 0;
        foreach ($this->innerContent as $chunk) {
            $content .= $chunk ?? (string) $this->innerBlocks[$index++];
        }

        if ($this->isRoot()) {
            return $content;
        }

        if ($this->attrs !== [] && \serialize_block_attributes($this->attrs) === '') {
            throw new RuntimeException(\sprintf(
                'Cannot serialize the attributes of block "%s": they hold a value JSON cannot encode.',
                $this->blockName ?? '(freeform)',
            ));
        }

        return (string) \get_comment_delimited_block_content($this->blockName, $this->attrs, $content);
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
            static fn (array|BlockNode $block): BlockNode => $block instanceof BlockNode ? $block : self::create($block),
            $blocks
        ));
    }

    /**
     * @throws InvalidArgumentException When the name would not survive a serialize and reparse.
     */
    private function assertBlockName(?string $blockName): void
    {
        if ($blockName === null || $blockName === self::ROOT_BLOCK_NAME) {
            return;
        }

        if (\preg_match(self::BLOCK_NAME_PATTERN, $blockName) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid block name "%s": expected a lowercase name such as "paragraph" or "core/paragraph".',
                $blockName,
            ));
        }
    }

    /**
     * WordPress writes the attributes into the delimiter as JSON, and its parser only accepts an
     * object there. A non-empty list would encode as `["a"]` and swallow the blocks that follow.
     *
     * @param array<string, mixed> $attributes
     *
     * @throws InvalidArgumentException
     */
    private function assertAttributes(array $attributes): void
    {
        if ($attributes !== [] && \array_is_list($attributes)) {
            throw new InvalidArgumentException('Block attributes must be a map, a list would not survive serialization.');
        }
    }

    /**
     * Callers check `assertAdoptable()` first, before changing anything.
     */
    private function adopt(BlockNode $node): BlockNode
    {
        // A block belongs to one parent, so attaching it here takes it away from its former one.
        // Without this, moving a block would silently duplicate it: it would stay in its former
        // parent's children while its own pointer names the new one.
        if ($node->parent instanceof self && $node->parent !== $this) {
            $index = $node->parent->indexOf($node);
            if ($index !== null) {
                $node->parent->removeInnerBlockAt($index);
            }
        }

        $node->parent = $this;

        return $node;
    }

    /**
     * Rejects a child that would close a cycle, that is one whose subtree already holds this node.
     *
     * Called before any mutation so that a refused node never leaves the tree half-changed. The
     * check walks children rather than parents because a parent pointer only records one of the
     * lists a node may sit in, while the children lists are what gets serialized.
     *
     * @throws LogicException
     */
    private function assertAdoptable(BlockNode $node): void
    {
        $pending = [$node];
        while ($pending !== []) {
            $current = \array_pop($pending);
            if ($current === $this) {
                throw new LogicException(\sprintf(
                    'Cannot add block "%s" to "%s": that would close a cycle, the block already holds it.',
                    $node->getBlockName() ?? '(freeform)',
                    $this->getBlockName() ?? '(freeform)',
                ));
            }

            foreach ($current->innerBlocks as $child) {
                $pending[] = $child;
            }
        }
    }

    /**
     * Rejects a list holding the same node twice: it would serialize twice and share every change.
     *
     * @param list<BlockNode> $nodes
     *
     * @throws LogicException
     */
    private function assertDistinct(array $nodes): void
    {
        $seen = [];
        foreach ($nodes as $node) {
            $id = \spl_object_id($node);
            if (isset($seen[$id])) {
                throw new LogicException(\sprintf(
                    'Cannot add block "%s" to "%s" twice: a block appears at most once in the tree.',
                    $node->getBlockName() ?? '(freeform)',
                    $this->getBlockName() ?? '(freeform)',
                ));
            }
            $seen[$id] = true;
        }
    }

    /**
     * Puts the given nodes in place of the child at the given index, in both lists.
     *
     * @param list<BlockNode> $replacements
     */
    private function spliceChild(int $index, array $replacements): void
    {
        if (\count($replacements) === 1) {
            // A swap leaves the placeholder where it is, and overwriting an existing index keeps a list.
            $innerBlocks = $this->innerBlocks;
            $innerBlocks[$index] = $replacements[0];
            /** @var list<BlockNode> $innerBlocks */
            $this->innerBlocks = $innerBlocks;

            return;
        }

        $position = $this->placeholderPosition($index);
        $separator = $this->siblingSeparator();
        \array_splice($this->innerBlocks, $index, 1, $replacements);

        if ($replacements === []) {
            $this->removePlaceholderAt($position);

            return;
        }

        \array_splice($this->innerContent, $position, 1, $this->placeholderRun(\count($replacements), $separator, false, false));
    }

    /**
     * Checks a batch for `applyReplacements()` before anything changes.
     *
     * @param array<int, list<BlockNode>> $replacements
     * @return list<BlockNode> Every replacement node.
     *
     * @throws OutOfRangeException|LogicException
     */
    private function assertReplacements(array $replacements): array
    {
        $count = \count($this->innerBlocks);
        $adopted = [];
        foreach ($replacements as $index => $nodes) {
            if ($index < 0 || $index >= $count) {
                throw new OutOfRangeException(\sprintf('No inner block at index %d, valid indexes are 0 to %d.', $index, $count - 1));
            }

            foreach ($nodes as $node) {
                if ($node->parent === $this && $node !== $this->innerBlocks[$index]) {
                    throw new LogicException(\sprintf(
                        'Cannot replace child %d of "%s" by one of its siblings, use replaceInnerBlockAt() to move a child.',
                        $index,
                        $this->getBlockName() ?? '(freeform)',
                    ));
                }
                $this->assertAdoptable($node);
                $adopted[] = $node;
            }
        }
        $this->assertDistinct($adopted);

        return $adopted;
    }

    /**
     * The content once the given replacements are applied.
     *
     * Walked from the end so that, as when replacing one by one from the highest index,
     * everything on the right is already final when a placeholder is removed, and everything
     * on the left is untouched.
     *
     * @param array<int, list<BlockNode>> $replacements
     * @return list<string|null>
     */
    private function replacedContent(array $replacements): array
    {
        $reversed = [];
        $child = \count($this->innerBlocks);
        // As one by one, an expansion copies the separator between the first two children. Only
        // child 0 can see that pair changed, by the replacements applied after it.
        $separator = $this->siblingSeparator();
        for ($position = \count($this->innerContent) - 1; $position >= 0; $position--) {
            $chunk = $this->innerContent[$position];
            if ($chunk !== null || !\array_key_exists(--$child, $replacements)) {
                $reversed[] = $chunk;
                continue;
            }

            if ($replacements[$child] !== []) {
                \array_push($reversed, ...$this->reversedExpansion($child, \count($replacements[$child]), $reversed, $separator));
                continue;
            }

            // Removal: drop the separator that joined the placeholder to a sibling, as removePlaceholderAt() does.
            $top = \count($reversed) - 1;
            if ($top >= 1 && $this->isSeparator($reversed[$top]) && $reversed[$top - 1] === null) {
                \array_pop($reversed);
                continue;
            }
            if ($position >= 2 && $this->isSeparator($this->innerContent[$position - 1]) && $this->innerContent[$position - 2] === null) {
                $position--;
            }
        }

        return \array_reverse($reversed);
    }

    /**
     * The placeholders of an expansion in `replacedContent()`, reversed like its output.
     *
     * @param list<string|null> $reversed
     * @return list<string|null>
     */
    private function reversedExpansion(int $child, int $count, array $reversed, string $separator): array
    {
        if ($child === 0) {
            $separator = $this->separatorAfterFirst($reversed);
        }

        return \array_reverse($this->placeholderRun($count, $separator, false, false));
    }

    /**
     * Takes out the given nodes that are already children of this node, so that adding them
     * back moves them instead of listing them twice.
     *
     * @param list<BlockNode> $nodes
     * @param BlockNode|null $kept The child being replaced, which stays where it is.
     * @return int The index, shifted by the children taken out before it.
     */
    private function removeOwnChildren(array $nodes, int $index, ?BlockNode $kept = null): int
    {
        foreach ($nodes as $node) {
            if ($node === $kept || $node->parent !== $this) {
                continue;
            }

            $from = $this->indexOf($node);
            if ($from === null) {
                continue;
            }

            $this->removeInnerBlockAt($from);
            if ($from < $index) {
                $index--;
            }
        }

        return $index;
    }

    /**
     * Forgets the parent of a node that was taken out of the children.
     *
     * A node is listed once at most, so it is enough to check that it still points here: a
     * child handed to `wrap()` points to its wrapper and must keep it.
     */
    private function detach(BlockNode $node): void
    {
        if ($node->parent === $this) {
            $node->parent = null;
        }
    }

    /**
     * Position in `innerContent` of the placeholder of the child at the given index.
     *
     * @param int|null $total Number of placeholders, when it is not yet the number of children.
     */
    private function placeholderPosition(int $index, ?int $total = null): int
    {
        $count = $total ?? \count($this->innerBlocks);

        // Scanning from the nearer end keeps appending and removing in reverse order linear
        // on nodes with many children, where scanning from the start is quadratic.
        if ($index * 2 < $count) {
            $seen = -1;
            foreach ($this->innerContent as $position => $chunk) {
                if ($chunk === null && ++$seen === $index) {
                    return $position;
                }
            }
        } else {
            $seen = $count;
            for ($position = \count($this->innerContent) - 1; $position >= 0; $position--) {
                if ($this->innerContent[$position] === null && --$seen === $index) {
                    return $position;
                }
            }
        }

        throw new LogicException(\sprintf('innerContent and innerBlocks are out of sync: no placeholder for child %d.', $index));
    }

    /**
     * Where placeholders go in a node without children.
     *
     * A container the parser left empty keeps its opening and closing markup in a single chunk.
     * When inserting children, `$split` cuts that chunk before its last closing tag; otherwise
     * the children would serialize after the wrapper instead of inside it. Content set by hand
     * is taken as given and not split.
     */
    private function emptyContainerPosition(bool $split): int
    {
        $count = \count($this->innerContent);

        if ($split && $count === 1) {
            $chunk = (string) $this->innerContent[0];
            $position = $this->lastClosingTagPosition($chunk);
            if ($position !== null && $position > 0) {
                \array_splice($this->innerContent, 0, 1, [
                    \substr($chunk, 0, $position),
                    \substr($chunk, $position),
                ]);

                return 1;
            }
        }

        return $count >= 2 ? $count - 1 : $count;
    }

    /**
     * Offset of the last closing tag of a chunk, or null when it has none.
     */
    private function lastClosingTagPosition(string $html): ?int
    {
        // The processor knows where each tag starts but only exposes it through a bookmark, which
        // subclasses can read. Searching the text instead would also match inside comments.
        $processor = new class ($html) extends WP_HTML_Tag_Processor {
            public function tokenOffset(): ?int
            {
                return $this->set_bookmark('token') ? $this->bookmarks['token']->start : null;
            }
        };

        $position = null;
        while ($processor->next_tag(['tag_closers' => 'visit'])) {
            if ($processor->is_tag_closer()) {
                $position = $processor->tokenOffset();
            }
        }

        return $position;
    }

    private function isSeparator(?string $chunk): bool
    {
        return \is_string($chunk) && \trim($chunk) === '';
    }

    /**
     * Builds `[SEP?] null (SEP null)* [SEP?]`, without any chunk when the separator is empty.
     *
     * @return list<string|null>
     */
    private function placeholderRun(int $count, string $separator, bool $leadingSeparator, bool $trailingSeparator): array
    {
        if ($count <= 0) {
            return [];
        }

        $glue = $separator === '' ? [] : [$separator];
        $run = $leadingSeparator ? $glue : [];
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                \array_push($run, ...$glue);
            }
            $run[] = null;
        }
        if ($trailingSeparator) {
            \array_push($run, ...$glue);
        }

        return $run;
    }

    /**
     * The separator a new sibling gets: the one already used between the first two children.
     *
     * Adjacent placeholders mean the list uses none, and a single whitespace chunk is copied as
     * is. Markup between them, or fewer than two children, falls back to the blank line
     * Gutenberg emits. A root always uses the blank line, since the parser keeps the whitespace
     * between top-level blocks as freeform blocks rather than chunks.
     */
    private function siblingSeparator(): string
    {
        if ($this->isRoot()) {
            return self::BLOCK_SEPARATOR;
        }

        $between = null;
        foreach ($this->innerContent as $chunk) {
            if ($chunk !== null) {
                if ($between !== null) {
                    $between[] = $chunk;
                }
                continue;
            }

            if ($between !== null) {
                return $this->separatorFrom($between);
            }
            $between = [];
        }

        return self::BLOCK_SEPARATOR;
    }

    /**
     * `siblingSeparator()` for child 0 in the middle of `replacedContent()`, read from the
     * content already rebuilt on its right, which is stored reversed.
     *
     * @param list<string|null> $reversed
     */
    private function separatorAfterFirst(array $reversed): string
    {
        if ($this->isRoot()) {
            return self::BLOCK_SEPARATOR;
        }

        $between = [];
        for ($i = \count($reversed) - 1; $i >= 0; $i--) {
            $chunk = $reversed[$i];
            if ($chunk === null) {
                return $this->separatorFrom($between);
            }
            $between[] = $chunk;
        }

        return self::BLOCK_SEPARATOR;
    }

    /**
     * @param list<string> $between The chunks between two consecutive placeholders.
     */
    private function separatorFrom(array $between): string
    {
        if ($between === []) {
            return '';
        }

        return \count($between) === 1 && $this->isSeparator($between[0]) ? $between[0] : self::BLOCK_SEPARATOR;
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
            $this->isSeparator($right)
            && \array_key_exists($position + 1, $this->innerContent)
            && $this->innerContent[$position + 1] === null
        ) {
            \array_splice($this->innerContent, $position, 1);

            return;
        }

        if (
            $position >= 2
            && $this->isSeparator($left)
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
                $this->removePlaceholderAt($this->placeholderPosition($i - 1, $i));
            }

            return;
        }

        $missing = $blocks - $placeholders;
        $separator = $this->siblingSeparator();
        if ($placeholders === 0) {
            \array_splice($this->innerContent, $this->emptyContainerPosition(false), 0, $this->placeholderRun($missing, $separator, false, false));

            return;
        }

        $position = $this->placeholderPosition($placeholders - 1, $placeholders) + 1;
        \array_splice($this->innerContent, $position, 0, $this->placeholderRun($missing, $separator, true, false));
    }
}

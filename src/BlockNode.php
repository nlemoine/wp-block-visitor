<?php

declare(strict_types=1);

namespace n5s\BlockVisitor;

use InvalidArgumentException;
use LogicException;
use n5s\BlockVisitor\Internal\Placeholders;
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
    private array $innerBlocks = [];

    /**
     * @var list<string|null>
     */
    private array $innerContent;

    private ?BlockNode $parent = null;

    /**
     * How many traversals are visiting the children right now. While it is above zero, the
     * children list cannot change, so a visitor touching an ancestor fails at its own call.
     * A counter rather than a flag, so that nested traversals of the same node unlock in turn.
     */
    private int $childrenLocks = 0;

    /**
     * A child that already has a parent is moved here, as with the child mutators.
     *
     * @param array<string, mixed> $attrs
     * @param list<array<string, mixed>|BlockNode> $innerBlocks
     * @param list<string|null> $innerContent
     *
     * @throws InvalidArgumentException When the name would not survive serialization.
     * @throws LogicException When the same node is listed twice.
     */
    public function __construct(
        private ?string $blockName = null,
        private array $attrs = [],
        array $innerBlocks = [],
        array $innerContent = [],
    ) {

        $this->assertBlockName($blockName);

        $this->innerContent = \array_is_list($innerContent) ? $innerContent : \array_values($innerContent);

        // Only nodes passed as such can be listed twice or belong to another parent; the ones
        // built here from arrays are new, which is every node of a parsed document.
        $given = [];
        foreach ($innerBlocks as $block) {
            if ($block instanceof self) {
                $this->innerBlocks[] = $block;
                $given[] = $block;
                continue;
            }

            $node = self::create($block);
            $node->parent = $this;
            $this->innerBlocks[] = $node;
        }

        if ($given !== []) {
            $this->assertDistinct($given);

            // A node under construction cannot be anyone's descendant yet, so this only checks
            // the lock on each node's current parent, for all of them before any is taken.
            foreach ($given as $node) {
                $this->assertAdoptable($node);
            }
            foreach ($given as $node) {
                $this->adopt($node);
            }
        }

        $this->innerContent = Placeholders::reconcile($this->innerContent, \count($this->innerBlocks), $this->isRoot());
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
     * Creates a new block wrapping the given node, in that node's place.
     *
     * Outside a traversal, the wrapper takes the child's place in its parent right away. From a
     * visitor, the children of the parent are locked, so the child stays listed there until the
     * wrapper is returned from `leave()`, where the traverser makes the swap: wrap only the node
     * being left.
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

        // Filled by hand: the constructor would take the child away from its parent, which the
        // lock a visitor holds on that parent forbids.
        $wrapper = new self($blockName, $attrs);
        $wrapper->innerBlocks = [$child];
        $wrapper->innerContent = $innerContent;

        $parent = $child->parent;
        if ($parent instanceof self && $parent->childrenLocks === 0) {
            $index = $parent->indexOf($child);
            if ($index !== null) {
                $parent->replaceInnerBlockAt($index, $wrapper);
            }
        }
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
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException(\sprintf(
                'Cannot rename a block to "%s": that would drop its delimiters, use BlockNode::createRoot() for a document root.',
                self::ROOT_BLOCK_NAME,
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
     * Forbids any change to the children list until the matching `unlockChildren()`.
     *
     * @internal Called by the traverser around the visit of the children.
     */
    public function lockChildren(): void
    {
        $this->childrenLocks++;
    }

    /**
     * @internal Called by the traverser once the children have been visited.
     *
     * @throws LogicException When there is no lock to release, so a stray call cannot wedge the node.
     */
    public function unlockChildren(): void
    {
        if ($this->childrenLocks === 0) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new LogicException(\sprintf(
                'Cannot unlock the children of "%s": they are not locked.',
                $this->getBlockName() ?? '(freeform)',
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->childrenLocks--;
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
        $this->assertUnlocked();
        $count = \count($this->innerBlocks);
        if ($index < 0 || $index >= $count) {
            throw new OutOfRangeException(\sprintf('No inner block at index %d, valid indexes are 0 to %d.', $index, $count - 1)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $replacements = \array_values($replacements);
        $this->assertDistinct($replacements);
        foreach ($replacements as $node) {
            $this->assertAdoptable($node);
        }

        $old = $this->innerBlocks[$index];
        $index = $this->removeOwnChildren($replacements, $index, $old);
        $this->spliceChild($index, $replacements);

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
        // Before the lock check: a nested pass over a locked ancestor has nothing to apply.
        if ($replacements === []) {
            return $this;
        }

        $this->assertUnlocked();
        $adopted = $this->assertReplacements($replacements);
        $counts = \array_map(\count(...), $replacements);
        $this->innerContent = Placeholders::replaceMany($this->innerContent, $counts, \count($this->innerBlocks), $this->isRoot());

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
        $nodes = \array_values($nodes);
        if ($nodes === []) {
            return $this;
        }

        $this->assertUnlocked();
        $count = \count($this->innerBlocks);
        if ($index < 0 || $index > $count) {
            throw new OutOfRangeException(\sprintf('Cannot insert at index %d, valid indexes are 0 to %d.', $index, $count)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->assertDistinct($nodes);
        foreach ($nodes as $node) {
            $this->assertAdoptable($node);
        }

        $index = $this->removeOwnChildren($nodes, $index);
        $count = \count($this->innerBlocks);
        if ($count === 0) {
            $this->splitEmptyContainer();
        }

        Placeholders::insert($this->innerContent, $index, \count($nodes), $count, $this->isRoot());
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
        $this->assertUnlocked();
        $new = $this->toNodes($innerBlocks);
        $this->assertDistinct($new);
        foreach ($new as $node) {
            $this->assertAdoptable($node);
        }

        $old = $this->innerBlocks;

        if (\count($new) !== \count($old)) {
            if ($old === []) {
                $this->splitEmptyContainer();
            }
            Placeholders::respan($this->innerContent, \count($new), $this->isRoot());
        }

        $this->innerBlocks = $new;

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
        $this->innerContent = Placeholders::reconcile($this->innerContent, \count($this->innerBlocks), $this->isRoot());

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
        $separator = Placeholders::separator($this->innerContent, $this->isRoot());
        $this->innerContent = Placeholders::run(\count($this->innerBlocks), $separator, false, false);

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
        if ($from === $to || !$this->hasAttribute($from)) {
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
    //  Copy
    // -------------------------------------------------------------------------

    /**
     * A copy is a new subtree: its children are copies too, and it has no parent until it is
     * inserted somewhere. Without this, the copy would share its children with the original,
     * so a change to one would show in both and moving one child would break the other.
     */
    public function __clone()
    {
        $this->parent = null;
        // A copy made during a traversal is not part of it.
        $this->childrenLocks = 0;

        foreach ($this->innerBlocks as $index => $child) {
            $copy = clone $child;
            $copy->parent = $this;
            $this->innerBlocks[$index] = $copy;
        }
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

        if ($this->blockName === null || $this->isRoot()) {
            return $content;
        }

        // Writes what get_comment_delimited_block_content() does, which would encode the
        // attributes a second time after the check below.
        $attributes = '';
        if ($this->attrs !== []) {
            // The parser only reads a JSON object from the delimiter, and a list would encode as
            // `["a"]` and swallow the blocks that follow. Cast, it keeps its numeric keys: `{"0":"a"}`.
            $attributes = \serialize_block_attributes(\array_is_list($this->attrs) ? (object) $this->attrs : $this->attrs);
            if ($attributes === '') {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException(\sprintf(
                    'Cannot serialize the attributes of block "%s": they hold a value JSON cannot encode.',
                    $this->blockName,
                ));
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $attributes .= ' ';
        }

        $name = \strip_core_block_namespace($this->blockName);

        // WordPress tests the content with empty(), so "0" also makes a void block.
        if ($content === '' || $content === '0') {
            return \sprintf('<!-- wp:%s %s/-->', $name, $attributes);
        }

        return \sprintf('<!-- wp:%s %s-->%s<!-- /wp:%s -->', $name, $attributes, $content, $name);
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
     * Builds the nodes of a list mixing parsed arrays and nodes, checking every node first.
     *
     * A node nested in a parsed array is adopted by the node built from that array, so each one
     * is checked against this node before any array is built: a refused one would otherwise be
     * left inside a node that is thrown away.
     *
     * @param list<array<string, mixed>|BlockNode> $blocks
     * @return list<BlockNode>
     */
    private function toNodes(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }

        $this->assertUnlocked();
        foreach (self::givenNodes($blocks) as $node) {
            $this->assertAdoptable($node);
        }

        return \array_values(\array_map(
            static fn (array|BlockNode $block): BlockNode => $block instanceof BlockNode ? $block : self::create($block),
            $blocks
        ));
    }

    /**
     * The nodes passed as such in a list of parsed arrays and nodes, at any depth.
     *
     * @param array<int|string, mixed> $blocks
     * @return list<BlockNode>
     */
    private static function givenNodes(array $blocks): array
    {
        $given = [];
        foreach ($blocks as $block) {
            if ($block instanceof self) {
                $given[] = $block;
            } elseif (\is_array($block) && isset($block['innerBlocks']) && \is_array($block['innerBlocks'])) {
                \array_push($given, ...self::givenNodes($block['innerBlocks']));
            }
        }

        return $given;
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
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException(\sprintf(
                'Invalid block name "%s": expected a lowercase name such as "paragraph" or "core/paragraph".',
                $blockName,
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
     * Rejects a child that would close a cycle, that is one whose subtree already holds this
     * node, or that a visitor's lock keeps in its current parent.
     *
     * Called before any mutation so that a refused node never leaves the tree half-changed. The
     * cycle check walks children rather than parents because a parent pointer only records one
     * of the lists a node may sit in, while the children lists are what gets serialized.
     *
     * @throws LogicException
     */
    private function assertAdoptable(BlockNode $node): void
    {
        $pending = [$node];
        while ($pending !== []) {
            $current = \array_pop($pending);
            if ($current === $this) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new LogicException(\sprintf(
                    'Cannot add block "%s" to "%s": that would close a cycle, the block already holds it.',
                    $node->getBlockName() ?? '(freeform)',
                    $this->getBlockName() ?? '(freeform)',
                ));
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            foreach ($current->innerBlocks as $child) {
                $pending[] = $child;
            }
        }

        // Adopting takes the node from its parent, which is what the lock on that parent forbids.
        $parent = $node->parent;
        if ($parent instanceof self && $parent !== $this && $parent->childrenLocks > 0 && $parent->indexOf($node) !== null) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new LogicException(\sprintf(
                'Cannot move block "%s" out of "%s" while a visitor is visiting its children: return a copy or a new node instead.',
                $node->getBlockName() ?? '(freeform)',
                $parent->getBlockName() ?? '(freeform)',
            ));
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * @throws LogicException When a traversal is visiting the children.
     */
    private function assertUnlocked(): void
    {
        if ($this->childrenLocks === 0) {
            return;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        throw new LogicException(\sprintf(
            'Cannot change the children of "%s" while a visitor is visiting them. Visitors must not mutate ancestors or siblings; return a value from leave() instead.',
            $this->getBlockName() ?? '(freeform)',
        ));
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new LogicException(\sprintf(
                    'Cannot add block "%s" to "%s" twice: a block appears at most once in the tree.',
                    $node->getBlockName() ?? '(freeform)',
                    $this->getBlockName() ?? '(freeform)',
                ));
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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

        Placeholders::replace($this->innerContent, $index, \count($this->innerBlocks), \count($replacements), $this->isRoot());
        \array_splice($this->innerBlocks, $index, 1, $replacements);
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
        // A child wrapped from a hook points to its wrapper while still listed here; unless the
        // wrapper replaces it, both would serialize it.
        foreach ($this->innerBlocks as $index => $child) {
            if ($child->parent !== $this && !\array_key_exists($index, $replacements)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new LogicException(\sprintf(
                    'Child %d of "%s" is still listed but no longer belongs to it: wrap only the node being left, and return the wrapper from leave().',
                    $index,
                    $this->getBlockName() ?? '(freeform)',
                ));
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        $count = \count($this->innerBlocks);
        $adopted = [];
        foreach ($replacements as $index => $nodes) {
            if ($index < 0 || $index >= $count) {
                throw new OutOfRangeException(\sprintf('No inner block at index %d, valid indexes are 0 to %d.', $index, $count - 1)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            foreach ($nodes as $node) {
                if ($node->parent === $this && $node !== $this->innerBlocks[$index]) {
                    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    throw new LogicException(\sprintf(
                        'Cannot replace child %d of "%s" by one of its siblings, use replaceInnerBlockAt() to move a child.',
                        $index,
                        $this->getBlockName() ?? '(freeform)',
                    ));
                    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $this->assertAdoptable($node);
                $adopted[] = $node;
            }
        }
        $this->assertDistinct($adopted);

        return $adopted;
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
     * Prepares a node without children for its first ones.
     *
     * A container the parser left empty keeps its opening and closing markup in a single chunk,
     * so the chunk is cut before its last closing tag and the children land between the two
     * halves; otherwise they would serialize after the wrapper instead of inside it. Only called
     * when inserting: content set by hand is taken as given.
     */
    private function splitEmptyContainer(): void
    {
        if (\count($this->innerContent) !== 1) {
            return;
        }

        $chunk = (string) $this->innerContent[0];
        $position = $this->lastClosingTagPosition($chunk);
        if ($position !== null && $position > 0) {
            $this->innerContent = [\substr($chunk, 0, $position), \substr($chunk, $position)];
        }
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
}

# wp-block-visitor

Traverse and transform WordPress block trees with visitors. Parse post content once, walk every block depth-first, extract data or rewrite blocks in place, then serialize back to block markup. Untouched blocks are written back the way `serialize_blocks()` writes them, so a no-op traversal leaves content saved by the editor unchanged.

> [!WARNING]
> 🚧 Work in progress. The API may change without notice.

## Install

```sh
composer require n5s/wp-block-visitor
```

Requires PHP 8.3+ and the WordPress block functions (`parse_blocks`, `serialize_block`, `WP_HTML_Tag_Processor`) to be loaded.

## Usage

```php
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;

final class RenameButtonVisitor extends AbstractBlockVisitor
{
    public function leave(BlockNode $block): BlockNode|array|Traversal|null
    {
        if ($block->getBlockName() === 'vendor/button') {
            $block->setBlockName('core/button')->renameAttribute('href', 'url');
        }

        return null;
    }
}

$traverser = new BlockTraverser(new RenameButtonVisitor());
$root = $traverser->traverse($post->post_content);

$post->post_content = (string) $root;
```

`traverse()` accepts block markup or a `BlockNode` and returns the root node. Cast it to a string to get the serialized markup back.

## Visitors

A visitor implements `BlockVisitorInterface`, or extends `AbstractBlockVisitor` and overrides the hook it needs. Nodes are visited depth-first in document order: `enter()` is called before the children of a node, `leave()` after them. The root node is never visited.

```php
interface BlockVisitorInterface
{
    public function beforeTraverse(BlockNode $root): void;

    public function afterTraverse(BlockNode $root): void;

    public function enter(BlockNode $block): BlockNode|Traversal|null;

    public function leave(BlockNode $block): BlockNode|array|Traversal|null;
}
```

Return `null` to do nothing. This is the default for both node hooks in `AbstractBlockVisitor`, so a read-only visitor only overrides `enter()` and never returns anything else.

`beforeTraverse()` and `afterTraverse()` are called once per pass with the root, before the first node and after the last one. `afterTraverse()` also runs after `Traversal::Stop`, not after an exception. They are the place to reset or flush state a visitor keeps across documents; `AbstractBlockVisitor` implements both as no-ops.

### Mutation rules

Which mutation goes where is the whole point of the library, so here are the rules.

**The current node and its subtree** are mutated in place through the `BlockNode` API, without returning anything: attributes, content, children. Children added in `enter()` are visited afterwards; children added in `leave()` are not.

**The current node itself** is replaced, removed, expanded or wrapped through the return value of `leave()`:

| Return from `leave()` | Effect |
|---|---|
| `null` | nothing |
| a `BlockNode` | replaces the node |
| a list of `BlockNode` | replaces the node by zero or more siblings, e.g. `[$block, $newSibling]` inserts a sibling after it |
| `Traversal::Remove` | removes the node |
| `Traversal::Stop` | stops the traversal for this visitor |

Nodes returned from `leave()` are not visited again by the same visitor, so wrapping a block in a new parent never loops:

```php
public function leave(BlockNode $block): BlockNode|array|Traversal|null
{
    if ($block->getBlockName() !== 'core/paragraph') {
        return null;
    }

    return BlockNode::wrap($block, 'core/group', [], "\n<div class=\"wp-block-group\">", "</div>\n");
}
```

`wrap()` is for the node being left. From a hook, the wrapped node keeps its place and its parent until the traverser swaps the wrapper in from what `leave()` returned. A wrapper built around any other node, or built and not returned, can be used nowhere: every mutator rejects a node that holds a block still listed elsewhere. Outside a visitor, `wrap()` puts the wrapper in the child's place itself.

`enter()` can return a `BlockNode` to replace the current node before its children are visited, `Traversal::SkipChildren` to skip the subtree (`leave()` is still called), or `Traversal::Stop`. It cannot remove or expand: the traverser throws a `LogicException`. Since the children of a node returned from `enter()` are visited, returning `BlockNode::wrap($block, ...)` there visits `$block` again and wraps it forever: wrap from `leave()`.

`Traversal::Stop` ends the pass at once: `leave()` is not called for the nodes still open, and the values already returned for the lists being visited are applied.

**Ancestors and siblings** are never mutated from a visitor. While a node's children are visited, the traverser locks its children list: any call that would change it, including attaching one of those children somewhere else, throws a `LogicException` right where the visitor makes it, before anything changes. That covers removals, insertions, swaps and reorderings anywhere in the list, from the current node's hooks or from its descendants'. Changing the attributes or the content of an ancestor does not touch its children and is not rejected. To act on a parent based on what a child contains, record the fact in the visitor state during the child's visit and act in `leave()` of the parent.

A `leave()` return value is applied once the whole sibling list has been visited, so later siblings still observe the node it replaces. All the values returned for one sibling list are applied in a single pass, so removing every child of a wide parent stays linear. Attaching a block somewhere takes it away from its former parent, in one pass per former parent, so moving or unwrapping many children stays linear too. A block can never be added under itself or under one of its own descendants.

Exceptions thrown by a visitor propagate unchanged. That is the way to abort the traversal of a document with an error. The tree is then in an undefined state: replacements staged by earlier siblings are not applied, so discard it rather than serializing it. Every exception the library itself throws implements `n5s\BlockVisitor\Exception\ExceptionInterface` and extends the SPL class of the same name (`LogicException`, `InvalidArgumentException`, `OutOfRangeException`, `RuntimeException`), so a `catch` can tell a contract violation from your own abort.

Returning a node that is the current node's parent or one of its ancestors is rejected too, since it would create a cycle. So is returning a sibling of the current node, from either hook: moving it would shift the siblings still to visit. A list returned from `leave()` must only hold `BlockNode` instances.

### Multiple visitors

Each visitor gets its own full pass over the tree, so a visitor sees the result of the previous ones. Visitors run in registration order, unless they implement `PrioritizedVisitorInterface`: higher priority runs first.

```php
$traverser = new BlockTraverser($cleanupVisitor, $galleryVisitor, $treeVisitor);
```

## BlockNode

### Factories

```php
BlockNode::create($parsedBlock);                          // from a parse_blocks() array
BlockNode::createFromString('<!-- wp:paragraph -->...');  // first named block of the markup
BlockNode::createRoot($postContent);                      // root holding every top-level block
BlockNode::wrap($child, 'core/group', $attrs, $before, $after); // takes $child's place in its parent; from leave(), the traverser makes the swap
new BlockNode('core/group', $attrs, $children, $innerContent);  // children that have a parent are moved here
```

The `n5s\BlockVisitor\Internal` namespace only serves `BlockNode`; it is not part of the supported API and can change in any release. The traversal lock is not reachable from outside the library.

### Reading

```php
$block->getBlockName();
$block->getAttributes();
$block->getAttribute('className');
$block->hasAttribute('id');
$block->getClassNames();
$block->hasClassName('is-style-outline');
$block->getInnerBlocks();
$block->getInnerContent();      // HTML chunks, null marks the position of a child
$block->getInnerHTML();         // the HTML chunks of innerContent joined, as the parser builds it
$block->getParent();
$block->getDepth();             // top-level blocks are at depth 0
$block->isRoot();
$block->indexOf($child);
```

### Attributes

```php
$block->setBlockName('core/paragraph');
$block->setAttribute('id', 'intro');
$block->setAttributes(['id' => 'intro']);
$block->removeAttribute('id');
$block->removeAttributes(['id', 'anchor']);
$block->renameAttribute('anchorId', 'anchor');
$block->clearAttributes();
$block->addClassName('is-style-outline');
$block->removeClassName('is-style-fill');
```

### Children

Every child mutator keeps `innerContent` in sync: the HTML around the children is preserved, one placeholder per child is maintained, and parents are updated. Inserting into a container the parser left empty splits its markup so the block lands inside the wrapper rather than after it, and inside the inner `div` a cover, a media-text or a legacy group keeps for its blocks. A quote's citation and a gallery's caption come after the inner blocks in the editor's markup, which no rule on the closing tags can know: the first block inserted into an empty quote or gallery that already has one lands after it. A block appears at most once in the tree: inserting a node that is already a child of the same parent moves it, and listing the same node twice throws a `LogicException`. A root node cannot become a child: insert its inner blocks instead.

```php
$block->appendInnerBlock($child);
$block->appendInnerBlocks([$child, $parsedBlockArray]);
$block->prependInnerBlock($child);
$block->prependInnerBlocks([$child, $parsedBlockArray]);
$block->insertInnerBlockAt(1, $child);
$block->replaceInnerBlockAt(1, $a, $b);   // zero nodes removes, one replaces, more expand
$block->removeInnerBlockAt(1);
$block->setInnerBlocks([$a, $b]);        // same count: content untouched; otherwise keeps the HTML before the first child and after the last one
```

`clone $block` copies the whole subtree: the children are copies too, and the copy has no parent until it is inserted somewhere, so changing it never touches the original. Attribute values are copied as PHP copies arrays, so an object stored as an attribute value stays shared; the parser never produces one.

New siblings are joined with the separator the list already uses between its first two children: the same whitespace chunk, nothing when they are adjacent, and a blank line (`\n\n`) when the list has fewer than two children or markup between them. At the top level there is never one: WordPress keeps the blank lines between top-level blocks as freeform blocks, so a new top-level block is written right after the previous one, as `serialize_blocks()` does, and a reparse gives back the same blocks.

### Content

```php
$block->setInnerContent('<p>Hello</p>');                  // single chunk, placeholders appended after it
$block->setInnerContent(["\n<div>", null, "</div>\n"]);  // full chunk list
$block->wrapInnerContent('<div class="wp-block-group">'); // closing tags are generated for the elements left open
$block->clearContent();                                   // drops the HTML, keeps the placeholders
```

### Rejected input

Block names must look like `paragraph`, `core/paragraph` or `my_plugin/my_block`, the same names the WordPress parser reads; any other name raises `InvalidArgumentException`. `@root` names a root node, the one `createRoot()` builds, which can never become a child of another node (`LogicException`). Attribute values must be JSON-encodable, or serialization raises `RuntimeException`. Attributes shaped as a list, which is how the parser reads `{"0":"a"}`, are written back as a JSON object, so the delimiter still reparses as the same block. Each of those rules exists because the alternative is a delimiter that WordPress reparses as something else, swallowing the blocks that follow.

### Serialization

```php
(string) $block;    // block markup, or the whole document for a root node
$block->toArray();  // parse_blocks() shape
```

The `toArray()` of a root node is a block named `@root`; the `parse_blocks()` equivalent of the document is `$root->toArray()['innerBlocks']`.

## Examples

### Prime the attachment cache in one query

WordPress resolves each media block on render with its own `get_post()` call, one query per image, cover or video. Collecting the IDs first turns them into a single query. The visitor is not shipped with the package: copy [`examples/AttachmentIdVisitor.php`](examples/AttachmentIdVisitor.php) into your project under your own namespace.

```php

$visitor = new AttachmentIdVisitor();
(new BlockTraverser($visitor))->traverse($post->post_content);
$visitor->prime(); // _prime_post_caches() on every collected ID
```

The visitor knows the core media blocks and accepts a map of extra block names to ID attributes for custom blocks.

### Runnable demos

`examples/` contains runnable visitors, exposed as WP-CLI commands:

```sh
wp visitor tree --require=examples/cli.php     # print the block tree of a fixture
wp visitor depth --require=examples/cli.php    # print every block with its depth
wp visitor gallery --require=examples/cli.php  # expand a gallery into image blocks
wp visitor wrap --require=examples/cli.php     # wrap top-level paragraphs in groups
wp visitor remove --require=examples/cli.php   # remove every paragraph, print the tree
wp visitor ids --require=examples/cli.php      # collect the attachment IDs of a fixture
```

Run the tests before (`composer install && composer test`) so a WordPress instance can be found.

## Cost

Compared with `parse_blocks()` and WordPress 6.9's streaming `WP_Block_Processor` on a 2000-block document, PHP 8.5, Apple Silicon (`composer bench`):

| 2000 blocks | BlockTraverser | WP_Block_Processor | parse_blocks() |
|---|---|---|---|
| Read: collect attachment IDs | 15 ms, 15 MB | 13 ms, 8 MB | 10 ms, 14 MB |
| Write: add a class to every image | 17 ms, 15 MB | 17 ms, 9 MB | 13 ms, 16 MB |

Memory is the process peak, 7 MB of which is PHP itself. Of the 15 ms of the read, 8.4 ms are `parse_blocks()`, 2.8 ms build the tree and 2.2 ms visit its 3939 nodes, freeform whitespace blocks included.

**What you pay**: a block tree in memory, about the size of the `parse_blocks()` array, and, to read, about 10 percent more time than `WP_Block_Processor` and 45 percent more than `parse_blocks()`, for building the nodes and dispatching the visitor. To write, it costs the same as `WP_Block_Processor` and 35 percent more than `parse_blocks()`, since serializing straight from the nodes makes up for building them.

**What you get**: children can be inserted, removed, replaced or wrapped and `innerContent` stays consistent; untouched blocks come back as `serialize_blocks()` would write them; visitors compose in passes. `WP_Block_Processor` reads without building anything and is the right tool for scanning, but a modification is a span you splice into the source yourself. `parse_blocks()` gives you the tree and leaves the bookkeeping to you.

## Development

```sh
composer qa          # phpcs, phpstan, rector (dry run), phpunit
composer cs:fix      # fix coding standard violations
composer rector:fix  # apply rector rules
composer infection   # mutation testing, run on PHP 8.4
composer bench       # phpbench comparison with WP_Block_Processor and parse_blocks()
```

Tests load a real WordPress install backed by SQLite, so `parse_blocks()` and friends are the genuine core functions.

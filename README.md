# wp-block-visitor

Traverse and transform WordPress block trees with visitors. Parse post content once, walk every block depth-first, extract data or rewrite blocks in place, then serialize back to block markup. Untouched blocks serialize byte for byte, so a no-op traversal leaves post content unchanged.

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
    public function enter(BlockNode $block): BlockNode|Traversal|null;

    public function leave(BlockNode $block): BlockNode|array|Traversal|null;
}
```

Return `null` to do nothing. This is the default for both hooks in `AbstractBlockVisitor`, so a read-only visitor only overrides `enter()` and never returns anything else.

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

`enter()` can return a `BlockNode` to replace the current node before its children are visited, `Traversal::SkipChildren` to skip the subtree (`leave()` is still called), or `Traversal::Stop`. It cannot remove or expand: the traverser throws a `LogicException`.

**Ancestors and siblings** are never mutated from a visitor. The traverser checks that the current node is still attached to its parent after each hook and throws a `LogicException` otherwise. To act on a parent based on what a child contains, record the fact in the visitor state during the child's visit and act in `leave()` of the parent.

Exceptions thrown by a visitor propagate unchanged. That is the way to abort the traversal of a document with an error. The tree is then in an undefined state: replacements staged by earlier siblings are not applied, so discard it rather than serializing it.

Returning a node that is the current node's parent or one of its ancestors is rejected too, since it would create a cycle.

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
BlockNode::wrap($child, 'core/group', $attrs, $before, $after);
```

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
$block->getInnerHTML();         // as parsed; not updated by mutators, only clearContent() resets it
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

Every child mutator keeps `innerContent` in sync: the HTML around the children is preserved, one placeholder per child is maintained, and parents are updated.

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

### Content

```php
$block->setInnerContent('<p>Hello</p>');                  // single chunk, placeholders appended
$block->setInnerContent(["\n<div>", null, "</div>\n"]);  // full chunk list
$block->wrapInnerContent('<div class="wp-block-group">'); // closing tags are generated, void elements excepted
$block->clearContent();                                   // drops the HTML, keeps the placeholders
```

### Serialization

```php
(string) $block;    // block markup, or the whole document for a root node
$block->toArray();  // parse_blocks() shape
```

## Examples

`examples/` contains runnable visitors, exposed as WP-CLI commands:

```sh
wp visitor tree --require=examples/cli.php     # print the block tree of a fixture
wp visitor depth --require=examples/cli.php    # print every block with its depth
wp visitor gallery --require=examples/cli.php  # expand a gallery into image blocks
wp visitor wrap --require=examples/cli.php     # wrap top-level paragraphs in groups
wp visitor remove --require=examples/cli.php   # remove every paragraph, print the tree
```

Run the tests before (`composer install && composer test`) so a WordPress instance can be found.

## Development

```sh
composer qa          # phpcs, phpstan, rector (dry run), phpunit
composer cs:fix      # fix coding standard violations
composer rector:fix  # apply rector rules
composer infection   # mutation testing, run on PHP 8.4
```

Tests load a real WordPress install backed by SQLite, so `parse_blocks()` and friends are the genuine core functions.

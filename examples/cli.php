<?php

declare(strict_types=1);

use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Examples\AttachmentIdVisitor;
use n5s\BlockVisitor\Examples\DepthVisitor;
use n5s\BlockVisitor\Examples\GalleryVisitor;
use n5s\BlockVisitor\Examples\ParagraphRemoverVisitor;
use n5s\BlockVisitor\Examples\TreeVisitor;
use n5s\BlockVisitor\Examples\WrapInGroupVisitor;

require __DIR__ . '/deps.php';

WP_CLI::add_command('visitor', new class () {
    /**
     * Insert images into a gallery block.
     *
     * ## EXAMPLES
     *
     *     wp visitor gallery
     *
     * @when before_wp_load
     */
    public function gallery(): void
    {
        $content = '<!-- wp:vendor/gallery {"ids":[1,2,3],"align":"wide"} /-->';
        $visitor = new GalleryVisitor();
        $traverser = new BlockTraverser($visitor);

        $block = $traverser->traverse($content);

        echo $block;
    }

    /**
     * Displays the tree structure of a post.
     *
     * ## EXAMPLES
     *
     *     wp visitor tree
     *
     * @when before_wp_load
     */
    public function tree(): void
    {
        $visitor = new TreeVisitor();
        $traverser = new BlockTraverser($visitor);

        $traverser->traverse($this->getDemoContent());

        echo $visitor;
    }

    /**
     * Displays the depth and name of each block.
     *
     * ## EXAMPLES
     *
     *     wp visitor depth
     *
     * @when before_wp_load
     */
    public function depth(): void
    {
        $visitor = new DepthVisitor();
        $traverser = new BlockTraverser($visitor);
        $traverser->traverse($this->getDemoContent());

        echo $visitor;
    }

    /**
     * Wraps top-level paragraphs in a group block.
     *
     * ## EXAMPLES
     *
     *     wp visitor wrap
     *
     * @when before_wp_load
     */
    public function wrap(): void
    {
        $content = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:separator /-->\n\n<!-- wp:paragraph -->\n<p>World</p>\n<!-- /wp:paragraph -->";
        $traverser = new BlockTraverser(new WrapInGroupVisitor());

        echo $traverser->traverse($content);
    }

    /**
     * Removes every paragraph, then prints the tree.
     *
     * ## EXAMPLES
     *
     *     wp visitor remove
     *
     * @when before_wp_load
     */
    public function remove(): void
    {
        $tree = new TreeVisitor();
        $traverser = new BlockTraverser($tree, new ParagraphRemoverVisitor());

        $traverser->traverse($this->getDemoContent('demo-simple.html'));

        echo $tree;
    }

    /**
     * Collects the attachment IDs of a post so they can be loaded in one query.
     *
     * ## EXAMPLES
     *
     *     wp visitor ids
     *
     * @when before_wp_load
     */
    public function ids(): void
    {
        $visitor = new AttachmentIdVisitor();
        $traverser = new BlockTraverser($visitor);

        $traverser->traverse($this->getDemoContent());

        WP_CLI::line(sprintf('%d attachment IDs: %s', count($visitor->getIds()), implode(', ', $visitor->getIds())));
        WP_CLI::line('Call $visitor->prime() before rendering to load them all with one query.');
    }

    private function getDemoContent(string $file = 'demo.html'): string
    {
        $path = realpath(__DIR__ . '/../tests/fixtures/' . $file);
        if ($path === false) {
            throw new RuntimeException('Could not resolve demo content');
        }

        return (string) file_get_contents($path);
    }
});

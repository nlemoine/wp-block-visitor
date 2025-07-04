<?php

declare(strict_types=1);

use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Examples\DepthVisitor;
use n5s\BlockVisitor\Examples\GalleryVisitor;
use n5s\BlockVisitor\Examples\TreeVisitor;

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

    private function getDemoContent(): string
    {
        $root = realpath(__DIR__ . '/../tests/fixtures/demo.html');
        if ($root === false) {
            throw new RuntimeException('Could not resolve demo content');
        }

        return file_get_contents($root);
    }
});

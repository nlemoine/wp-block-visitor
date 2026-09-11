<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Examples;

use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\Visitor\AbstractBlockVisitor;
use n5s\BlockVisitor\Visitor\Traversal;

/**
 * Collects the attachment IDs referenced by block attributes.
 *
 * WordPress resolves each media block on render with its own `get_post()` call,
 * one query per block. Collecting the IDs in one pass and priming the post cache
 * turns those N queries into a single one:
 *
 *     $visitor = new AttachmentIdVisitor();
 *     (new BlockTraverser($visitor))->traverse($post->post_content);
 *     $visitor->prime();
 */
class AttachmentIdVisitor extends AbstractBlockVisitor
{
    /**
     * Core blocks and the attributes holding an attachment ID, or a list of them.
     */
    public const array CORE_ATTRIBUTES = [
        'core/audio' => ['id'],
        'core/cover' => ['id'],
        'core/file' => ['id'],
        'core/gallery' => ['ids'],
        'core/image' => ['id'],
        'core/media-text' => ['mediaId'],
        'core/video' => ['id'],
    ];

    /**
     * @var array<int, true>
     */
    private array $ids = [];

    /**
     * @param array<string, list<string>> $attributes Block name to attribute names, merged with the core map.
     */
    public function __construct(
        private readonly array $attributes = self::CORE_ATTRIBUTES
    ) {
    }

    public function enter(BlockNode $block): BlockNode|Traversal|null
    {
        foreach ($this->attributes[$block->getBlockName() ?? ''] ?? [] as $attribute) {
            $value = $block->getAttribute($attribute);
            foreach (\is_array($value) ? $value : [$value] as $id) {
                if (\is_int($id) && $id > 0) {
                    $this->ids[$id] = true;
                }
            }
        }

        return null;
    }

    /**
     * @return list<int> In order of first appearance.
     */
    public function getIds(): array
    {
        return \array_keys($this->ids);
    }

    /**
     * Loads every collected attachment and its meta into the object cache in one query.
     */
    public function prime(): void
    {
        if ($this->ids !== []) {
            \_prime_post_caches($this->getIds(), false, true);
        }
    }
}

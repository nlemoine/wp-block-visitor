<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Integration;

use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Examples\AttachmentIdVisitor;
use n5s\BlockVisitor\Tests\TestCase;

final class AttachmentIdVisitorTest extends TestCase
{
    public function testCollectsEveryAttachmentIdOnceInDocumentOrder(): void
    {
        $visitor = new AttachmentIdVisitor();

        (new BlockTraverser($visitor))->traverse(self::fixture('demo.html'));

        $this->assertSame(
            [17, 111079, 243, 244, 242, 241, 1192, 1219, 1220, 1221, 192, 1222, 1226, 1227, 225, 1229, 1230, 1231, 212, 36, 111078],
            $visitor->getIds()
        );
    }

    public function testCollectsListsOfIdsAndIgnoresInvalidValues(): void
    {
        $visitor = new AttachmentIdVisitor();
        $content = '<!-- wp:gallery {"ids":[3,"4",0,-1,5]} /-->'
            . '<!-- wp:media-text {"mediaId":7} /-->'
            . '<!-- wp:cover {"url":"https://example.com/bg.jpg"} /-->'
            . '<!-- wp:image {"id":3} /-->';

        (new BlockTraverser($visitor))->traverse($content);

        $this->assertSame([3, 5, 7], $visitor->getIds());
    }

    public function testAttributeMapCanBeExtended(): void
    {
        $visitor = new AttachmentIdVisitor([...AttachmentIdVisitor::CORE_ATTRIBUTES, 'vendor/hero' => ['backgroundId', 'logoId']]);

        (new BlockTraverser($visitor))->traverse('<!-- wp:vendor/hero {"backgroundId":10,"logoId":11} /--><!-- wp:image {"id":12} /-->');

        $this->assertSame([10, 11, 12], $visitor->getIds());
    }

    public function testPrimeWithoutIdsDoesNothing(): void
    {
        $visitor = new AttachmentIdVisitor();

        (new BlockTraverser($visitor))->traverse('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->');
        $visitor->prime();

        $this->assertSame([], $visitor->getIds());
    }
}

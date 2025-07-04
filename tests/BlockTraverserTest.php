<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests;

use Mantle\Testkit\Unit_Test_Case;
use n5s\BlockVisitor\BlockNode;

class BlockTraverserTest extends Unit_Test_Case
{
    public function testCreateTraverser(): void
    {
        $traverser = new \n5s\BlockVisitor\BlockTraverser();

        $this->assertInstanceOf(\n5s\BlockVisitor\BlockTraverser::class, $traverser);
        $this->assertEmpty($traverser->getVisitors());
    }

}

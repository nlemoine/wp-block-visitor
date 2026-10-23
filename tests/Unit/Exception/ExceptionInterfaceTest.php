<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests\Unit\Exception;

use Closure;
use n5s\BlockVisitor\BlockNode;
use n5s\BlockVisitor\BlockTraverser;
use n5s\BlockVisitor\Exception\ExceptionInterface;
use n5s\BlockVisitor\Internal\Placeholders;
use n5s\BlockVisitor\Tests\Support\RecordingVisitor;
use n5s\BlockVisitor\Tests\TestCase;
use n5s\BlockVisitor\Visitor\Traversal;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class ExceptionInterfaceTest extends TestCase
{
    /**
     * @param class-string<Throwable> $splClass
     */
    #[DataProvider('throwingCallProvider')]
    public function testEveryExceptionTheLibraryThrowsIsMarkedAndKeepsItsSplParent(Closure $call, string $splClass): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            $this->assertInstanceOf(ExceptionInterface::class, $exception);
            $this->assertInstanceOf($splClass, $exception);

            return;
        }

        $this->fail('The call should have thrown');
    }

    /**
     * @return \Iterator<string, array{Closure(): mixed, class-string<Throwable>}>
     */
    public static function throwingCallProvider(): \Iterator
    {
        yield 'invalid block name' => [static fn (): BlockNode => new BlockNode('Not A Name'), \InvalidArgumentException::class];
        yield 'index out of range' => [static fn (): BlockNode => (new BlockNode('core/group'))->removeInnerBlockAt(0), \OutOfRangeException::class];
        yield 'cycle' => [static function (): BlockNode {
            $parent = new BlockNode('core/group', [], [new BlockNode('core/paragraph')]);

            return $parent->getInnerBlocks()[0]->appendInnerBlock($parent);
        }, \LogicException::class,];
        yield 'unencodable attribute' => [static fn (): string => (string) new BlockNode('core/paragraph', ['bad' => \INF]), \RuntimeException::class];
        yield 'traverser protocol' => [static fn (): BlockNode => (new BlockTraverser(new RecordingVisitor(static fn (): Traversal => Traversal::Remove)))->traverse('<!-- wp:paragraph /-->'), \LogicException::class];
        yield 'placeholders out of sync' => [static fn (): int => Placeholders::position([], 0, 1), \LogicException::class];
    }
}

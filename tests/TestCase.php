<?php

declare(strict_types=1);

namespace n5s\BlockVisitor\Tests;

use n5s\BlockVisitor\Tests\Support\AssertsHtml;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use AssertsHtml;

    protected static function fixturesPath(): string
    {
        return __DIR__ . '/fixtures';
    }

    protected static function fixture(string $name): string
    {
        return \file_get_contents(self::fixturesPath() . '/' . $name);
    }
}

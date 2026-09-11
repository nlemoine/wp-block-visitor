<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
    ])
    ->withRootFiles()
    ->withCache(__DIR__ . '/tmp/rector')
    // PHP version comes from the "php" constraint in composer.json.
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        phpunitNarrowAsserts: true,
    )
    // PHPUnit migration rules, bound to the PHPUnit version in composer.lock.
    ->withComposerBased(phpunit: true)
    // WordPress function/class signatures, so type inference does not go blind on WP_* APIs.
    ->withPhpstanConfigs([__DIR__ . '/phpstan.neon.dist'])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // Fixtures are intentionally malformed legacy markup.
        __DIR__ . '/tests/fixtures',
    ]);

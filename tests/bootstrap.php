<?php

declare(strict_types=1);

use function Mantle\Testing\manager;

require_once dirname(__DIR__) . '/vendor/autoload.php';
$rootDir = realpath(__DIR__ . '/..');

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
putenv("WP_CORE_DIR=$rootDir/tmp/wordpress");
putenv('WP_TESTS_USE_HTTPS=1');

manager()->with_sqlite()->install();

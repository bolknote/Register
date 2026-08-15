<?php

declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration();
$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/tools/deployment/SharedHostingDistributionBuilder.php';

return $config
    ->addPathToScan($projectRoot . '/_admin', isDev: false)
    ->addPathToScan($projectRoot . '/_include/common.php', isDev: false)
    ->addPathToScan($projectRoot . '/_include/functions.php', isDev: false)
    ->addPathToScan($projectRoot . '/_include/setup.php', isDev: false)
    ->addPathToScan($projectRoot . '/_tests', isDev: true)
    ->addPathToScan($projectRoot . '/tools', isDev: true)
    ->addPathToScan($projectRoot . '/index.php', isDev: false)
    ->addPathToExclude($projectRoot . '/tools/quality/stubs')
    // Codeception creates these actors and traits from suite configuration at runtime.
    ->ignoreUnknownClasses([
        'Tests\\Support\\Helper\\AbstractBrowserModule',
    ])
    // Native mbstring/ctype are covered by direct polyfills; the other extensions are optional and guarded.
    ->ignoreErrorsOnExtensions([
        'ext-ctype',
        'ext-curl',
        'ext-intl',
        'ext-mbstring',
        'ext-zend-opcache',
        'ext-zlib',
    ], [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages([
        'symfony/expression-language',
        'symfony/polyfill-ctype',
        'symfony/polyfill-iconv',
        'symfony/polyfill-mbstring',
    ], [ErrorType::UNUSED_DEPENDENCY])
    // These tools are invoked from Composer scripts or non-PHP configuration files.
    ->ignoreErrorsOnPackages([
        'codeception/module-asserts',
        'phan/phan',
        'php-parallel-lint/php-parallel-lint',
        'phpcompatibility/php-compatibility',
        'phpmd/phpmd',
        'phpstan/phpstan',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-phpunit',
        'phpstan/phpstan-strict-rules',
        'slevomat/coding-standard',
        'squizlabs/php_codesniffer',
        'vimeo/psalm',
    ], [ErrorType::UNUSED_DEPENDENCY])
    ->enableAnalysisOfUnusedDevDependencies();

<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\ConstFetch\RemovePhpVersionIdCheckRector;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/_admin/ajax.php',
        __DIR__ . '/_admin/index.php',
        __DIR__ . '/_admin/install.php',
        __DIR__ . '/_admin/pictman.php',
        __DIR__ . '/_include/src',
        __DIR__ . '/_include/common.php',
        __DIR__ . '/_include/functions.php',
        __DIR__ . '/_include/installation_required.php',
        __DIR__ . '/_include/setup.php',
        __DIR__ . '/_extensions',
        __DIR__ . '/_tests',
        __DIR__ . '/cron.php',
        __DIR__ . '/index.php',
        __DIR__ . '/tools',
    ])
    ->withSkip([
        // These rules cannot see that catch variables and PDO statements are
        // intentionally retained for assertions/resource release in tests.
        CatchExceptionNameMatchingTypeRector::class,
        PreferPHPUnitThisCallRector::class,
        RemoveConcatAutocastRector::class,
        RemoveDefaultValueFromAssignedPropertyRector::class,
        RemovePhpVersionIdCheckRector::class,
        RemoveUnusedVariableAssignRector::class,
        __DIR__ . '/_extensions/*/lang',
        __DIR__ . '/_extensions/*/templates',
        __DIR__ . '/_extensions/*/views',
        __DIR__ . '/_include/src/Register/Module/*/resources/lang',
        __DIR__ . '/_include/src/Register/Module/*/resources/views',
        __DIR__ . '/_tests/_resources',
        __DIR__ . '/_tests/_support/_generated',
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
        phpunitNarrowAsserts: true,
    );

<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\ConstFetch\RemovePhpVersionIdCheckRector;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\ValueObject\PhpVersion;

$projectRoot = dirname(__DIR__, 2);

return RectorConfig::configure()
    ->withPaths([
        $projectRoot . '/_admin/ajax.php',
        $projectRoot . '/_admin/index.php',
        $projectRoot . '/_admin/install.php',
        $projectRoot . '/_admin/pictman.php',
        $projectRoot . '/_include/src',
        $projectRoot . '/_include/common.php',
        $projectRoot . '/_include/functions.php',
        $projectRoot . '/_include/installation_required.php',
        $projectRoot . '/_include/setup.php',
        $projectRoot . '/_extensions',
        $projectRoot . '/_tests',
        $projectRoot . '/index.php',
        $projectRoot . '/tools',
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
        // Explicit null guards narrow reliably in all configured analyzers.
        FlipTypeControlToUseExclusiveTypeRector::class,
        $projectRoot . '/_extensions/*/lang',
        $projectRoot . '/_extensions/*/templates',
        $projectRoot . '/_extensions/*/views',
        $projectRoot . '/_include/src/Register/Module/*/resources/lang',
        $projectRoot . '/_include/src/Register/Module/*/resources/templates',
        $projectRoot . '/_include/src/Register/Module/*/resources/views',
        $projectRoot . '/_tests/_resources',
        $projectRoot . '/_tests/_output',
        $projectRoot . '/_tests/_support/Helper/AbstractBrowserModule.php',
        $projectRoot . '/_tests/_support/_generated',
        $projectRoot . '/tools/quality',
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

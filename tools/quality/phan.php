<?php

declare(strict_types=1);

return [
    'target_php_version' => '8.5',
    'minimum_target_php_version' => '8.3',
    'directory_list' => [
        '_admin',
        '_include/src',
        '_extensions',
        '_vendor',
    ],
    'file_list' => [
        '_include/common.php',
        '_include/functions.php',
        '_include/installation_required.php',
        '_include/setup.php',
        'index.php',
        'tools/backup.php',
        'tools/dev-bootstrap.php',
        'tools/dev-router.php',
        'tools/queue-status.php',
        'tools/retry-background-job.php',
    ],
    'exclude_analysis_directory_list' => [
        '_vendor',
        '_admin/lang',
        '_admin/templates',
        '_include/src/Register/Module/Blog/resources/lang',
        '_include/src/Register/Module/Blog/resources/templates',
        '_include/src/Register/Module/Blog/resources/views',
        '_include/src/Register/Module/Math/lang',
        '_include/src/Register/Module/Search/resources/lang',
        '_include/src/Register/Module/Search/resources/views',
        '_tests/_resources',
        '_tests/_support/_generated',
        'tools/quality',
    ],
    'exclude_file_regex' => '@(?:^|/)(?:counter|data)\.php$@',
    'allow_missing_properties' => false,
    'null_casts_as_any_type' => false,
    'scalar_implicit_cast' => false,
    'strict_method_checking' => true,
    'strict_param_checking' => true,
    'strict_property_checking' => true,
    'strict_object_checking' => true,
    'strict_return_checking' => true,
    'check_docblock_signature_param_type_match' => true,
    'check_docblock_signature_return_type_match' => true,
    'analyze_signature_compatibility' => true,
    // Public services/controllers are invoked through DI, routing and extension
    // hooks, so whole-program dead-code inference is not valid for this package.
    'dead_code_detection' => false,
    'dead_code_detection_prefer_false_negative' => false,
    'unused_variable_detection' => true,
    'redundant_condition_detection' => true,
    'error_prone_truthy_condition_detection' => true,
    // Exceptions from framework/vendor calls are deliberately not duplicated
    // through every controller's docblock.
    'warn_about_undocumented_throw_statements' => false,
    'warn_about_undocumented_exceptions_thrown_by_invoked_functions' => false,
    'minimum_severity' => 0,
    'plugins' => [
        'AlwaysReturnPlugin',
        'DuplicateArrayKeyPlugin',
        'DuplicateExpressionPlugin',
        'EmptyMethodAndFunctionPlugin',
        'EmptyStatementListPlugin',
        'InvalidVariableIssetPlugin',
        'LoopVariableReusePlugin',
        'NonBoolBranchPlugin',
        'NonBoolInLogicalArithPlugin',
        'PHPDocRedundantPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'RedundantAssignmentPlugin',
        'RemoveDebugStatementPlugin',
        'SleepCheckerPlugin',
        'StrictComparisonPlugin',
        'StrictLiteralComparisonPlugin',
        'SuspiciousParamOrderPlugin',
        'UnreachableCodePlugin',
        'UnusedSuppressionPlugin',
        'UseReturnValuePlugin',
    ],
    'suppress_issue_types' => [
        // PHPStan/Psalm conditional types, local aliases and DB-row shapes are
        // stricter than the equivalent syntax understood by Phan 6.
        'PhanPartialTypeMismatchArgument',
        'PhanPartialTypeMismatchArgumentInternal',
        'PhanPartialTypeMismatchReturn',
        'PhanTemplateTypeNotUsedInFunctionReturn',
        'PhanTypeArraySuspicious',
        'PhanTypeArraySuspiciousNullable',
        'PhanTypeMismatchArgument',
        'PhanTypeMismatchArgumentInternal',
        'PhanTypeMismatchArgumentNullable',
        'PhanTypeMismatchDeclaredParam',
        'PhanTypeMismatchDeclaredReturn',
        'PhanTypeMismatchDeclaredReturnNullable',
        'PhanTypeMismatchProperty',
        'PhanTypeMismatchPropertyProbablyReal',
        'PhanTypePossiblyInvalidDimOffset',
        'PhanUndeclaredProperty',
        'PhanUndeclaredTypeProperty',
        'PhanUnextractableAnnotation',
        'PhanUnextractableAnnotationSuffix',

        // Required callback parameters and variables intentionally exposed to
        // included templates appear unused to a single-file analyzer.
        'PhanUnusedClosureParameter',
        'PhanUnusedPrivateMethodParameter',
        'PhanUnusedVariable',
        'PhanUnreferencedUseNormal',

        // Heuristic parameter-name/order checks are not semantic errors; the
        // strict signature engines above still validate every call.
        'PhanParamSuspiciousOrder',
        'PhanPluginSuspiciousParamOrderInternal',
        'PhanPluginSuspiciousParamPosition',
        'PhanPluginSuspiciousParamPositionInternal',
        'PhanPluginMixedKeyNoKey',
        'PhanCoalescingNeverNullInLoop',
        'PhanImpossibleValueComparison',
        'PhanPluginDuplicateIfCondition',

        // Phan's bundled internal signatures do not model the current GD AVIF
        // functions, and Composer's PHP 8 polyfill duplicates Stringable.
        'PhanParamTooManyInternal',
        'PhanRedefinedInheritedInterface',

        'PhanPluginPrintfVariableFormatString',
        'PhanPluginPrintfIncompatibleArgumentTypeWeak',
        // Phan does not connect variables exported to required templates or
        // Symfony's vendor return types across these procedural entry points.
        // PHPStan max and Psalm level 1 analyze the same files and scopes.
        'PhanImpossibleConditionInGlobalScope',
        'PhanImpossibleTypeComparisonInGlobalScope',
        'PhanPluginNonBoolBranch',
        'PhanPluginNonBoolInLogicalArith',
        'PhanUndeclaredGlobalVariable',
        // printf() is HTML rendering in the installer, not a debug statement.
        'PhanPluginRemoveDebugCall',
        'PhanPluginRemoveDebugEcho',
        'PhanTypeInvalidThrowsIsInterface',
        'PhanUnusedPublicMethodParameter',
        'PhanUnusedPublicNoOverrideMethodParameter',
    ],
];

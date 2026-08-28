# Refactoring context: 500,000-byte code selection

This manifest marks the code that should be loaded first for the current engine-level refactoring.
It is anchored to commit `57abf2ece4f5bcd668f132de12e18b44c284a944` and deliberately selects whole files (`1-end`),
so no class or method is cut in half.

The scope is the active Register/S2 boundary: bootstrap and dependency injection, base versus optional
modules, portable persistence and schema migration, the shared content/blog model, canonical URLs and
comments, and the request-driven queue. The obsolete `feature/shutdown-runner` worktree/ref is not part
of the selection; its current implementation is represented by the queue files already merged into
`main`.

Sizes are raw `wc -c` bytes at the anchored commit. The total is exactly **500,000 bytes** (decimal
500 KB; 488.28125 KiB) across **101 files**. The manifest file itself is not counted.

| Group | Files | Bytes | Why it is in the refactoring context |
|---|---:|---:|---|
| Runtime and module boundaries | 33 | 176,406 | Boot order, DI, routing/listeners, base/optional module split and autoloading |
| Portable persistence and schema | 7 | 64,429 | Cross-database behavior, transactions and supported migration path |
| Shared content and blog domain | 32 | 155,393 | The main product seam being refactored: content identity, publication, tags, views and public controllers |
| Canonical URLs and comments | 8 | 45,105 | Cross-cutting identities consumed by pages, feeds, search, notifications and federation |
| Request-driven queue | 16 | 42,016 | Current merged shutdown execution, leases, budgets, retry and generation semantics |
| Contract tests | 5 | 16,651 | Small, high-value tests that pin the riskiest architectural invariants |
| **Total** | **101** | **500,000** | |

Each entry below has the format `bytes  line-range  path`.

## Runtime and module boundaries

```text
6437   1-158   composer.json
4524   1-119   index.php
11200  1-268   _include/common.php
3027   1-109   _include/functions.php
3461   1-75    _include/setup.php
56086  1-1014  _include/src/CmsExtension.php
17179  1-483   _include/src/Extensions/ExtensionManager.php
10528  1-291   _include/src/Framework/Application.php
7714   1-259   _include/src/Framework/Container.php
510    1-18    _include/src/Framework/ContainerAwareListenerModuleInterface.php
475    1-18    _include/src/Framework/ContainerAwareRoutingModuleInterface.php
388    1-16    _include/src/Framework/ContainerModuleInterface.php
397    1-20    _include/src/Framework/ControllerInterface.php
412    1-22    _include/src/Framework/Event/NotFoundEvent.php
258    1-15    _include/src/Framework/Exception/AccessDeniedException.php
441    1-18    _include/src/Framework/Exception/ConfigurationException.php
357    1-17    _include/src/Framework/Exception/DecoratedServiceNotFoundException.php
215    1-15    _include/src/Framework/Exception/NotFoundException.php
224    1-15    _include/src/Framework/Exception/ParameterNotFoundException.php
354    1-17    _include/src/Framework/Exception/ServiceAlreadyDefinedException.php
351    1-17    _include/src/Framework/Exception/ServiceNotFoundException.php
766    1-24    _include/src/Framework/ExtensionInterface.php
459    1-18    _include/src/Framework/ListenerModuleInterface.php
320    1-17    _include/src/Framework/ModuleInterface.php
690    1-24    _include/src/Framework/ResponseProcessorInterface.php
431    1-18    _include/src/Framework/RoutingModuleInterface.php
1060   1-48    _include/src/Framework/ServiceDecorator.php
504    1-22    _include/src/Framework/StatefulServiceInterface.php
3853   1-115   _include/src/Model/ExtensionCache.php
464    1-22    _include/src/Register/Module/BaseModuleManifestInterface.php
4820   1-152   _include/src/Register/Module/BaseModuleRegistry.php
37252  1-693   _include/src/Register/ProductModule.php
1249   1-46    _include/src/Register/RegisterKernel.php
```

## Portable persistence and schema

```text
17717  1-536  _include/src/Pdo/DbLayer.php
10891  1-311  _include/src/Pdo/DbLayerPostgres.php
13826  1-414  _include/src/Pdo/DbLayerSqlite.php
10729  1-372  _include/src/Pdo/PDO.php
8761   1-235  _include/src/Register/Schema/SchemaManager.php
475    1-22   _include/src/Register/Schema/SchemaMigrationInterface.php
2030   1-64   _include/src/Register/Schema/SchemaMigrator.php
```

## Shared content and blog domain

```text
343    1-19   _include/src/Register/Content/Admin/ContentRevision.php
2002   1-62   _include/src/Register/Content/Admin/ContentRevisionService.php
491    1-22   _include/src/Register/Content/ContentChangedEvent.php
3375   1-120  _include/src/Register/Content/ContentChangeDispatcher.php
369    1-16   _include/src/Register/Content/ContentDeletionGuardInterface.php
1081   1-43   _include/src/Register/Content/ContentDetails.php
1096   1-38   _include/src/Register/Content/ContentDetailsRepository.php
1553   1-55   _include/src/Register/Content/ContentId.php
1220   1-40   _include/src/Register/Content/ContentItem.php
2826   1-86   _include/src/Register/Content/ContentMediaSchema.php
1888   1-65   _include/src/Register/Content/ContentPublicationQueueHandler.php
4918   1-153  _include/src/Register/Content/ContentPublicationScheduler.php
483    1-22   _include/src/Register/Content/ContentRenderedEvent.php
2310   1-77   _include/src/Register/Content/ContentRepository.php
3483   1-87   _include/src/Register/Content/ContentSchema.php
401    1-20   _include/src/Register/Content/ContentSourceInterface.php
1504   1-51   _include/src/Register/Content/ContentTagSchema.php
260    1-17   _include/src/Register/Content/ContentType.php
12633  1-347  _include/src/Register/Content/ContentViewRepository.php
1384   1-46   _include/src/Register/Content/ContentViewSchema.php
5936   1-162  _include/src/Register/Content/PageContentSource.php
440    1-17   _include/src/Register/Content/RecentContentSourceInterface.php
510    1-24   _include/src/Register/Content/Tag.php
10311  1-308  _include/src/Register/Content/TagRepository.php
473    1-22   _include/src/Register/Content/TagUsage.php
3975   1-161  _include/src/Register/Module/Blog/BlogUrlBuilder.php
4769   1-156  _include/src/Register/Module/Blog/Content/BlogContentSource.php
7479   1-202  _include/src/Register/Module/Blog/Controller/BlogController.php
4617   1-134  _include/src/Register/Module/Blog/Controller/MainPageController.php
13864  1-333  _include/src/Register/Module/Blog/Controller/PostPageController.php
10427  1-313  _include/src/Register/Module/Blog/Model/PostProvider.php
48972  1-923  _include/src/Register/Module/Blog/Module.php
```

## Canonical URLs and comments

```text
20973  1-549  _include/src/Register/Comment/CommentRepository.php
2417   1-71   _include/src/Register/Comment/CommentSchema.php
4386   1-149  _include/src/Register/Comment/ContentCommentStrategy.php
5834   1-179  _include/src/Register/Url/ContentSlugService.php
7376   1-221  _include/src/Register/Url/ContentUrlGenerator.php
2015   1-83   _include/src/Register/Url/ReservedRouteRegistry.php
931    1-36   _include/src/Register/Url/SlugGenerator.php
1173   1-47   _include/src/Register/Url/UniqueSlugGenerator.php
```

## Request-driven queue

```text
3325   1-105  _include/src/Queue/BackgroundWorkRunner.php
383    1-16   _include/src/Queue/BackgroundWorkRunnerInterface.php
3132   1-106  _include/src/Queue/NativeShutdownRuntime.php
10440  1-280  _include/src/Queue/QueueConsumer.php
926    1-30   _include/src/Queue/QueueDatabaseClock.php
1933   1-60   _include/src/Queue/QueueExecutionBudget.php
547    1-26   _include/src/Queue/QueueHandlerInterface.php
2687   1-81   _include/src/Queue/QueueHandlerRegistry.php
344    1-15   _include/src/Queue/QueuePermanentFailure.php
5543   1-141  _include/src/Queue/QueuePublisher.php
1398   1-44   _include/src/Queue/QueueRecovery.php
2965   1-99   _include/src/Queue/QueueRunnerLease.php
1405   1-47   _include/src/Queue/QueueSchema.php
251    1-14   _include/src/Queue/QueueTimeBudgetExceeded.php
1037   1-35   _include/src/Queue/ShutdownRuntimeInterface.php
5700   1-154  _include/src/Queue/ShutdownWorkCoordinator.php
```

## Contract tests

```text
6991  1-259  _tests/unit/Cms/Queue/ShutdownWorkCoordinatorTest.php
1223  1-41   _tests/unit/Register/Content/ContentIdTest.php
1861  1-64   _tests/unit/Register/Module/BaseModuleRegistryTest.php
4156  1-118  _tests/unit/Register/RegisterKernelTest.php
2420  1-92   _tests/unit/Register/Schema/SchemaMigratorTest.php
```

## Reproducing the measurement

From the repository root, extract the selected paths and recompute the byte total:

```bash
awk '$1 ~ /^[0-9]+$/ && $2 ~ /^1-[0-9]+$/ { print $3 }' \
    _doc/refactoring-context-500kb.md \
    | tr '\n' '\0' \
    | xargs -0 wc -c
```

At the anchored commit, hashing the sorted per-file SHA-256 records produces:

```text
db8cb2d717b0f69af4a47311c554ea5dbbbfad6fc31223080b08a9c8854003a5
```

This is a priority context, not a declaration that everything outside it is unimportant. In
particular, presentation assets, optional ActivityPub internals, administration UI, search/Rose,
mail, backup/update machinery and broad end-to-end suites should be pulled in only when the concrete
refactoring step crosses one of those boundaries.

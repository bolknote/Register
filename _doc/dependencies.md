# Dependency maintenance

Last reviewed: 2026-08-14.

`composer audit --locked` reports no known security advisories. Production dependencies are current.
The remaining Composer warnings are limited to development tools:

| Package | Status | Decision |
| --- | --- | --- |
| `codeception/module-phpbrowser` | Updated from 3.0.2 to 4.1.0 together with Guzzle 8, Promises 3 and PSR-7 3. | Keep on `^4.1`; run the HTTP acceptance suite after future major updates. |
| `azjezz/psl` | Current version, but abandoned. It is pulled in only by `maglnet/composer-require-checker` 4.20. | Keep temporarily. Composer Require Checker 4.24 uses the replacement package but requires PHP 8.4 and Symfony 8, while Register still supports PHP 8.3. Revisit when the minimum PHP version becomes 8.4 or a compatible release removes the dependency. |
| `netresearch/jsonmapper` | 5.0.1; Composer lists 6.0.0. | Do not force the transitive major update. Phan, Psalm and `danog/advanced-json-rpc` currently require version 5. Revisit after those tools widen their constraints. |
| `phpmd/phpmd` | Composer compares the selected `3.x-dev` branch with `dev-master`. | No update: the locked `3.x` commit is newer than the reported master commit. Reconsider when PHPMD publishes a stable 3.x release. |

Use these commands for the next review:

```shell
composer audit --locked
composer outdated --locked --direct
composer outdated --locked --all
composer validate --strict --no-check-publish
composer check-platform-reqs --lock
```

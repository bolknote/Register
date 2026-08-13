# Developing optional modules for Register

Register supports optional modules for integrations and specialized behavior. The modules that form
the blog product itself are base modules and follow a different lifecycle; see
[ADR 0001](decisions/0001-register-module-tiers.md).

## Base modules are not plugins

Blog, Pages, Comments, Tags, Search, Typography, Analytics, Math, and Admin are available in every
Register installation. They cannot be disabled or uninstalled and are upgraded with the engine.

During the transition from S2, five base modules still live under `_extensions`: `s2_blog`,
`s2_search`, `s2_typo`, `s2_counter`, and `s2_latex`. Their status is defined by
[`BaseModuleRegistry`](../_include/src/Register/Module/BaseModuleRegistry.php). Their directory and
class names do not make them optional.

Their schemas are installed and upgraded by Register's single `REGISTER_SCHEMA_REVISION` ledger.
Base manifest versions are not consulted after a database has entered that ledger, and their
`uninstall()` methods cannot remove product data.

The rest of this document describes optional modules only.

## Current optional-module layout

An optional module resides in its own `_extensions/{module_id}` directory. The identifier may contain
lowercase ASCII letters, digits, and underscores. A module contains:

- `Manifest.php` — required metadata and lifecycle operations;
- `Extension.php` — optional public application services, listeners, and routes;
- `AdminExtension.php` — optional control-panel services and listeners;
- optional `lang`, `templates`, `views`, JavaScript, and CSS resources.

These inherited names will become `Module`, `AdminModule`, and a dedicated optional-module directory
in a later migration. The current names remain the supported API until that migration lands.

## Manifest

`Manifest.php` defines `s2_extensions\{module_id}\Manifest` implementing
`S2\Cms\Extensions\ManifestInterface`. It provides the title, author, description, semantic version,
dependencies, and migration callbacks.

Installation and upgrades must be idempotent. A disabled optional module keeps its schema and data.
Uninstalling code must not delete data by default; permanent data removal belongs to a separate,
explicitly confirmed operation.

Optional modules must not remove or narrow base Register tables, settings, or routes.

## Runtime module contract

`Extension.php` and `AdminExtension.php` currently implement
[`ExtensionInterface`](../_include/src/Framework/ExtensionInterface.php):

```php
interface ExtensionInterface
{
    public function buildContainer(Container $container): void;

    public function registerListeners(
        EventDispatcherInterface $eventDispatcher,
        Container $container,
    ): void;

    public function registerRoutes(RouteCollection $routes, Container $container): void;
}
```

Use `buildContainer()` for service definitions, `registerListeners()` for event subscriptions, and
`registerRoutes()` for controller routes. Public and control-panel registrations remain separate so
public requests do not pay for administration-only services.

Prefer routes and controllers over directly accessible PHP endpoints.

## Integration boundaries

Optional modules should depend on public Register capabilities and services, for example content
repositories, canonical URL generation, publication events, comment events, renderer extensions,
search document providers, and administration menu registration.

Until those contracts are introduced, treat direct access to `articles`, `s2_blog_*`, comment, tag,
and search-index tables as unstable. Such access will break as Register adopts a unified content
model.

Use dependency injection instead of globals and static state. Keep business logic in services and
keep event listeners small.

## Dependencies and compatibility

Dependencies are declared by module identifier in the current manifest API. A module cannot be
enabled until its optional dependencies are enabled. Future manifests will depend on Register
versions and named capabilities so modules do not need to know which base package provides a
capability.

Use semantic versions. A module must declare the Register versions it supports once the capability
API is versioned.

## Resources

Optional modules may supply translations, templates, views, CSS, and JavaScript from their own
directory. Pass the module identifier when rendering module-owned views or templates so lookup stays
isolated. Themes may provide an explicit override for an optional module's resource.

Global view replacement by optional modules is unsupported because multiple modules could compete
for the same file. Prefer events, named placeholders, renderer contracts, and view-specific hooks.

## Operational rules

- Optional modules must tolerate being disabled.
- Disabling a module must not remove data or break base routes.
- Installation and migration failures must leave the previous working state recoverable.
- Validate identifiers and every administration action, including CSRF protection.
- Do not expose secrets in module metadata, templates, logs, or client assets.
- Avoid direct PHP entry points; use registered routes.
- Add unit and integration coverage for install, upgrade, disable, re-enable, and compatibility
  behavior.

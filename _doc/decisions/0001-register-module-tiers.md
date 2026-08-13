# ADR 0001: Register has base and optional module tiers

- Status: accepted
- Date: 2026-08-13

## Context

Register is a blog engine based on S2. The inherited extension mechanism treats Blog, Search,
Typography, Counter, and LaTeX as separately installable features. That model lets an administrator
disable the publishing model itself and makes a fresh installation incomplete until extensions are
installed manually.

Register still needs extension points for integrations and specialized behavior. Removing modular
boundaries would make the product harder to maintain and would prevent third-party additions.

## Decision

Register has two module tiers.

**Base modules** form the product. Content/Blog, permanent Pages, Comments, Tags, Search,
Typography, Analytics, Math, and Admin are present in every installation. They are loaded in a
deterministic order, upgraded with Register, and cannot be disabled or uninstalled. The transitional
extension-packaged base modules are identified by `Register\Module\BaseModuleRegistry`.

**Optional modules** add integrations and specialized behavior. They may be discovered, installed,
enabled, disabled, and upgraded independently. Disabling an optional module preserves its data;
deleting that data is a separate explicit operation.

Both tiers may register dependency-injection services, event listeners, routes, administration
extensions, templates, translations, and assets through a small shared runtime contract. Only
optional modules participate in the administrator-controlled lifecycle.

Product code moves to the `Register` namespace. The `S2\Cms` namespace remains for framework code
that is reusable without Register's blog domain.

## Consequences

- A fresh Register installation must initialize every base module.
- Runtime loading of base modules must not depend on extension database rows or the enabled-extension
  cache.
- The module-management page and HTTP actions must not offer disable or uninstall operations for
  base modules.
- Product migrations have one Register schema version; optional modules retain independent
  migrations and compatibility metadata.
- The current implementation stores that integer in `REGISTER_SCHEMA_REVISION`; the first migration
  absorbs and removes inherited base-module rows from the optional-module registry.
- Optional modules integrate through public Register contracts and events rather than querying base
  module tables directly.
- Existing extension class names and directories remain transitional until base modules are moved
  into `Register\*` namespaces.

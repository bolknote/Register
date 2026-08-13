# Register architecture overview

Register is a blog engine built on S2. S2 provides reusable application infrastructure; Register
provides the publishing domain and product experience. The boundary is intentional:

- `S2\Cms` contains HTTP, dependency injection, events, database access, queues, caching, and reusable
  administration infrastructure.
- `Register` contains posts, pages, comments, tags, search, typography, analytics, formula rendering,
  editorial workflows, public presentation, and product policy.

See [ADR 0001](decisions/0001-register-module-tiers.md) for the module-tier decision.

## Application runtime

[`Application`](../_include/src/Framework/Application.php) is the HTTP kernel. Runtime modules provide
container definitions, event listeners, and routes through the current
[`ExtensionInterface`](../_include/src/Framework/ExtensionInterface.php). The interface will be renamed
to `ModuleInterface` as product modules move out of the inherited extension directories.

The boot sequence is:

1. Register the reusable S2 infrastructure module.
2. Register the mandatory Register base modules in deterministic order.
3. In control-panel requests, register the base administration module and administration portions of
   the base modules.
4. Discover and register enabled optional modules.
5. Build the container, event dispatcher, and route collection.

The mandatory list is defined in
[`BaseModuleRegistry`](../_include/src/Register/Module/BaseModuleRegistry.php), not in the database.

## Module tiers

### Base modules

Base modules form every Register installation and cannot be disabled or uninstalled:

- Content/Blog and permanent Pages;
- Comments and Tags;
- Search;
- Typography;
- Analytics;
- Math;
- Admin.

Some of these concerns currently live in S2 core and others still live under `_extensions`. This is
a transitional layout. Base product code will move into `Register\*` namespaces and use one Register
schema migration stream.

### Optional modules

Optional modules provide integrations and specialized behavior. They can add services, listeners,
routes, administration pages, translations, templates, views, and assets. Their installed and enabled
state may be stored in the database, and they retain independent version and migration metadata.

Optional modules must use public Register services and events. Direct access to product tables is not
a supported integration boundary.

## Configuration

Register has two configuration layers:

| Static | Dynamic |
|---|---|
| Stored in `config.php` | Stored in the `config` table |
| Database, filesystem, URL, cache, and environment wiring | Editorial and site behavior |
| Changed by editing deployment configuration | Changed in the control panel |
| Loaded as container parameters | Accessed through `DynamicConfigProvider` |

Product-facing settings will gradually move from inherited `S2_*` names to typed Register settings.
The inherited names are implementation details rather than a compatibility requirement.

## Controllers and presentation

Controllers implement
[`ControllerInterface`](../_include/src/Framework/ControllerInterface.php), receive a matched request,
and return a response. Route matching decides which controller runs.

Page templates define the large-scale HTML structure. Views render individual blocks. During the
namespace transition, lookup still supports `_include`, themes, and `_extensions`; base-module
resources will move to Register-owned resource directories while optional-module resource lookup
remains isolated.

## Content and URL direction

Posts are the primary content type and pages are a secondary permanent content type. They will share
publication, revision, author, comment, tag, search, feed, and sitemap infrastructure while retaining
type-specific policies such as page hierarchy.

The blog lives at `/`. Post permalinks are `/<slug>`; publication dates belong to archive navigation,
not post addresses. One canonical URL service must be used by public rendering, the control panel,
RSS, sitemap, search, comments, and notifications.

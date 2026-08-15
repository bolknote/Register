# Register and Aegea feature comparison

This document compares Register with the capabilities advertised on the
[Aegea feature page](https://blogengine.ru/features/). It is intended as a product-gap inventory for
future planning, not as a commitment to reproduce every Aegea feature.

The snapshot was reviewed on 2026-08-14 against first-party Register source code, routes, database schema,
tests, and the CodeGraph index. Repeated items from Aegea's release summaries and thematic sections
are consolidated. A feature that can only be recreated with arbitrary HTML, a custom theme, or a new
extension is not marked as built in.

Status legend:

- **Available** — the user-facing outcome is implemented in Register.
- **Partial** — only part of the outcome is implemented, or the workflow differs materially.
- **Missing** — no first-party implementation was found.
- **Not applicable** — the feature belongs to a commercial/licensing model Register does not use.

## Executive summary

Register already has a solid blog foundation: pages and posts, unpublished drafts, tags, comments,
email subscriptions, favourites, calendar archives, RSS, sitemaps, morphological search, related-content
recommendations, a mandatory/optional module system, and browser-based administration. It additionally supports
multiple users with roles and three database families: MySQL/MariaDB, PostgreSQL, and SQLite.

The largest product gaps are in the authoring experience, social publishing and identity, per-item
popularity analytics, bundled themes and languages, and automatic URL lifecycle management.

## Editor, media, and presentation

| Aegea capability | Register status | Notes |
|---|---|---|
| Persistent public “Write” control | Partial | Editing is available through the separate control panel; there is no persistent public author control. |
| Markdown-like authoring syntax and semantic blocks | Partial | Register provides an HTML editor, formatting controls, and smart paragraphs, but not Aegea's syntax or block model. |
| Links, emphasis, arbitrary HTML, and emoji | Available | Supported through HTML and UTF-8. |
| Automatic URL linking | Missing | Links must be authored explicitly. |
| Automatic tweet embedding | Missing | Embed HTML can be inserted manually. |
| Automatic YouTube and Vimeo embedding | Missing | Media can be embedded manually with HTML. |
| Fully offline editor | Missing | There is no service worker or offline save queue. |
| Batch drag-and-drop uploads | Partial | The file manager handles uploads; direct editor drag-and-drop is focused on JPEG and PNG images. |
| Paste an image from the clipboard | Available | Implemented in the editor image pipeline. |
| JPG, GIF, PNG, MP3, Ogg, and MP4 uploads | Available | Included in the default extension allow-list. |
| WebP, AVIF, MOV, and WebM uploads | Available | Included in the default extension allow-list and covered by the media validation pipeline. |
| SVG uploads | Partial | SVG is deliberately excluded by default because it can contain active content; a trusted deployment can opt in explicitly. |
| Play audio inside the editor | Available | Native HTML audio is enhanced by Register's accessible local player in the live preview. |
| Replace a file with Alt while retaining its name | Missing | Name collisions produce a new name instead. |
| Rename an uploaded file | Partial | Supported by the picture manager, but not inline in the text media workflow. |
| Automatic gallery from consecutive images | Missing | A gallery can only be assembled manually. |
| Automatic image captions | Missing | Captions require manual markup. |
| Special syntax for external audio | Missing | Manual HTML is required. |
| Audio and video media fragments | Partial | The media manager inserts first-party audio markup; video still requires manual HTML. |
| Silent looping video (`@loop`) | Missing | No first-party implementation. |
| Public code highlighting for 18 languages | Missing | CodeMirror highlights editor source, but Register does not bundle a public article code renderer. |
| Preview plus explicit draft/publish choice | Available | The shared editor has live preview and explicit draft, scheduled, and immediate publication states. |
| Continuous browser crash recovery | Available | Editor content is periodically stored in `localStorage`. |
| Save with Ctrl/Cmd-S | Partial | Ctrl-S is implemented; modifier handling is not fully uniform across platforms. |
| Scheduled publishing | Available | The editor stores a future publication time; the request-driven shutdown queue publishes due content in bounded batches and updates dependent views and search. |
| Backdated publishing | Available | The creation timestamp is editable. |
| Rename a media file from the editor | Partial | Available through the picture manager rather than directly on an editor media fragment. |
| Ten bundled themes | Partial | Register bundles three first-party choices: Register, Oldschool, and Pixel Forest. |
| Bundled dark themes | Available | The Register theme follows the operating-system light/dark preference. |
| Custom themes and view overrides | Available | Themes, templates, views, and extensions are replaceable. |
| CSS variables and responsive layout | Available | Used by the bundled Register theme. |
| Extra HTML wrappers without replacing a theme | Partial | Achievable through template overrides, but not exposed as a dedicated setting. |
| Global custom CSS / embedded commercial theme | Missing | No dedicated product feature. |
| Theme component preview page | Missing | No first-party catalogue of theme elements. |

## Typography, drafts, and comments

| Aegea capability | Register status | Notes |
|---|---|---|
| Automatic typography | Available | The built-in Typography module processes rendered HTML and RSS. |
| Nested quotes, dashes, non-breaking spaces, and abbreviation protection | Available | Implemented by the typographer. |
| Move quotation marks outside links | Available | Implemented. |
| Typography traditions for every interface language | Partial | Typography follows the active locale and is idempotent. Russian rules are bundled; content in other locales is preserved until a matching ruleset is added. |
| Typography in posts, comments, and subtitles | Available | Applied to the final rendered page. |
| Create and list drafts | Available | Unpublished records are stored and filterable in the control panel. |
| Draft thumbnails in the draft list | Missing | No specialised draft-card view. |
| Social-card preview for a draft | Missing | No Open Graph/social preview pipeline. |
| Return a published item to drafts | Available | Publication can be disabled without deleting the item. |
| Preserve date and comments when hiding an item | Available | Unpublishing does not delete either. |
| Author-only hidden item on the public site | Partial | Authors can access unpublished content in the control panel, but there is no separate public hidden mode. |
| Secret public link to a draft | Missing | No preview token or public draft route. |
| Per-item and global comment controls | Available | Both global and content-level settings exist. |
| Built-in anti-spam | Available | Includes a challenge, local decisions, and Akismet integration. |
| Automatically close old discussions | Missing | Comment availability is not derived from post age. |
| Delete a comment with Undo | Missing | No undo workflow. |
| Sequential new-comment marker | Partial | Administrative counters exist, but not Aegea's sequential marker. |
| Comment formatting equivalent to posts | Partial | Comments have limited BBCode and preview, not the full article editor. |
| Social identities for commenters | Missing | No Twitter, Facebook, VK, or Telegram authentication. |
| Login-free emoji reactions | Available | A visitor can keep one of six reactions on a post or page; clicking it again removes it and choosing another switches it. |
| Configure or require social providers | Missing | No social-login provider layer. |
| Nested author replies | Available | Comments have parent identities and render as bounded-indentation threads with a no-JavaScript reply fallback. |
| Edit and moderate comments | Available | Supported in the control panel. |
| Mark a comment as important | Available | Stored using the comment quality flag. |
| Configurable maximum comment length | Partial | A fixed storage limit exists, but there is no product setting. |
| Keep commenter email private | Available | Display is controlled by the commenter. |
| Subscribe to and unsubscribe from a discussion | Available | Email notification and unsubscribe links are implemented. |
| Notify authors/moderators about new comments | Available | Implemented by the comment notification services. |
| Toggle author email in the footer | Missing | No dedicated setting. |
| Per-post view count | Missing | Register Analytics records site-level daily aggregates without raw IP or User-Agent storage rather than a counter for each post. |

## Navigation, recommendations, and broadcasting

| Aegea capability | Register status | Notes |
|---|---|---|
| Configurable main menu | Partial | Register has a page tree and blog navigation, but no equivalent menu composer. |
| Drag menu items directly in the public menu | Missing | Ordering is managed in the control panel. |
| Favourites, tags, and calendar navigation | Available | Corresponding pages and routes exist. |
| Popular, hot, and random-item navigation | Missing | No first-party popularity model or random route. |
| Promote selected tags into navigation | Partial | Possible through navigation/templates, but not a dedicated setting. |
| Year, month, and day calendar | Available | Implemented by the built-in Blog module. |
| Semantic related-content recommendations | Available | Implemented through the search index on SQLite, MySQL/MariaDB, and PostgreSQL. |
| Adaptive recommendations with images | Available | Recommendation snippets account for available images. |
| Restrict recommendations to favourites | Missing | The favourite flag is not part of recommendation ranking. |
| Recommendation intercuts in the home feed | Missing | Recommendations are attached to content pages. |
| Popular-content fallback for recommendations | Missing | Popularity is not calculated. |
| Popular gallery for a tag | Missing | No first-party implementation. |
| Built-in social share buttons | Missing | Can be added by a theme or extension. |
| Image-aware Pinterest sharing | Missing | No built-in sharing pipeline. |
| Social/YouTube/TikTok subscription popup | Missing | No first-party implementation. |
| Main RSS feed | Available | Provided by the unified Content and Blog modules. |
| Configurable RSS size | Available | Controlled by the common item limit. |
| Tag and search-result RSS feeds | Missing | No dedicated routes. |
| JSON Feed | Missing | No JSON Feed route or serializer. |
| JSON representation of a single item | Missing | No public content JSON route. |
| Podcast feed, artwork, and Apple metadata | Missing | No podcast subsystem. |
| Synchronisation with the Blogs aggregator | Missing | No first-party integration. |
| Dedicated social image / cover | Missing | No social-card data model. |
| SEO descriptions for site, tag, and item | Partial | Items have `meta_desc`; site/tag/social metadata is not equivalent. |
| Telegram Instant View support | Missing | No first-party integration. |
| Google Analytics and Yandex Metrica settings | Missing | No dedicated configuration fields. |
| Arbitrary analytics | Partial | Can be added through a custom template or extension. |

## Tags, search, archives, and languages

| Aegea capability | Register status | Notes |
|---|---|---|
| Assign, display, and globally edit tags | Available | Implemented. |
| Unlimited tags | Available | No explicit product limit. |
| Custom tag URL | Available | Tags have an editable URL. |
| Rich tag introduction | Partial | Tags have an HTML description, but not Aegea's complete intro/social model. |
| Hidden tags | Missing | The tag schema has no visibility flag. |
| Semantically related tags | Partial | Morphological/prefix matching exists; there is no semantic tag graph. |
| Tag RSS and JSON feeds | Missing | No dedicated feed routes. |
| Built-in full-text search | Available | Implemented with Rose. |
| Russian and English morphology | Available | Russian uses bundled OpenCorpora dictionary lemmatization with Porter fallback; English uses Porter. The behavior is identical on SQLite, MySQL/MariaDB, and PostgreSQL. |
| Tag suggestions | Partial | Autocomplete and morphological matches exist. |
| Start searching by typing anywhere | Missing | The search field must be focused. |
| Boost favourites in search ranking | Missing | The favourite flag is not indexed as a ranking signal. |
| Image and YouTube snippets in search | Available | Implemented. |
| Search RSS and JSON feeds | Missing | No dedicated routes. |
| Open search in a new window when initiated from a form | Missing | No context-specific target behaviour. |
| Automatic search indexing | Available | Index updates are queued automatically. |
| Generated snippets in tags and archives | Partial | Excerpts are shown, but those pages do not use the full search snippet generator. |
| Favourite marker and page | Available | Implemented by the built-in Content and Blog modules. |
| Favourites influence search/recommendations | Missing | Not used as a ranking input. |
| Monthly popular/hot pages | Missing | Per-item popularity is not stored. |
| Popular gallery on the 404 page | Missing | No first-party implementation. |
| Year, month, and day archives | Available | Implemented by blog routes. |
| Calendar archive navigation | Available | Implemented. |
| `/all` archive | Available | `/all/` is a compact technical index of every published post. |
| Configurable popularity period | Missing | No popularity subsystem. |
| Russian and English interface | Available | Bundled. |
| French, Italian, Ukrainian, and Belarusian interface | Missing | Not bundled. |
| Arbitrary UTF-8 content | Available | Supported throughout the application. |
| Extensible translation system | Available | Additional translation loaders can be registered. |
| Plural forms | Available | Supported by the translation layer. |
| Localised dates | Available | Date formats are supplied by translations. |

## Keyboard workflow and URL lifecycle

| Aegea capability | Register status | Notes |
|---|---|---|
| Alt-E to edit | Missing | No matching shortcut. |
| Ctrl-S to save | Available | Implemented by the editor. |
| Enter moves from title to body | Partial | Register moves between form fields, but the exact workflow differs. |
| Ctrl-Enter saves and previews | Missing | No matching shortcut. |
| Google Docs-style formatting shortcuts | Partial | Several Ctrl-based formatting shortcuts exist; the set and macOS handling differ. |
| Keyboard navigation to previous/next item and archive | Partial | Ctrl/Meta navigation exists, but not every Aegea Alt mapping. |
| Clipboard image paste | Available | Implemented. |
| Alt-drag media replacement | Missing | No matching interaction. |
| Custom item and tag URLs | Available | Both are editable. |
| Short post permalinks | Available | Posts use `/<slug>` at the site root; dates remain archive navigation only. |
| Automatic title transliteration | Available | New posts receive an ICU-transliterated slug when `intl` is installed and a portable PHP fallback otherwise. |
| Automatic redirects from every previous URL | Missing | URL history is not stored. |
| Manually configured redirects | Available | Supported by the redirect map. |

## Administration, installation, and operations

| Aegea capability | Register status | Notes |
|---|---|---|
| Browser-based login | Available | Implemented. |
| Password-only login without a username | Missing | Register requires both login and password. |
| Indefinite remembered login | Partial | Cookie sessions and a configurable timeout exist, but sessions are not unconditional and permanent. |
| “Foreign computer” session option | Available | The “Shared computer” option creates session-only authentication cookies. |
| Brute-force rate limiting | Available | Failed attempts are limited by hashed IP and login buckets without storing their raw values. |
| Author photo and settings-managed favicon | Missing | A favicon can be supplied by a theme; there is no equivalent author profile feature. |
| Multiple device sessions with revocation | Available | Active sessions can be inspected and revoked. |
| Password reset through email or a file | Missing | No first-party reset workflow. |
| Inline administration without a separate control panel | Missing | Register uses the `_admin` application. |
| Multiple users and role-based permissions | Available | This is a Register capability beyond Aegea's advertised single-author workflow. |
| Web installer | Available | Creates configuration and database tables. |
| Environment and permission diagnostics | Available | Performed during installation. |
| MySQL/MariaDB, PostgreSQL, and SQLite | Available | All three database families are supported. |
| Table prefixes and multiple installations per database | Available | Supported. |
| Production database settings from environment variables | Missing | Production configuration is file-based. |
| Upgrade an existing Register database | Missing | The pre-release product supports one clean schema generation and intentionally rejects old generations; an explicit importer can be added later. |
| Import an existing Aegea database | Missing | No importer. |
| Semi-automatic code and database update | Missing | Code deployment and future data import are external; Register currently performs no in-place product migration. |
| Automatic database backup | Available | A durable request-driven queue job creates a private daily authenticated encrypted database-and-media archive and prunes it to configured retention; the CLI drain covers hosts without response detachment. |
| Downloadable backup ZIP | Available | Administrators can create or download the latest encrypted ZIP envelope in Search & statistics; the CLI offers creation and offline decryption. |
| Continuous incremental backup | Missing | No first-party implementation. |
| HTTP and HTTPS | Available | Supported, including optional forced HTTPS for administration. |
| Modern PHP support | Available | Register requires PHP 8.3 and is checked for PHP 8.3–8.5 compatibility. |
| Sitemap | Available | `/robots.txt` advertises a standards-compliant sitemap index containing published canonical pages and posts, split into bounded XML files. |
| Merge and minify CSS/JavaScript | Available | Registered assets can be merged, minified, hashed, and compressed. |
| Configurable mail sender | Available | Uses the configured webmaster email. |
| Known-vulnerability monitor in the application | Missing | Dependency scanning is available in development/CI, not as an admin feature. |
| True 404 response at the requested URL | Available | No redirect is required. |
| Upload-directory quota | Available | The total stored size is enforced under a filesystem lock and configured with `files.upload_quota_bytes`. |
| Automatic and manual pretty-URL fallback | Available | The installer detects a suitable prefix and configuration can override it. |
| Per-item timezone | Missing | Content records do not store a timezone. |
| Canonical-domain redirect | Partial | A canonical URL is generated, but the application does not enforce a host redirect. |
| General database and formatted-text cache | Partial | Routes, extensions, assets, and recommendations are cached; there is no equivalent general cache layer. |
| Commercial licence-expiry indicator | Not applicable | Register is distributed under the MIT licence. |

## Register capabilities that should be preserved

Closing selected product gaps should not weaken the capabilities that distinguish Register:

- multiple users and role-based permissions;
- MySQL/MariaDB, PostgreSQL, and SQLite support;
- mandatory base modules plus a separate API for optional integrations;
- morphological search and related-content recommendations;
- lightweight server requirements and high performance;
- PHP 8.3–8.5 compatibility and the strict automated quality gate;
- an isolated one-command local development environment.

## Candidate backlog

The following groups are candidates for product discussion. Their order is based on expected user value
and implementation scope; it is not an approved roadmap.

### Smaller, high-value increments

- add JSON Feed plus tag/search feeds;
- add social/SEO metadata and a social-card preview;
- track URL history and create automatic redirects;
- add per-item view counts and basic popular-content routes;
- make editor shortcuts consistent across Windows, Linux, and macOS.

### Medium product projects

- hidden items and secret draft-preview links;
- a configurable main-menu composer;
- first-party share controls and analytics settings;
- richer media blocks, captions, galleries, and replacement workflows;
- configurable age-based comment closing;
- offline-safe editing.

### Large product projects

- a Markdown/block authoring model;
- social identity and provider management for comments;
- podcast publishing;
- a broader bundled theme catalogue;
- French, Italian, Ukrainian, and Belarusian interface packs;
- a full popularity/recommendation model that combines views, favourites, recency, and tags.

## Evidence map

The main implementation anchors used during review are:

- editor persistence and preview: [`_admin/js/editor/form.js`](../_admin/js/editor/form.js);
- editor image pipeline: [`_admin/js/editor/images/pipeline.js`](../_admin/js/editor/images/pipeline.js);
- default upload formats: [`_include/src/Config/StaticConfigLoader.php`](../_include/src/Config/StaticConfigLoader.php);
- content, comment, tag, session, and user schema: [`_include/src/Model/Installer.php`](../_include/src/Model/Installer.php);
- comment subscriptions: [`_include/src/Model/CommentNotifier.php`](../_include/src/Model/CommentNotifier.php);
- authentication and sessions: [`_include/src/Model/AuthManager.php`](../_include/src/Model/AuthManager.php);
- login rate limiting: [`S2\Cms\Model\LoginRateLimiter`](../_include/src/Model/LoginRateLimiter.php);
- scheduled publication: [`Register\Content\ContentPublicationScheduler`](../_include/src/Register/Content/ContentPublicationScheduler.php);
- threaded comments: [`S2\Cms\Model\Comment\CommentThreadBuilder`](../_include/src/Model/Comment/CommentThreadBuilder.php);
- typography: [`Register\Module\Typography\Typograph`](../_include/src/Register/Module/Typography/Typograph.php);
- formula rendering: [`Register\Module\Math\Module`](../_include/src/Register/Module/Math/Module.php);
- search and indexing: [`Register\Module\Search\Module`](../_include/src/Register/Module/Search/Module.php);
- recommendations: [`Register\Module\Search\Service\RecommendationProvider`](../_include/src/Register/Module/Search/Service/RecommendationProvider.php);
- blog routes and archives: [`Register\Module\Blog\Module`](../_include/src/Register/Module/Blog/Module.php);
- anonymous identity and reactions: [`Register\Module\VisitorIdentity\Module`](../_include/src/Register/Module/VisitorIdentity/Module.php) and [`Register\Module\Reactions\Module`](../_include/src/Register/Module/Reactions/Module.php);
- full post index: [`Register\Module\Blog\Controller\AllPostsController`](../_include/src/Register/Module/Blog/Controller/AllPostsController.php);
- daily and manual backups: [`Register\Backup\BackupManager`](../_include/src/Register/Backup/BackupManager.php);
- asset processing: [`_include/src/Asset/AssetMerge.php`](../_include/src/Asset/AssetMerge.php);
- local development bootstrap: [`tools/dev-bootstrap.php`](../tools/dev-bootstrap.php).

When a listed capability changes, update both its status and notes, and add or adjust an evidence link if
the implementation moved.

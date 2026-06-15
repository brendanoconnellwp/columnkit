=== ColumnKit ===
Contributors: brendan
Tags: admin columns, custom fields, list table
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.6.0
License: GPLv2 or later

Customise WordPress admin list tables: add, remove, reorder columns; filter, sort, inline-edit, bulk-edit, and export them.

== Description ==

Personal plugin for replacing paid SaaS column-management plugins. v0.1 ships Phase 1 (custom columns + admin UI on Posts and Pages). Later phases add sorting/filtering, inline & bulk edit, export, and integrations with ACF, WooCommerce, and Yoast.

== Changelog ==

= 0.6.0 =
* Added: Column Sets (saved views) — define multiple named column layouts per screen (e.g. "SEO view", "Editorial view") and switch between them from a dropdown above the list table. Each user's choice is remembered per screen. Inline-edit, bulk-edit, and export all follow the active view.
* Added: per-column display formatting — set width, text alignment, a text prefix/suffix, and an optional coloured badge/pill (text + background colour) per column. Prefix/suffix carry through to CSV/JSON export.
* Improved: settings page redesign — collapsible column rows with live summaries, a searchable "Add column" picker with type descriptions, per-field inline help, a colour picker for badge colours, and a sticky Save bar.
* Added: Users & Taxonomies parity — user-meta and term-meta columns are now sortable (by clicking the header), inline-editable (click a cell to edit), and exportable to CSV/JSON, matching the post-screen feature set. (Term filtering is not yet available — WordPress exposes no hook to render a filter bar on the term list.)
* Changed: per-screen settings storage migrated from a flat column list (schema v1) to named sets (schema v2). Existing configurations migrate automatically on first read; no action required.

= 0.5.3 =
* Fixed: core Title/Date/Author inline edit silently no-op'd on hierarchical list tables (Pages and hierarchical CPTs) because per-row data was skipped for non-WP_Post query rows.
* Fixed: an ACF true/false field storing "No" rendered as a blank cell instead of "No".

= 0.5.2 =
* Added: inline & bulk edit for ACF, JetEngine, and Meta Box field columns (safe scalar field types — true/false, text, number, single select/radio, plain dates); complex/array/multi-value/clone fields remain read-only.

= 0.5.1 =
* Initial release: add/reorder columns on Posts/Pages/CPTs/Media/Users/Taxonomies; sort, filter, click-to-edit popover, bulk edit, CSV/JSON export; settings import/export; ACF/Meta Box/JetEngine/WooCommerce/Yoast integrations.

= 0.1.0 =
* Phase 1 foundation: column registry, settings page, post-ID / post-meta / taxonomy / featured-image / author columns on Posts and Pages list tables.

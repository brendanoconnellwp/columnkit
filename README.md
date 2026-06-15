# ColumnKit

A personal WordPress plugin that brings Admin Columns Pro–style list-table customisation to the admin: add/reorder columns on Posts, Pages, CPTs, Media, Users, and Taxonomies; sort and filter; click-to-edit values in place; export to CSV/JSON; back up settings as JSON. Conditional integrations with ACF, WooCommerce, and Yoast SEO.

Built as a six-phase exercise, with security and performance pitfalls documented inline at every step. **Not a published plugin** — built for one user, runs on their LocalWP dev site, source-controlled in their workspace.

---

## What it does

| Surface | Feature |
|---|---|
| Settings page (`Settings → Admin Columns`) | Pick a screen → choose/create a **view** → add / remove / drag-reorder columns → save |
| Column Sets (saved views) | Define multiple named column layouts per screen ("SEO view", "Editorial view"…). Switch between them from a dropdown above the list table; each user's choice is remembered per screen. Inline-edit, bulk-edit, and export follow the active view |
| Per-column display formatting | Width, text alignment, prefix/suffix, and an optional coloured badge/pill (text + background colour) — set per column in the row's **Display** panel. Prefix/suffix flow through to export |
| Post / Page / CPT / Media list tables | Custom columns, sortable headers, filter inputs above the table |
| Click any editable cell | Inline-edit popover with type-appropriate input (text/number/date/boolean/select). Saves via AJAX, updates the cell without page reload |
| Quick-edit pencil on Title / Date / Author cells | Edit core fields without leaving the list |
| Bulk Edit | WP's native panel with our fields + apply-checkbox per column |
| Export buttons above the list | CSV or JSON of the current filtered/sorted view |
| Settings page | Export/import the full column configuration as JSON |
| ACF / Meta Box / JetEngine / WooCommerce / Yoast | Auto-detected; their fields show up as available column types when their host plugin is active |

---

## Quick start

1. Activate the plugin: `Plugins → ColumnKit → Activate`
2. Visit `Settings → Admin Columns`
3. Pick a screen (e.g. **Posts — Posts**, or **Users**), click **Add Column**
4. Configure (label, meta key, value type for post-meta columns) and **Save Columns**
5. Visit the list table for that screen — your columns appear

To inline-edit a value:
- Hover any cell → faint pencil on the right edge (or explicit pencil button on Title / Author)
- Click → popover opens with the appropriate input
- Enter to save, Esc to cancel, click outside to cancel

---

## Architecture

```
columnkit/
├── columnkit.php   # Plugin bootstrap + PSR-4 autoloader
├── uninstall.php               # Wipes ck_* options + sitemeta
├── readme.txt                  # WP-repo-style readme
├── composer.json               # Dev deps (phpunit, brain/monkey, mockery)
├── phpunit.xml.dist
├── assets/                     # CSS + JS (settings page, inline-edit popover)
├── languages/                  # .pot translation template
├── src/
│   ├── Plugin.php              # Singleton; wires everything
│   ├── ColumnRegistry.php      # type-slug → instance map
│   ├── Columns/                # ColumnInterface + 9 built-in types
│   ├── ListScreens/            # Screen dispatch, Sort/Filter/Edit/Export managers
│   ├── Settings/               # Repository + Sanitizer
│   ├── Admin/                  # SettingsPage, Assets, DataExporter, SettingsExporter
│   ├── Support/                # ScreenIdentifier
│   └── Integrations/
│       ├── ACF/                # ACFFieldColumn (one generic type, type-aware renderer)
│       ├── MetaBox/            # MetaBoxFieldColumn — discovers via rwmb_meta_boxes filter
│       ├── JetEngine/          # JetEngineFieldColumn — discovers via jet_engine()->meta_boxes
│       ├── WooCommerce/        # Price / Stock / SKU (products only)
│       └── Yoast/              # SEO score / Readability / Focus keyword
├── tests/
│   ├── bootstrap.php
│   ├── Unit/                   # PHPUnit + Brain Monkey (no WP load)
│   └── smoke/                  # wp-cli eval-file scripts against the live dev site
└── docs/
    ├── PITFALLS-phase1.md      # Foundation
    ├── PITFALLS-phase2.md      # Sort + filter
    ├── PITFALLS-phase3.md      # Inline + bulk edit (incl. 3c core columns)
    ├── PITFALLS-phase4.md      # Export + settings import/export
    ├── PITFALLS-phase5.md      # ACF / WC / Yoast integrations
    └── PITFALLS-phase6.md      # Users / Media / Taxonomies / i18n / multisite
```

### Key concepts

**Column types** are PHP classes implementing `ColumnInterface`. Each has a slug (`post_meta`, `user_role`, etc.), a label, a settings schema, a `render($object_id, $settings): string` method, and optional capability interfaces:

- `SortableColumn` — adds `apply_sort(WP_Query, $settings, $order)`; SortManager wires it via `pre_get_posts`.
- `FilterableColumn` — adds `filter_value_keys()` + `render_filter()` + `apply_filter()`; FilterManager renders inputs above the table and applies via `pre_get_posts`.
- `EditableColumn` — adds `get_raw_value()`, `get_edit_input_type()`, `get_edit_options()`, `render_bulk_edit_field()`, `save_value()`; EditManager handles the AJAX save endpoint + WP's bulk panel.

**Screen identification.** Every list-table kind has a stable internal key:

| Screen | Key |
|---|---|
| Posts / Pages / CPT | `post_type:{slug}` |
| Media library | `media` |
| Users | `users` |
| Taxonomy term list | `taxonomy:{slug}` |

`ScreenIdentifier::from_screen($wp_screen)` resolves the current screen to one of these. `ListScreenManager::on_current_screen` dispatches to the right hook registration based on the kind.

**Settings storage.** One option per screen, key = `ck_screen_{sanitised_screen_key}`, `autoload=false`. Columns are organised into named **sets** (saved views); `default` always exists. Payload shape (schema v2):

```php
[
  'schema_version' => 2,
  'screen_key'     => 'post_type:post',  // original key, since storage rewrites `:` → `_`
  'sets'           => [
    'default'  => [ 'label' => 'Default', 'columns' => [ ... ] ],
    'set_ab12' => [ 'label' => 'SEO view', 'columns' => [
      [ 'id' => 'col_abc', 'type' => 'post_meta', 'label' => 'Price',
        'settings' => [ 'meta_key' => '_price', 'value_type' => 'numeric' ], 'width' => '' ],
      ...
    ] ],
  ],
]
```

Legacy v1 options (a flat `columns` array, no sets) are migrated to v2 transparently on first read — the old list becomes the `default` set — and persisted in the new shape on the next save. `SetResolver` picks which set a viewer sees: `?ck_set=` overrides, otherwise the user's remembered choice (user meta), otherwise `default`.

### Hook timing

- `plugins_loaded` → load text domain, boot Plugin
- `current_screen` → ListScreenManager detects the screen and registers screen-specific hooks
- `manage_*_columns` (filter) → header injection
- `manage_*_custom_column` (action for posts/media, filter for users/terms) → cell rendering
- `pre_get_posts` (priority default 10) → SortManager and FilterManager apply
- `the_posts` → pre-warm post-meta cache for visible posts (no N+1 on render)
- `restrict_manage_posts` → FilterManager renders filter inputs, DataExporter renders export buttons
- `quick_edit_custom_box` / `bulk_edit_custom_box` → EditManager bulk panel fields
- `save_post` → EditManager handles bulk save (with WP's `bulk-posts` nonce)
- `wp_ajax_ck_inline_save` → EditManager handles single-cell AJAX save (with our `ck_inline_save` nonce)
- `admin_post_ck_export` / `admin_post_ck_settings_export` / `admin_post_ck_settings_import` → admin-post.php endpoints

---

## Adding a custom column type

Create a class extending `BaseColumn`:

```php
namespace Acme;

use ColumnKit\Columns\BaseColumn;

final class PostWordCountColumn extends BaseColumn {
    public function get_type(): string {
        return 'post_word_count';
    }
    public function get_label(): string {
        return 'Word Count';
    }
    public function applies_to_screen( string $screen_key ): bool {
        return str_starts_with( $screen_key, 'post_type:' );
    }
    public function render( int $object_id, array $settings ): string {
        $content = get_post_field( 'post_content', $object_id );
        $count   = str_word_count( wp_strip_all_tags( (string) $content ) );
        return esc_html( (string) $count );
    }
}
```

Register it on the `columnkit/register_columns` action:

```php
add_action( 'columnkit/register_columns', function ( $registry ) {
    $registry->register( new \Acme\PostWordCountColumn() );
} );
```

Implement extra interfaces (`SortableColumn`, `FilterableColumn`, `EditableColumn`) to opt in to those features.

---

## Testing

**Unit tests** (PHPUnit + Brain Monkey, no WP load):
```bash
composer install
vendor/bin/phpunit
```
Tests sanitisation, CSV escape, registry behaviour, column render logic with WP functions stubbed. 27 tests, 40 assertions.

**Smoke tests** (wp-cli `eval-file` against the live dev site):
```bash
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase1-render.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase2-sort-filter.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase3-inline-bulk-edit.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase3c-core-inline-edit.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase4-export.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase5-integrations.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase5b-metabox-jetengine.php
localwp-wp --site="AI Experiments" eval-file tests/smoke/phase6-users-media-terms.php
```
~150 smoke tests across the six phases — exercise real DB queries, AJAX endpoints, XSS payloads, SQL injection attempts, nonce/cap enforcement.

`CK_TEST_MODE` constant flips DataExporter and SettingsExporter to return-instead-of-exit so output can be captured.

---

## Pitfalls reviewed

One `PITFALLS-phaseN.md` per phase. Each entry follows the pattern: **What** (the trap) / **How** (what we did about it) / **Test/proof** (the assertion that catches a regression). Categories covered:

- **Security**: XSS escaping at render + at save, CSRF nonces (WP's + our own), per-action capability checks, SQL injection via `posts_clauses` (always `$wpdb->prepare`), CSV formula injection, header injection in download filenames, screen-key + column-type whitelists on JSON import.
- **Performance**: `autoload=false` on settings options, pre-warm post-meta cache on `the_posts`, gate `pre_get_posts` aggressively (admin + main query + post type match), accept large-table indexing trade-offs explicitly.
- **WP-specific**: hook timing (`current_screen` not `plugins_loaded`), `(array) $query->get('var')` returns `['']` not `[]` for unset vars (use `is_array()`), `WP_Query::query($args)` reinit clobbers pre-set vars, `is_main_query()` is false in CLI, additive column merge with priority 20, different signatures for posts vs users vs terms.
- **Data integrity**: `schema_version` field on options, duplicate-ID regeneration, type-aware sanitisation (numeric/date/boolean rejects bad input), empty inline-edit deletes meta (string/numeric/date) but stays unchanged (boolean) — documented convention.
- **Recursion**: AJAX save-post listener gated on `$_POST[INPUT_BULK]` presence so `wp_update_post` inside our handlers can't loop.
- **JS fragility**: avoid WP's `inlineEditPost.edit` override pattern (Phase 3b replaced it with a standalone popover); use `.text()` not `.html()` for user data.

---

## What's NOT here vs. Admin Columns Pro

Deliberate v1 trade-offs:

- **No custom-field UI on the post edit screen.** Defer to ACF / Meta Box / Pods / soon-to-be-core. The plugin reads / writes existing meta values; the field UI is somebody else's job.
- **No sort / filter / inline-edit / export on Users + Taxonomies.** WP user/term queries use `WP_User_Query` / `WP_Term_Query`, not `WP_Query`; our managers only hook `pre_get_posts`. Display-only on those screens.
- **No WooCommerce HPOS Orders columns.** HPOS uses a separate `wc_orders` table with its own list-table mechanics. Products work normally.
- **No network-admin settings sync on multisite.** Workaround: export/import JSON on each site.
- **No comment list-table support.**
- **No Toolset / Events Calendar / Pods integrations.** ACF + Meta Box + JetEngine + Woo + Yoast cover the user's installed plugins; the rest can be added on the same Loader/FieldColumn pattern.

---

## Compatibility

- WordPress: 6.0+ (declared in plugin header, tested against 6.9.4)
- PHP: 8.0+ (declared); developed on PHP 8.5.5 for tests, 8.2.29 in the LocalWP runtime
- MySQL: anything WP supports
- Multisite: per-site only (no network admin UI)

---

## License

GPL-2.0-or-later. Use it, fork it, hack it, share it. Don't sell it as Admin Columns Pro — that'd be tacky and they earned their thing.

# Phase 5 — Pitfalls Reviewed

**Integrations: ACF, WooCommerce, Yoast SEO.**

Each integration is a self-contained subfolder under `src/Integrations/{Name}/`, with a `Loader` that detects whether the host plugin is active and only registers its columns if so. All three live alongside in the registry; deactivation of any host plugin makes its columns silently absent on the next page load.

---

## Conditional loading

### 1. Detection via function/class existence — not version constants
**What:** Using `defined( 'WC_VERSION' )` to detect WooCommerce works today but breaks if the constant name changes (it actually was renamed once). Using `class_exists( 'WooCommerce' )` plus a key function check is more robust because the actual integration code depends on those symbols anyway.
**How:**
- **ACF**: `function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field_object' )`
- **WooCommerce**: `class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' )`
- **Yoast**: `defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' )` — either signal is enough because Yoast SEO and Yoast SEO Premium both define them.
**Test/proof:** smoke tests "ACF is detected as active" / "WooCommerce is NOT active" / "Yoast is NOT active" — all pass.

### 2. Column classes are loadable even when the host plugin isn't
**What:** PHP autoloader pulls in column classes when they're referenced anywhere — including just to check `instanceof`. If `ProductPriceColumn`'s file referenced `WC_Product` at the top level (e.g. via `use WC_Product;` plus a typed property), loading it without WC active would fatal.
**How:** Integration column classes only call WC/Yoast/ACF functions *inside* methods, never at the class header. The `applies_to_screen()` and `render()` paths both check `function_exists()` / `class_exists()` defensively before calling.
**Test/proof:** smoke test "ProductPriceColumn instantiates without WC" and "ProductPriceColumn::render returns empty when wc_get_product unavailable" — both pass.

### 3. Stale configuration when a host plugin is deactivated
**What:** A user configures an ACF column, then deactivates ACF. On the next list-table load, `acf_field` is no longer in the registry. `ListScreenManager::render_cell` calls `$registry->get('acf_field')` → null → silently returns. The cell renders empty.
**How:** Already covered by Phase 1's defensive `if ( ! $col )` check. The settings UI doesn't show the orphaned configuration as broken — but it doesn't error either. Acceptable: re-activating the plugin restores the column.

---

## Defensive coding

### 4. `(array) $query->get('var')` returns `['']` for unset vars
**What:** `WP_Query::get()` returns `''` (empty string) for query vars that haven't been set. `(array) ''` is `[0 => '']` — a one-element array of empty string — not an empty array. Code that does `$existing = (array) $query->get( 'meta_query' ); $existing[] = $clause;` ends up with the empty string AS THE FIRST ENTRY of the meta_query, which silently corrupts downstream consumers.
**How:** Every column's `apply_filter` now uses `is_array( $raw ) ? $raw : []` instead of `(array) $raw`. Applies to PostMetaColumn, FeaturedImageColumn, TaxonomyColumn, all 3 WooCommerce columns, all 3 Yoast columns, and ACFFieldColumn. Caught while writing Phase 5 smoke tests — only surfaced because the tests call `apply_filter` directly on a fresh WP_Query (production flow goes through `pre_get_posts` which always initialises query_vars first).
**Test/proof:** smoke test "ACF apply_filter adds a LIKE meta_query clause" — passes after the fix.

---

## ACF integration

### 5. Field discovery cost
**What:** `discover_field_options()` calls `acf_get_field_groups()` then `acf_get_fields($group)` for each group. On sites with many field groups (e.g. dozens), this fires multiple option-table reads. Currently runs only on the **settings page** when rendering the column-type dropdown, so cost is borne by admins editing config — acceptable.
**How:** No caching for v1. If it becomes slow, hook `acf_get_field_groups` cache or pre-compute via `wp_cache_*`.

### 6. ACF Pro features (repeater, flexible_content) handled defensively
**What:** Repeater and flexible_content fields are ACF Pro features. On sites with ACF free, those types don't exist — but a column configured against them on a Pro site, then imported to a free site, would fail. We dispatch by field 'type' string; an unrecognised type falls through to the `text` case which JSON-encodes arrays.
**How:** `render_value()`'s switch has a `default` clause that JSON-encodes complex values. Repeater/flex columns from a Pro site display as JSON on a free site (not pretty, but doesn't fatal).

### 7. Field renamed or deleted — render fallback
**What:** A user configures column for `customer_phone`, later deletes that ACF field. `get_field_object()` returns null because the field no longer exists. Without fallback the column would silently produce empty cells across the table.
**How:** `render()` first tries `get_field_object()` (which uses ACF's renderers + formatting); if null, it falls back to `get_post_meta()` (raw value), so legacy data still appears.
**Test/proof:** smoke test "ACF column falls back to raw meta when field is not registered in ACF" — passes.

### 8. Sort uses `meta_value` (string) not `meta_value_num`
**What:** ACF doesn't tag a field's storage type. Number fields might be stored as strings in meta. Sorting numerically without CAST would alphasort: `"100" < "9"`.
**How:** ACFFieldColumn::apply_sort uses plain `meta_value` (alphabetical). For numeric ACF fields, users get alphabetical ordering. This is a v1 trade-off — a future enhancement could read the field's `type` and CAST for numbers. Documented in this pitfalls file.

---

## WooCommerce integration

### 9. HPOS Orders are deferred
**What:** WooCommerce 8.x+ uses High-Performance Order Storage (HPOS) — orders live in `wc_orders` table, not `wp_posts`. Our list-table-and-meta-based plugin can't trivially decorate WC's HPOS order screen.
**How:** Integration scope explicitly limited to Products (`post_type=product`), which still uses `wp_posts`. All WC columns declare `applies_to_screen( 'post_type:product' )`. Documented as out-of-scope until Phase 6 polish.

### 10. Price column uses `get_price_html()` which is already escaped
**What:** WC's `WC_Product::get_price_html()` returns a localised HTML snippet (currency symbol, `<ins>`/`<del>` for sale prices). Wrapping it in `esc_html()` would mangle the HTML.
**How:** We trust WC's output — it's a documented public API and WC sanitises currency/locale formatting upstream. Other integrations escape user-controlled values; WC's price-HTML is plugin-controlled and considered safe.

### 11. Stock column reads via `$product->get_stock_status()` not raw meta
**What:** WC has historically migrated stock data between `_stock_status` and the products table (and back). Using the high-level `wc_get_product()` + accessor methods insulates us from those migrations.
**How:** Render reads via `$product->get_stock_status()` and `->managing_stock()` / `->get_stock_quantity()`. Filter applies the meta_query against `_stock_status` (which WC still maintains for query compatibility — confirmed in WC 8.x source).

### 12. Currency formatting in export
**What:** `get_price()` returns a raw decimal. Exports should be machine-readable, so we use that. The list-table column uses `get_price_html()` for human display. Two values, two surfaces — intentional.

---

## Yoast SEO integration

### 13. Free + Premium share the same meta keys
**What:** Yoast SEO Premium adds features but uses the same `_yoast_wpseo_*` meta keys for the core SEO/readability/keyword data. Our detection accepts either.

### 14. Score buckets, not raw numbers, for filter UI
**What:** Yoast scores are 0-100. Filtering by exact value (e.g. "show posts with SEO score = 53") is rarely useful. Users want "show me posts that need improvement".
**How:** Filter UI is a 3-bucket select: Good (70+), OK (40-69), Needs work (<40). Mirrors how Yoast's own UI categorises scores.

### 15. Score column gracefully shows "—" for posts without Yoast data
**What:** A post created before Yoast was installed has no `_yoast_wpseo_linkdex` meta. An empty cell looks like "score 0" if rendered with a coloured badge.
**How:** YoastScoreColumn::render branches on empty meta and renders a neutral grey `—` badge so users can distinguish "not yet analyzed" from "scored low".

---

---

# Phase 5b — Meta Box + JetEngine

Added after Phase 6 at user request. Both store field values as standard post meta, so the architecture mirrors ACF — generic FieldColumn with auto-discovered settings dropdown, sort + filter via meta_value LEFT JOIN, read-only display (editing deferred to each plugin's own admin UI).

## Meta Box (metabox.io)

### 16. Field discovery via the public `rwmb_meta_boxes` filter
**What:** Meta Box doesn't expose a function like `acf_get_field_groups()`. The conventional way to enumerate registered fields is to call `apply_filters( 'rwmb_meta_boxes', [] )` — Meta Box's own UI uses the same mechanism.
**How:** `MetaBoxFieldColumn::discover_field_options()` runs that filter and walks each meta box's `fields` array, building a `field_id => "Name (type)"` map.
**Test/proof:** smoke test "discover_field_options finds Price field from filter" — registers a synthetic meta box via the same filter and confirms the column picks it up.

### 17. Render path uses rwmb_get_value but falls back to raw meta
**What:** `rwmb_get_value()` returns *formatted* output (image arrays with `url` keys, post-picker WP_Post arrays, etc.). For columns this is mostly what we want — but for fields whose meta box isn't registered on this request (e.g. listed via filter but the post-type check excludes us), `rwmb_get_value()` returns null. Without a fallback, the column would show empty cells even though the meta value exists.
**How:** Render tries `rwmb_get_value()` first; if it returns null/empty, falls back to `get_post_meta()` and renders the raw value.

### 18. Structural rendering of array values (images, post-pickers)
**What:** Meta Box returns arrays for image / file / post-picker / user-picker fields. Naïve `(string)` casting on an array gives "Array" and a notice.
**How:** `render_value()` branches by structure:
- `{ url, ID }` → wp_get_attachment_image / fallback img tag
- `{ ID, post_title }` → post title
- `{ ID, display_name }` → user display name
- numeric array → "a, b, c" flatten (first 5)
- scalar → esc_html

## JetEngine (Crocoblock)

### 19. Defensive method probing
**What:** JetEngine versions vary in their internal class shape (`jet_engine()->meta_boxes->get_registered_boxes()` is the modern API; older versions used different shapes). Calling a non-existent method fatals.
**How:** `discover_field_options` checks `isset( $engine->meta_boxes )` AND `method_exists(...)` before calling. Wraps the call in `try/catch \Throwable` for total safety. Returns empty array if anything looks off — UI silently shows no fields rather than breaking the settings page.

### 20. JetEngine stores meta as post_meta (no special accessor needed)
**What:** JetEngine doesn't have an analogue to `acf_get_value()` / `rwmb_get_value()` for reading field values. The values ARE just post meta keyed by the field name.
**How:** `render()` calls `get_post_meta()` directly. Simpler than ACF/Meta Box — no formatted-vs-raw distinction.

### 21. Attachment-ID-as-string is rendered as thumbnail when possible
**What:** JetEngine's "media" field type stores the attachment ID as a string in post meta. Rendering `"42"` as plain text would be a confusing display.
**How:** Render checks `ctype_digit()` on the value, then `wp_attachment_is_image()` on the ID, and renders a 40x40 thumbnail if both pass. Otherwise it falls through to esc_html — so non-image meta values (e.g. user IDs, large numbers) display as text.

## Out of scope (deferred)

- **ACF numeric/date type-aware sorting** — currently sorts alphabetically regardless of field type.
- **WooCommerce HPOS Order columns** — needs custom integration with WC's `OrdersTableQuery`.
- **Meta Box / JetEngine type-aware editing** — defer to each plugin's own admin UI per scope decision in feedback_scope_custom_fields memory.
- **Other integrations** mentioned in AC Pro's feature list but explicitly NOT in this build: Events Calendar, Pods, Toolset Types. The user's MVP scope was ACF + Woo + Yoast; Phase 5b added Meta Box + JetEngine.
- **Bulk edit for integration columns** — for ACF, defer to ACF's own bulk-edit features. WC stock could conceivably get bulk edit, but ranges/qty in bulk are tricky UX. Deferred.
- **Caching of ACF field discovery** — not needed until benchmarks show it matters.
- **Yoast tags column for the secondary keyphrases** (Premium feature) — same meta-key pattern; easy add later if asked.

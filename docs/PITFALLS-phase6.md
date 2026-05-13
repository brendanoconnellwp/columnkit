# Phase 6 — Pitfalls Reviewed

**Final polish: Media library, Users, Taxonomies, i18n .pot generation, multisite.**

---

## Screen-type dispatch

### 1. Three different hook signatures
**What:** WP's list-table column hooks use three different shapes:
- **Posts / Media**: `manage_{type}_posts_custom_column` / `manage_media_custom_column` — **action**, callback signature `($column_name, $post_id)`, echoes output.
- **Users**: `manage_users_custom_column` — **filter**, callback signature `($value, $column_name, $user_id)`, returns the HTML for WP to echo.
- **Taxonomies**: `manage_{tax}_custom_column` — **filter**, callback signature `($value, $column_name, $term_id)`, returns the HTML.

Confusing them silently does nothing — the action-as-filter pattern just lets `$value` pass through unchanged.
**How:** `ListScreenManager::on_current_screen` branches by screen kind and registers the appropriate hook with the matching callback (`render_cell` echoes, `filter_user_or_term_cell` returns). The branch is gated by `ScreenIdentifier::is_users()` / `is_media()` / `taxonomy()` / post-type fallback.
**Test/proof:** smoke tests "manage_users_custom_column returns role HTML (filter, not echo)" and "manage_media_custom_column echoes the post ID" — both pass.

### 2. Media uses `manage_media_*` not `manage_attachment_posts_*`
**What:** Attachments are posts (post_type=attachment), but the Media library uses `WP_Media_List_Table` which calls the `manage_media_columns` filter and `manage_media_custom_column` action — NOT the post-type-specific names. Hooking `manage_attachment_posts_columns` would silently no-op on the Media screen.
**How:** `ScreenIdentifier::from_screen` checks `$screen->base === 'upload'` FIRST (before the post-type branch). Attachments visible on /upload.php route through media-specific hooks. Attachments visible on a hypothetical CPT-style page would still take the post-type branch.

### 3. `applies_to_screen` gates which column types appear in the settings UI
**What:** Without screen-aware filtering, a user on the Users screen would see a "Featured Image" option and a "WooCommerce Price" option in the column-type dropdown. Adding them would silently fail (column's render returns empty because get_post_meta with a user_id is wrong).
**How:** Every column class declares which screens it supports via `applies_to_screen($screen_key)`:
- Post-based columns (PostMetaColumn, TaxonomyColumn, AuthorColumn, ACFFieldColumn, Yoast columns, WC columns): return true for `post_type:*` (+ `media` where applicable).
- User columns (UserMetaColumn, UserRoleColumn, UserPostCountColumn): return true only for `users`.
- Term columns (TermMetaColumn): return true only for `taxonomy:*`.
- FeaturedImageColumn: post_type:* only — NOT media (because media items ARE the images).

`SettingsPage::render_page` filters the type dropdown through `applies_to_screen` so users only see compatible options.
**Test/proof:** 9 smoke tests covering applies_to_screen for each column type × each screen kind — all pass.

### 4. Sort / filter / inline-edit / export are post-only in v1
**What:** WP's user and term queries don't use `WP_Query` — they use `WP_User_Query` (with `pre_user_query` hook) and `WP_Term_Query` (with `terms_clauses`). Our `SortManager` / `FilterManager` / `EditManager` / `DataExporter` are all wired to `pre_get_posts`, so they don't apply to user/term screens.
**How:** `wire_post_extras()` is only called for post screens (incl. media). For users + taxonomies we get display-only columns. Documented as v1 trade-off; would-be Phase 6b adds query-time hooks for those.

---

## XSS / data safety on new screens

### 5. User meta escaping
**What:** User meta is set by registered users, including ones with `edit_user` cap on themselves. A malicious user could plant `<script>` in their `description` or any custom meta key, and rendering it raw in an admin list would XSS the next admin viewing the Users screen.
**How:** `UserMetaColumn::render()` escapes string output with `esc_html()`; arrays/objects are JSON-encoded then `esc_html()`'d.
**Test/proof:** smoke test "UserMetaColumn escapes XSS in meta" — payload `<script>alert(1)</script>` is rendered as `&lt;script&gt;alert(1)&lt;/script&gt;`.

### 6. Role display name uses `translate_user_role`
**What:** Role display names come from `wp_roles()->roles[$slug]['name']` — set by plugins on `add_role()`. A plugin that registered a role with HTML in its name (rare but possible) would inject HTML into the column.
**How:** `UserRoleColumn::render()` runs every name through `translate_user_role()` (which is plain text lookup) and then `esc_html()` on the final joined string. Defence in depth.

### 7. UserPostCountColumn validates the post-type setting
**What:** A POST payload from the column-settings form could try to set `post_type` to `evil_type`. We then call `count_user_posts($id, 'evil_type', ...)` — WP's function ignores unknown types but the stored config is junk.
**How:** `sanitize_settings()` runs the post_type through `sanitize_key()` AND falls back to `'post'` if `post_type_exists()` returns false.
**Test/proof:** smoke test "UserPostCountColumn rejects unknown post type" — passes.

---

## i18n

### 8. Text domain consistency across the codebase
**What:** Every translatable string needs the same text-domain literal (`'columnkit'`). Mixed domains break translations silently.
**How:** All source files use the same literal. The `.pot` extraction tool (`wp i18n make-pot`) was run with `--domain=columnkit` and produced 132 translatable strings under that domain. The generated file declares `X-Domain: columnkit`.

### 9. `.pot` covers every translatable string in the plugin source
**What:** The .pot is the translation template — translators work from it. Strings missed at extraction time can't be translated until the next .pot regeneration.
**How:** `wp i18n make-pot . languages/columnkit.pot` walks every PHP file under the plugin root, extracts every `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()` etc. call into the .pot. Re-generating after each user-facing change keeps it fresh.
**Test/proof:** smoke test ".pot file declares text domain" and ".pot includes plugin name string" — both pass. File has 132 msgid entries.

### 10. Text domain loaded on `plugins_loaded`
**What:** Calling `__()` before the text domain is loaded silently returns the untranslated string. On WP 6.7+ it triggers a `_doing_it_wrong` notice about just-in-time loading.
**How:** Already done in Phase 1's bootstrap — `load_plugin_textdomain()` runs on `plugins_loaded` BEFORE the rest of the plugin boots.

---

## Multisite

### 11. Per-site option storage, no implicit network sharing
**What:** On multisite, `update_option`/`get_option` are scoped to the current site; `update_site_option`/`get_site_option` are network-wide. Mixing them up causes silent cross-site bleed.
**How:** `SettingsRepository` ONLY uses `update_option` / `get_option` — verified by smoke test "SettingsRepository never calls get_site_option" which greps the source file. Each site on a multisite network has independent column configs.
**Test/proof:** smoke test "SettingsRepository uses per-site update_option" — passes.

### 12. Network admin not in scope
**What:** A "Network Settings" page that lets a super-admin push a column config to all sites would be useful but is out of scope for v1.
**How:** Documented. Workaround: super-admins can export their settings JSON on one site and import it on others.

---

## Performance

### 13. UserPostCountColumn — `count_user_posts` runs a SELECT COUNT per row
**What:** On a Users list with 100 rows × User Post Count column, that's 100 separate `SELECT COUNT(*) FROM wp_posts WHERE post_author = X` queries.
**How:** Accepted for v1 since user-lists are usually small. If it becomes a bottleneck, a single grouped query (`SELECT post_author, COUNT(*) ... GROUP BY post_author`) could pre-warm an in-request cache. Tracked in this pitfalls doc as deferred.

### 14. Term-meta cache pre-warming not implemented
**What:** For posts we pre-warm via `update_meta_cache('post', $ids)` on `the_posts`. For terms, there's `update_termmeta_cache($term_ids)` — but `WP_Term_Query` doesn't expose a clean per-request post-query hook to walk visible terms before render.
**How:** Accepted as v1 trade-off. Term lists are typically smaller (categories/tags rarely exceed hundreds), and the per-row meta read is fast for those sizes. Document. Revisit if a user reports slowness.

---

## Out of scope (deferred)

- **Sort / filter / inline-edit / export for Users and Taxonomies** — would need `pre_user_query` and `terms_clauses` hooks; non-trivial refactor.
- **Network admin UI for multisite settings sync** — workaround: use the JSON import/export from Phase 4.
- **Comment list-table columns** — `manage_edit-comments_columns` is yet another signature; only useful for sites with heavy commenting.
- **Custom WooCommerce HPOS Orders list** — Phase 5 deferred this; still deferred.
- **User columns: registration date, email column with mailto, last-login** — easy adds, just not yet.
- **Term column for hierarchy/parent** — easy add.
- **Pre-warm term meta** — perf only.
- **Translation strings audit** — manually review the .pot to ensure all visible strings are translatable (some validation messages may have slipped).

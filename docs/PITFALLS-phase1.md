# Phase 1 — Pitfalls Reviewed

This document is the record of *common pitfalls that we explicitly addressed in Phase 1* (foundation: scaffold, registry, settings page, columns on Posts/Pages). It is a living doc — Phase 2/3/4/5/6 each add their own pitfall sections.

For each pitfall: **What it is**, **Why it bites**, **How we handled it here**, and **Test/proof**.

---

## Security

### 1. Output XSS via attacker-controlled meta values
**What:** `get_post_meta()` can return arbitrary user-submitted content (e.g. a value typed in a custom field). Echoing it raw into an admin list table is an XSS vector — admin XSS escalates to full site takeover.
**How:** Every column renderer escapes at the output boundary:
- `PostMetaColumn::format()` calls `esc_html()` on all string output.
- `TaxonomyColumn` escapes each term name with `esc_html()` before joining.
- `FeaturedImageColumn` uses `get_the_post_thumbnail()`, which escapes attributes itself.
- `AuthorColumn` escapes user fields with `esc_html()`.
- `ListScreenManager::filter_columns()` runs `wp_strip_all_tags()` on the user's label before WP echoes it as a column header.

**Test/proof:** `SanitizerTest::test_strips_html_from_label` verifies labels with `<script>` are stripped before storage; renderers escape on output regardless.

### 2. CSRF on settings save
**What:** Anyone tricking an admin into visiting a malicious page could submit the settings form on their behalf.
**How:** `SettingsPage::handle_save()` calls `check_admin_referer( self::NONCE )` before doing anything, and the form uses `wp_nonce_field()`.

### 3. Capability bypass on settings save
**What:** A logged-in subscriber could POST to `admin-post.php` if we forget to gate the handler.
**How:** `SettingsPage::handle_save()` checks `current_user_can( 'manage_options' )` before nonce check; same cap is required to load the settings page.

### 4. SQL injection via column-type slug or screen key
**What:** Our `SettingsRepository::configured_screens()` queries `wp_options` with a `LIKE`; user-controlled data anywhere near that query is dangerous.
**How:**
- Screen key flows into `option_name()` which sanitises to `[A-Za-z0-9_]` only.
- `$wpdb->prepare()` + `$wpdb->esc_like()` for the `LIKE`.
- Column type slugs are checked against the registry whitelist in `Sanitizer::sanitize_columns()`; unknown types are dropped.

### 5. Insecure deserialization via stored option payload
**What:** WP options are serialized. If an attacker can write arbitrary PHP-serialised payloads (e.g. by injecting through a vulnerable plugin) and we then trust the option's shape, they can crash or exfiltrate.
**How:** `SettingsRepository::get()` defensively re-normalises the loaded option into a known shape (`schema_version` int + `columns` array) regardless of what came back. We never `unserialize()` user data ourselves.

### 6. Open redirect after save
**What:** Using `wp_redirect()` with a user-supplied URL is a classic open-redirect bug.
**How:** `handle_save()` uses `wp_safe_redirect()` with a server-built `add_query_arg` URL — no user-controlled redirect target.

---

## Performance

### 7. N+1 meta queries per list-table row
**What:** A list table with 50 rows × 3 meta columns = 150 separate `get_post_meta` SQL queries if each row hits the DB.
**How:** `ListScreenManager::prewarm_meta_cache()` hooks `the_posts`, takes all visible post IDs, and calls `update_meta_cache( 'post', $ids )` — one query that primes the object cache. Subsequent `get_post_meta()` calls hit the cache.

### 8. Autoloaded option bloat
**What:** Adding an option without `autoload=false` means WP loads it on *every* request, including front-end requests where our settings are irrelevant. With dozens of per-screen options this gets expensive.
**How:** `SettingsRepository::save()` passes `false` for autoload. `register_activation_hook` does the same for `ck_version`.

### 9. Front-end bootstrapping cost
**What:** Booting all admin classes on the front-end wastes CPU.
**How:** `Plugin::boot()` gates admin-only services behind `is_admin()`. `ListScreenManager` registers on `current_screen` which is admin-only.

---

## WordPress-specific

### 10. Wrong hook timing
**What:** Hooking `manage_post_posts_columns` at `plugins_loaded` works, but you can't yet know which screen the user is on, so you'd end up hooking every post type unconditionally — slow and conflict-prone with other plugins.
**How:** We hook on `current_screen`, identify the screen, and only then add the manage-columns filter for that one post type.

### 11. Conflicting with other plugins' columns
**What:** Multiple plugins all filter `manage_post_posts_columns`. Late priority + heavy hands wipe each other out.
**How:** We hook at priority 20 (after most plugins' defaults at 10), and *append* to the columns array rather than replacing it. Users can hide unwanted core columns via Screen Options.

### 12. `manage_{screen_id}_columns` vs `manage_{post_type}_posts_columns`
**What:** Two different filter names exist and they don't mean the same thing. Hooking the wrong one silently no-ops.
**How:** For post list tables we use `manage_{post_type}_posts_columns` (the documented one for the edit-post.php list table).

### 13. i18n loaded too early
**What:** Calling `__()` before the text domain loads returns the untranslated string and breaks just-in-time translations on newer WP versions.
**How:** `load_plugin_textdomain()` is called on `plugins_loaded`, before any localised string is emitted to the user.

### 14. Multisite blast radius
**What:** Using `update_site_option()` on a multisite stores at the network level. Default expectation is per-site.
**How:** We use `update_option()` / `get_option()` — per-site by default. Multisite-aware sync is intentionally deferred to Phase 6.

### 15. Activation crashes if PHP version too low
**What:** PHP 7.x sites can't parse PHP 8 syntax — fatal on activation.
**How:** Plugin header declares `Requires PHP: 8.0`. WP refuses activation below that.

---

## Data integrity

### 16. Settings shape drift between versions
**What:** Storing a freeform array means a v2 change can break v1 reads.
**How:** `SettingsRepository` writes `schema_version` into every option. `get()` re-normalises; future migrations can branch on this.

### 17. Duplicate column IDs collide in the list table
**What:** Two columns with the same `id` would map to the same WP column key and overwrite each other.
**How:** `Sanitizer::sanitize_columns()` tracks used IDs and regenerates duplicates.

### 18. Untrusted column settings keys
**What:** The form posts `settings[meta_key]`, `settings[value_type]`, etc. An attacker could post `settings[__proto__]` or odd keys hoping we'll trust them.
**How:** Each column's `sanitize_settings()` whitelists keys from its own `settings_fields()` definition; everything else is dropped.

---

## Out-of-scope for Phase 1 (tracked in later phases)

- Sorting/filtering integrity (Phase 2: SQL safety in `pre_get_posts`, performance of meta sorts at scale).
- Inline-edit JS fragility — WP core's `inlineEditPost.edit` (Phase 3).
- CSV formula injection on export (Phase 4: prefix `=`, `+`, `-`, `@`).
- Plugin-version-compat for ACF / Woo / Yoast API changes (Phase 5).
- Network-wide settings, Users / Media / Taxonomy screens, .pot generation (Phase 6).

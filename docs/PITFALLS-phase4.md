# Phase 4 — Pitfalls Reviewed

**Data export (CSV / JSON)** and **Settings JSON import/export**.

---

## Security — Data export

### 1. CSV formula injection
**What:** A meta value like `=SUM(A1:A9)`, `+CMD()`, `@SUM(...)`, or `-1+1` is a *spreadsheet formula* — when opened in Excel / Google Sheets / Numbers, the cell evaluates and may execute commands like `DDE` (Excel) or external HTTP calls. An attacker who can write meta on any post (e.g. via a comment-form vuln) could plant a formula that fires the moment the admin opens the export. This is OWASP CSV Injection.
**How:** `DataExporter::csv_escape()` prefixes values starting with `=`, `+`, `-`, `@`, `\t` (tab), or `\r` (CR) with a literal single-quote so spreadsheets treat the cell as text.
**Test/proof:** unit test `DataExporterTest::test_csv_escape_neutralises_formula_starters` (11 cases) + smoke test "CSV formula-injection value is escaped" — both pass.

### 2. Capability check per post type
**What:** Export of post type X requires the same edit capability as the list table for X — using `manage_options` is too narrow (editors can't use it), while `read` is too broad.
**How:** `handle_export` resolves the post type's `cap->edit_posts` via `get_post_type_object()` and checks `current_user_can()` with that. Failure → `wp_die(403)`.

### 3. Nonce on export action
**What:** Export URLs are GET requests with all filter/sort state embedded. Without a nonce, any logged-in user could be tricked into triggering the export of sensitive data.
**How:** `render_export_buttons` adds `_wpnonce=…` to the URL; `handle_export` verifies via `check_admin_referer( 'ck_export' )`.

### 4. Header injection via filename
**What:** A user-supplied filename component in `Content-Disposition` is a header-injection vector (CRLF could split headers and inject a body).
**How:** Filenames are built server-side (`{post_type}-export-{timestamp}.{format}`) and run through `sanitize_file_name()`. No user input flows into them.

### 5. Buffer flushing before streaming
**What:** WP, themes, or other plugins may have called `ob_start()`. If we `header()` after that, output may already have been flushed → "headers already sent" warning; binary CSV could become corrupted by stray HTML.
**How:** `handle_export` walks `ob_get_level()` and calls `ob_end_clean()` until no buffers remain, *then* sends headers. Skipped in `CK_TEST_MODE` so smoke tests can capture the streamed output.

### 6. UTF-8 BOM at start of CSV
**What:** Excel on Windows ignores the `charset=utf-8` in the Content-Type and assumes the CSV is in the system codepage. Non-ASCII characters (accents, currency symbols, emoji) come out garbled.
**How:** First bytes written are `\xEF\xBB\xBF` (UTF-8 BOM). Excel recognises this and switches to UTF-8.
**Test/proof:** smoke test "CSV starts with UTF-8 BOM" — passes.

---

## Security — Settings import/export

### 7. `manage_options` cap + per-action nonces
**What:** Settings export reveals every column configuration (potentially leaking meta key names you'd rather not advertise); import lets a user write to options. Both need strict gating.
**How:** Both handlers verify `current_user_can('manage_options')` AND check a per-action nonce (`ck_settings_export` / `ck_settings_import`).

### 8. File upload validation
**What:** A malicious uploaded "JSON" file could be (a) a zip bomb, (b) a 100 MB attack to exhaust memory, (c) crafted PHP smuggled in via filename, (d) non-JSON garbage.
**How:**
- Size cap: `MAX_IMPORT_BYTES = 5 MB`. Larger → reject.
- `is_uploaded_file()` to ensure the file came from `$_FILES`, not an attacker-controlled local path.
- `json_decode()` → returns `null` for non-JSON, structural check (must have `screens` array) before any write.
- No filename trust; we only read `tmp_name` and immediately parse the content.

### 9. Screen-key allowlist on import
**What:** Imported screen keys map directly to option names (`ck_screen_{key}`). An attacker payload with `screen_key=evil:../../../some_other_option` could write to unrelated options.
**How:** `import_from_json` only accepts screen keys matching `^post_type:[a-z0-9_\-]+$`. Anything else is silently skipped.
**Test/proof:** smoke tests "import skips non-post_type screen keys" and "evil screen key NOT written to DB" — both pass.

### 10. Column type whitelist on import
**What:** Attacker JSON could declare column type `evil_attacker_type` hoping we'd serialize and store it.
**How:** Every imported screen runs through `Sanitizer::sanitize_columns()` (same code path as the manual-save UI). Unknown types are dropped silently. IDs are regenerated for collisions. Width is character-whitelisted. Settings keys are pruned to those each column class declares.
**Test/proof:** smoke test "import drops unknown column types" — passes.

---

## Data integrity

### 11. Screen key not derivable from option name
**What:** Storage replaces `:` with `_` (`post_type:post` → `ck_screen_post_type_post`). On readback we'd otherwise be unable to recover the original key, which would break round-trips in export.
**How:** `SettingsRepository::save()` now writes the original `screen_key` into the option payload. `configured_screens()` reads it back from each option (with legacy fallback to the option-name suffix).

### 12. Cache invalidation after import
**What:** `SettingsRepository` has a static cache keyed by screen_key. Importing without invalidation would mean future reads return stale data within the same request.
**How:** `save()` writes the new payload into `self::$cache[$screen_key]`. Multi-request: not an issue since the cache lives only for one PHP request.

### 13. Filter/sort preservation in data export
**What:** A user expects "Export CSV" to export *what they're currently seeing* — filtered, sorted, paginated subset. Export of all posts would surprise them on a 50,000-row table.
**How:** `render_export_buttons` includes all current `$_GET` (minus pagination + nonce/action) in the export URL. `handle_export` registers a one-shot `pre_get_posts` listener that re-applies our `FilterableColumn` and `SortableColumn` logic to the export query.
**Test/proof:** smoke test "filter-aware export: range [15..25] returns 1 post (Bravo)" — passes.

### 14. `WP_Query::query($args)` re-init clobbers pre-set vars
**What:** Same gotcha from Phase 2 — setting query vars before calling `query()` doesn't survive. Phase 4's export query has to apply filters via a real `pre_get_posts` hook, not via `$query->set()` before `query()`.
**How:** Already documented in Phase 2 pitfalls; export handler uses the same `pre_get_posts`-shim pattern.

---

## Test ergonomics

### 15. `CK_TEST_MODE` opt-out for buffer flushing + exit
**What:** Production export must flush buffers + call `exit` to avoid stray output corrupting binary downloads. Tests need to capture the output, which requires neither happening.
**How:** Both `DataExporter::handle_export` and `SettingsExporter::handle_export` check `defined('CK_TEST_MODE') && CK_TEST_MODE` and skip both behaviours when set. Single-line escape hatch; production codepath unchanged.

### 16. `handle_import` flow split for testability
**What:** The full upload flow depends on `is_uploaded_file()` which returns false in wp-cli / CLI test contexts. Direct testing would always fail.
**How:** Refactored the JSON-handling part into `import_from_json(string $json): int` — public, no upload requirement. `handle_import` is now a thin wrapper that validates the upload and calls `import_from_json` with the file contents.

---

## Out of scope (deferred to polish)

- **Export of attachments / users / taxonomies** — Phase 6 polish will extend list-table coverage; export comes along automatically.
- **Custom field "raw vs formatted" toggle** — currently exports use the human-readable rendered value with HTML stripped. A future setting could let users opt for the raw stored meta value.
- **Streaming JSON line-by-line (NDJSON)** — current export builds one JSON array which loads into memory client-side. For multi-MB exports NDJSON would be friendlier; defer until needed.
- **Chunked export for very large datasets** — `posts_per_page=-1` loads all matching posts. For 50k+ tables, batched query+stream would be better. Defer until a user complains.
- **Excel-friendly date columns** — date strings exported as-is. Excel will interpret YYYY-MM-DD but other locales may not.
- **Settings export from network admin (multisite)** — current export is per-site. Multisite polish in Phase 6.

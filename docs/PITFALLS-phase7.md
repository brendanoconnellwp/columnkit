# Phase 7 — Pitfalls Reviewed

**Column Sets (saved views)** — multiple named column layouts per screen, switchable from the list table.

---

## Data integrity

### 1. Lossless v1 → v2 migration
**What:** Existing installs store a flat `columns` array (schema v1). Reshaping storage into named `sets` must not lose a single user's configuration, and must not require a migration script that could half-run.
**How:** `SettingsRepository::normalise()` coerces *any* stored payload — v1 flat, v2 sets, or junk — into the canonical v2 shape on every read. A v1 payload's `columns` becomes the `default` set. Migration happens in memory on read; the v2 shape is only written back on the next `save_set()`, never during a GET. So a read-only request never mutates the DB, and a never-touched screen keeps working forever.
**Test/proof:** `SettingsRepositoryTest::test_v1_flat_columns_migrate_into_default_set`, `test_garbage_option_is_normalised`; smoke `phase7` "legacy v1 option reads back as schema v2".

### 2. The `default` set always exists
**What:** Code throughout the plugin asks for a screen's columns; if the requested set is missing (deleted, typo, stale `?ck_set`), it must resolve to *something* rather than blank the list table.
**How:** `normalise()` guarantees a `default` key (first in iteration order). `get_columns()` falls back requested-set → default → `[]`. `delete_set('default')` empties rather than removes it.
**Test/proof:** `test_get_columns_unknown_set_falls_back_to_default`, `test_delete_default_set_empties_but_keeps_it`.

### 3. Saving one set must not clobber its siblings
**What:** The settings form posts only the columns of the set being edited. A naive "replace the whole option" save would wipe every other view.
**How:** `save_set()` reads the current payload, replaces only the targeted set, and persists. The back-compat `save()` shim routes to the `default` set the same way.
**Test/proof:** `test_save_set_creates_and_preserves_other_sets`, `test_back_compat_save_targets_default_set`; smoke `phase7` "default set untouched by new set".

---

## Security

### 4. Set ids are slug-constrained, never reflected raw
**What:** Set ids flow into option payload keys, user-meta values, and `?ck_set` URLs. Unconstrained input there risks meta-key pollution or reflected junk.
**How:** `sanitize_set_id()` collapses anything outside `[a-z0-9_]` and caps length at 40; empty → `default`. Applied at every boundary: settings POST, the inline-edit AJAX `set` param, the export `ck_set` param, and `SetResolver`.
**Test/proof:** `test_sanitize_set_id_strips_unsafe_chars`, `test_sanitize_set_id_blanks_collapse_to_default`.

### 5. Set CRUD is cap- + nonce-gated
**What:** Creating/renaming/duplicating/deleting a view mutates stored options — needs the same protection as the rest of the settings page.
**How:** `handle_set_action` requires `manage_options` + `check_admin_referer( 'ck_set_action' )`, validates the screen key against `available_screens()`, and whitelists the `op`. Unknown op → `wp_die(400)`.

### 6. Inline-edit / export resolve columns by the *active* set
**What:** Column ids are unique within a set but a **duplicated** set carries the same ids. The inline-edit AJAX and the export endpoint re-fetch column config server-side; looking it up in the wrong set would edit/export the wrong column (or fail).
**How:** The active set id rides along: localised into `CK_INLINE.set` → posted as `set` → `EditManager` uses `get_columns($screen, $set)`; the export buttons preserve `?ck_set` → `DataExporter` reads it. Both run the id through `sanitize_set_id()`.
**Test/proof:** smoke `phase7` set-scoped resolution; manual: view a non-default set, edit a cell, confirm the right meta updates.

---

## UX / request handling

### 7. View switcher preserves the rest of the URL
**What:** Switching views must not drop the user's current filters, search, or sort.
**How:** The switcher's option values are built with `add_query_arg( 'ck_set', id )` against the current request URI, so every other query var is retained. Navigation is a plain GET (`list-screen.js` sets `location.href`), no form interference with WP's filter form.

### 8. Switching is sticky, but only on explicit choice
**What:** Remembering a view across visits is good UX; silently writing user meta on every page load is surprising and adds writes to read requests.
**How:** `SetResolver` persists to user meta **only** when `?ck_set` is explicitly present (an intentional switch). A bare visit just reads the remembered value. Resolution order: `?ck_set` (valid) → remembered (still exists) → `default`.
**Test/proof:** smoke `phase7` SetResolver precedence block (param honoured + remembered, bare visit reuses, invalid param falls back).

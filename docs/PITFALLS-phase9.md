# Phase 9 — Pitfalls Reviewed

**Users + Taxonomies parity** — meta sort, inline-edit, and CSV/JSON export on screens whose
queries are `WP_User_Query` / `WP_Term_Query`, not `WP_Query`.

---

## Security

### 1. Object-specific capability on every inline save
**What:** The inline-edit AJAX endpoint is shared across posts, users, and terms. Reusing the
post capability (`edit_post`) for a user or term edit would be wrong — and dangerous.
**How:** `EditManager::ajax_save_meta_object()` branches: users require `current_user_can( 'edit_user', $id )`, terms require `current_user_can( 'edit_term', $id )` (which maps to the taxonomy's `edit_terms`). The screen string is validated too — users must be `users`, terms must be a real `taxonomy:{slug}` (`taxonomy_exists`).
**Test/proof:** `MetaColumnEditTest` (save routing); smoke `phase9` inline round-trips; the cap calls are the proof.

### 2. Export caps match each object's list table
**What:** Exporting users/terms must require the same privilege as viewing that list table.
**How:** `UserListManager::handle_export` requires `list_users`; `TermListManager::handle_export` requires the taxonomy's `cap->manage_terms`. Both also verify a per-export nonce and whitelist the format. Filenames go through `sanitize_file_name()`; CSV cells through `DataExporter::csv_escape()` (formula-injection neutralisation, reused).

### 3. Meta-sort order is whitelisted
**What:** `order` flows from the URL into the query. Unsanitised it's a (minor) injection / logic vector.
**How:** Both managers coerce `order` to exactly `ASC` or `DESC` before setting it on the query, mirroring the post `SortManager`.

---

## Targeting / correctness

### 4. `get_terms_args` is global — gate it hard
**What:** Unlike `pre_get_posts` (one main query), `get_terms_args` fires for *every* `get_terms()` call on a page (parent dropdowns, other widgets, core). Blindly injecting `meta_key`/`orderby` would reorder unrelated term queries.
**How:** `TermListManager::apply_sort` returns the args untouched unless (a) we're in admin, (b) the manager is active for this request, (c) the queried taxonomy includes ours, and (d) `?orderby` starts with `ck_` and resolves to one of our columns. Anything else is a no-op passthrough.
**Test/proof:** smoke `phase9` "term sort ignores non-ck orderby".

### 5. Inline-save resolves columns by the active set, not the default
**What:** Same trap as posts (Phase 7) — a duplicated view shares column ids. A user/term edit must look the column up in the set the viewer is actually on.
**How:** The editable cell carries `data-ck-object` + `data-ck-id`; the JS sends `object`, `object_id`, `screen`, and `set`; `ajax_save_meta_object` calls `get_columns( $screen, $set )`. All run through `sanitize_set_id()` / screen validation.

### 6. One AJAX endpoint, three object types — post path untouched
**What:** Folding user/term editing into the existing `ck_inline_save` risks regressing the well-tested post + core-field flow.
**How:** The handler early-returns to `ajax_save_meta_object()` only when `object` is `user`/`term` (JS omits it for posts → `post`). The entire post branch — core Title/Date/Author, bulk edit, recursion guards — is byte-for-byte unchanged.

---

## JS / assets

### 7. Generalised row-id parsing without breaking posts
**What:** Post rows are `post-N`; user rows `user-N`; term rows `tag-N`. The popover previously hard-matched `^post-(\d+)$`.
**How:** Our user/term cells carry `data-ck-id` (and `data-ck-object`) explicitly, so the JS reads the id from the cell and only falls back to a loosened `/-(\d+)$/` row match for post cells that don't carry it. Posts keep working via the fallback; users/terms never depend on row-id shape.

### 8. Core-column editing stays posts-only
**What:** Title/Date/Author inline edit is meaningless on Users/Terms and its `coreData` footer print is `edit.php`-specific.
**How:** `Assets` only adds `coreColumns` + the `admin_footer-edit.php` hook on `edit.php`; users/terms get the same popover + endpoint but no core decoration.

---

## Known gap

### 9. No term filtering
`edit-tags.php` exposes no "above the table" action hook (the equivalent of `restrict_manage_posts`/`restrict_manage_users`) to render filter inputs, so term filtering is intentionally not implemented. Users get role/search via core; user *meta* filtering remains a future addition. Documented here and in the README rather than hacked in.

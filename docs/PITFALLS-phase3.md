# Phase 3 — Pitfalls Reviewed

**Click-to-edit popover (AJAX)** for single-cell inline editing, plus **WP's native Bulk Edit panel** for multi-row applies. Limited to `post_meta` columns in this phase.

Initial Phase 3 used WP's Quick Edit (`quick_edit_custom_box`) — replaced after user feedback because Admin Columns Pro doesn't use Quick Edit either. See `PITFALLS-phase3-history.md` if we ever need to remember the Quick Edit approach.

---

## Security — Inline edit AJAX endpoint

### 1. Standalone CSRF protection
**What:** `wp_ajax_ck_inline_save` is our own endpoint — WP doesn't auto-verify nonces on AJAX actions, the handler must do it.
**How:** First line of `EditManager::ajax_inline_save` calls `check_ajax_referer( 'ck_inline_save', '_ajax_nonce', false )` — `false` for the third arg so we control the response rather than letting WP `wp_die` raw. Failed nonce → `wp_send_json_error( ... , 403 )`.
**Test/proof:** smoke tests "invalid nonce returns success=false" and "invalid nonce: meta unchanged" — both pass.

### 2. Per-object capability check
**What:** Even with a valid nonce, the user might not be allowed to edit *this specific post* (authors can only edit their own, etc.).
**How:** `current_user_can( 'edit_post', $post_id )` is checked after the nonce, before any read or write.
**Test/proof:** smoke tests "anonymous user is rejected" and "anonymous user: meta unchanged" — both pass.

### 3. Column-ID whitelisting
**What:** A POST could ask to edit `col_id=__proto__` or any arbitrary string. Naively passing that to a registry lookup could surface unintended behaviour.
**How:** We look up the column config via the post's actual settings (`SettingsRepository::get_columns()` for the post's screen). If no matching `col_id` is configured on that screen, the handler returns `404`. Then we verify the resolved column type `instanceof EditableColumn` — non-editable types are refused with `400`.
**Test/proof:** smoke test "unknown col_id rejected" — passes.

### 4. Input sanitisation at the boundary (XSS-defence at save time, not render time)
**What:** If we trust the raw POSTed value and only escape on render, every consumer of that meta value downstream (REST API, front-end templates, exports) inherits the risk.
**How:** `EditManager::ajax_inline_save` runs `wp_unslash` + `sanitize_text_field` on the value *before* calling `save_value`. `sanitize_text_field` strips `<script>` / `<style>` tag contents along with any tags. The stored value is already-safe text.
**Test/proof:** smoke test "sanitize_text_field neutralises XSS at save time" — payload `"><script>alert(1)</script>` is stored as `">`, not as the script payload.

### 5. Type-aware value rejection
**What:** Submitting non-numeric data into a numeric column corrupts subsequent sort/filter behaviour.
**How:** `PostMetaColumn::save_value` rejects non-numeric input for `value_type=numeric`, rejects unparseable dates for `value_type=date`, etc. — see Phase 3 historical doc, behaviour preserved.
**Test/proof:** smoke test "non-numeric input does not change meta" — passes.

### 6. JSON response auto-escaping
**What:** Returning HTML-as-JSON has historically been an XSS vector when devs hand-build JSON.
**How:** We use `wp_send_json_success` / `wp_send_json_error` which apply `wp_json_encode` (with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` by default). The cell HTML returned in `data.html` is also already escaped by the column's render method.

---

## Security — Bulk Edit (WP native panel)

### 7. WP's `bulk-posts` nonce + our cap check
**What:** Bulk Edit submissions go through edit.php with WP's `bulk-posts` nonce. We layer on top.
**How:** `EditManager::on_save_post` verifies `wp_verify_nonce( $_POST['_wpnonce'], 'bulk-posts' )` and `current_user_can( 'edit_post', $post_id )` before doing anything. Without the nonce, the handler exits without writing.
**Test/proof:** smoke test "bulk: invalid nonce blocks save" — passes.

### 8. Explicit "apply this column" checkbox per column
**What:** Without an explicit apply checkbox, every column with a typed value would clobber the meta on every selected post — even columns the user didn't intend to bulk-set.
**How:** Each bulk-edit fieldset includes a `ck_bulk_apply[col_id]` checkbox. `on_save_post` skips any column not present in that array.
**Test/proof:** smoke tests "bulk: ticked column updates" and "bulk: un-ticked column unchanged" — all pass.

---

## Hook lifecycle

### 9. AJAX hooks must be registered at boot, not on `current_screen`
**What:** `current_screen` only fires for actual screens (edit.php, etc.). An `admin-ajax.php` request never matches; if we registered our AJAX handler there, the handler would never run.
**How:** `EditManager` splits hook registration into two phases. `register_global_hooks()` is called from `ListScreenManager::register_hooks` (which runs once at boot) and registers the AJAX action. `activate()` is called from `on_current_screen` and registers the bulk-edit + save_post hooks (which are only relevant on a list-table screen).

### 10. Column config lookup at AJAX request time
**What:** During the inline-edit AJAX request, we don't know which "active screen" the user is on — there is no screen, we're on `admin-ajax.php`. We need the column config anyway, to know what column type / settings to use.
**How:** `ajax_inline_save` looks up the column directly: get the post → derive `post_type:{type}` screen key → load settings → find entry by `col_id`. This means the AJAX path doesn't depend on activate having been called.

### 11. `save_post` recursion still avoided
**What:** Same as previous design — calling `wp_update_post` from inside a `save_post` handler is recursive.
**How:** `PostMetaColumn::save_value` only writes post meta (`update_post_meta` / `delete_post_meta`), neither of which triggers `save_post`. The `EditableColumn` interface documents that any future column type calling `wp_update_post` must remove our handler before doing so.

### 12. autosave/revision filtering
**What:** `save_post` fires for autosave drafts and revisions. Writing on those targets the wrong post.
**How:** `on_save_post` short-circuits when `wp_is_post_autosave` or `wp_is_post_revision` returns true.

---

## UX / popover behaviour

### 13. Popover positioning + viewport collision
**What:** A popover anchored to a cell can end up below the viewport bottom (especially on the last row) or off the right edge.
**How:** `position()` in `admin-inline.js` measures available space below and flips above when not enough room. Horizontal positioning clamps to keep the popover within `window.width - 12px`. Re-positions on `resize` and `scroll`.

### 14. Click-outside / Esc / Enter behaviour
**What:** Modal-like UX expectations: Esc cancels, Enter saves (for single-line inputs), click outside cancels.
**How:** Document `click.ck-edit` and `keydown.ck-edit` namespaced handlers are attached on open and removed on close. The click-outside handler uses `setTimeout(..., 0)` to defer registration past the current click event, otherwise the opening click would immediately close.

### 15. Cell content can be empty (visually un-clickable)
**What:** An empty `<span class="ck-cell">` has zero size and can't be clicked.
**How:** CSS adds `min-width:1em; min-height:1em` + `::before { content: '\200B' }` (zero-width space) on `:empty`, keeping the cell clickable while staying invisible.

### 16. Pencil affordance, but only on hover
**What:** Users need to know cells are editable, but a permanent pencil icon clutters the table.
**How:** CSS `::after` pseudo-element with pencil glyph appears only on `:hover`. Border highlight on hover gives a visual click affordance.

### 17. Avoiding accidental triggers on links / buttons inside cells
**What:** Some cell content includes links (e.g. featured-image cell could have a thumbnail link). Clicking those should follow the link, not open the editor.
**How:** The cell click handler checks `$(e.target).closest('a, button, input, select, textarea').length` and bails out if found. Native interactive elements always win.

---

## XSS / output safety on round-trip

### 18. data-attribute escaping when re-rendering
**What:** After AJAX save, JS updates `data-ck-raw` with the response value. If we passed the value unescaped, the JS would set it correctly via `.attr()` but a subsequent `outerHTML` read could re-introduce the raw value into the DOM as-is.
**How:** `.attr()` writes a plain attribute value (no DOM injection). When the page is later re-rendered server-side, `render_cell` always runs `esc_attr` again. Safe in both directions.

### 19. JS template strings — avoiding `innerHTML` with user data
**What:** Concatenating user values into HTML strings and assigning to `innerHTML` is a classic XSS vector.
**How:** All user-data in the popover uses `.text()` or jQuery `.val()`, never `.html()`. `.text()` is the DOM API that auto-escapes.

---

---

# Phase 3c — Inline edit for core columns (Title, Date, Author)

Added after Phase 3b at user request. The plugin now decorates WP's built-in `column-title`, `column-date`, and `column-author` cells with the same click-to-edit popover.

## Architecture

### 20. Client-side decoration vs. server-side output buffering
**What:** WP renders core column cells deep inside `WP_Posts_List_Table` — there's no clean PHP filter to modify their HTML. The two options are (a) server-side output buffering of the whole table, (b) client-side DOM manipulation on page load.
**How:** We use (b). On document ready, `decorateCoreColumns()` walks `#the-list tr[id^="post-"]`, looks up the row's post ID, finds the configured TDs by class (`td.column-title`, `td.column-date`, `td.column-author`), and wraps their inner HTML with `<span class="ck-cell ck-editable">`. The existing click handler picks it up unchanged.

### 21. Per-post raw values delivered via footer-printed JSON
**What:** JS needs the raw stored value of each field (post title, ISO date, author ID) to pre-fill the editor. The list-table query has already run by the time the page renders, but `wp_enqueue_scripts` fires before that — so `wp_localize_script` can't include this data.
**How:** `Assets::print_core_data()` hooks `admin_footer-edit.php`, calls `EditManager::collect_core_data()` to walk `$wp_query->posts`, and prints a small inline `<script>` setting `CK_INLINE.coreData = {...}`. By the time admin-inline.js's document-ready callback fires, the data is on the page.

## Security

### 22. Per-field capability gates
**What:** Generic `edit_post` is enough for changing a meta value, but switching `post_author` requires `edit_others_posts` (otherwise a user with write access to their own post could reassign it away from themselves to escape further scrutiny).
**How:** `CORE_FIELDS` declares an additional `cap` per field. `save_core_field` short-circuits with 403 if the per-field cap fails. Title and Date have `cap=null` (only `edit_post` required); Author has `cap='edit_others_posts'`.
**Test/proof:** smoke test "contributor cannot switch author (lacks edit_others_posts)" — passes.

### 23. Author target user must exist + be eligible
**What:** A POST could supply `value=999999` (non-existent user) or a banned/spam user ID. Saving that to `post_author` orphans the post.
**How:** `save_core_field` for author does `get_userdata( $author_id )` and `user_can( $user, 'edit_posts' )` — rejects with 400 if either fails.
**Test/proof:** smoke test "invalid author ID rejected" — passes.

### 24. Title cannot be empty
**What:** WP doesn't prevent an empty `post_title` at the DB level, but a list table full of `(Untitled)` rows is data-loss-flavored. Likewise, a leading-whitespace title looks "set" but is effectively empty.
**How:** `save_core_field` for title trims the value and rejects empty.
**Test/proof:** smoke test "empty/whitespace title rejected" — passes.

### 25. Date preserves time-of-day; only the date portion changes
**What:** A `<input type=date>` returns `YYYY-MM-DD`. Naively setting `post_date='2024-06-30 00:00:00'` loses the post's original time-of-day. For scheduled posts and posts ordered by date, that subtly changes ordering with other same-day posts.
**How:** `save_core_field` for date reads the post's existing time portion via `strtotime( $post->post_date )`, formats `H:i:s`, appends to the new date. Both `post_date` and `post_date_gmt` are updated via `wp_update_post` with `edit_date=true`.
**Test/proof:** smoke tests "post_date YYYY-MM-DD updated" and "post_date keeps original time-of-day" — both pass.

### 26. `wp_update_post` recursion safety
**What:** `wp_update_post` fires `save_post`. We have an `on_save_post` listener for bulk edit. If our AJAX core save triggered our own bulk handler, weird write-amplification or recursion could result.
**How:** Two layers of defence:
1. `on_save_post` returns early when `$_POST[INPUT_BULK]` is absent — AJAX requests don't set it, so the bulk handler wouldn't have fired anyway.
2. Belt-and-braces: `save_core_field` does `remove_action('save_post', [$this, 'on_save_post'])` before `wp_update_post`, then re-adds it.
**Test/proof:** smoke test "core save during AJAX does not trigger bulk write" — passes (a `ck_bulk` payload smuggled in $_POST is ignored).

## UX

### 27. Title and Author cells display content as a link — pencil button required
**What:** WP renders Title and Author cells with the visible text inside an `<a>`. Our cell-click handler exempts links so that real link clicks (e.g. "Edit this post") keep working — but that leaves users no clickable area for inline edit.
**How:** During decoration, we append a small `.ck-edit-trigger` `<button>` to the cell. The click handler exempts `button:not(.ck-edit-trigger)`, so the trigger button always opens the editor. CSS hides the button by default and reveals it on cell hover.

### 28. Surgical update after save preserves edit-trigger button
**What:** When AJAX returns new HTML and JS replaces the cell's innerHTML, the appended `.ck-edit-trigger` button would be lost.
**How:** Before `$cell.html(resp.data.html)`, we `.detach()` the trigger button; after, we re-append. Row-actions (Edit/Quick Edit/Trash links rendered by WP) are NOT preserved — they reappear on the next page load. Acceptable v1 tradeoff.

## Out of scope (deferred to polish)

- **Inline edit for Author** — needs recursion-safe `wp_update_post`, deferred.
- **Inline edit for Taxonomy** — needs multi-term UI, deferred.
- **Inline edit for Featured Image** — needs WP media frame, deferred.
- **Optimistic UI** (update cell first, rollback on AJAX error) — currently we wait for the server response. Acceptable for v1.
- **Multi-line text editing** (textarea + Shift+Enter to insert newline) — would need `<textarea>` input type. Easy add later.
- **Bulk edit via custom UX** (multi-row select + custom toolbar) — kept WP's native Bulk Edit panel for now; ACP's custom toolbar is a future-phase polish.

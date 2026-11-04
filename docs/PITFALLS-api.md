# Public API (v0.8.0) — Pitfalls Reviewed

**`ColumnKit\Api` v1** for companion plugins (BoardKit), plus the `columnkit/loaded` action.

---

## Security

### 1. Two copies of the save checks would drift apart
**What:** Api::save() needs exactly the checks `ck_inline_save` applies. Copying them means a future security fix lands in one path only.
**How:** The post branch of `EditManager::ajax_inline_save()` moved into `Support\InlineSaver` (`authorise_post()` + `save_column()`). The AJAX handler and `Api::save()` both call it. Error messages and status codes are unchanged.
**Test/proof:** `InlinePostSaveTest` (new — the post path had no unit coverage before; the existing suite passed even with InlineSaver un-imported) and `ApiTest`.

### 2. Role-restricted views must not leak through the API
**What:** The list table resolves a requested view with `SetResolver::allowed()`, silently falling back to the default view. An API that took `$set_id` at face value would let a companion plugin show or save columns of a view the user's role can't see.
**How:** `Api::sets()` returns `visible_sets()`. `Api::columns()` returns `[]` for an invisible view (not the default set's columns — the caller should fall back knowingly). `Api::save()` uses InlineSaver in strict mode: an invisible view is a 403 `ck_set_forbidden`. The AJAX path keeps its old fallback.
**Test/proof:** `ApiTest::test_columns_of_invisible_view_are_empty_not_the_default_set`, `test_save_into_view_user_cannot_see_is_403_not_default_fallback`, `InlinePostSaveTest::test_invisible_view_still_falls_back_to_default_on_the_list_table`.

### 3. Silent refusals must not be reported as success
**What:** `EditableColumn::save_value()` returns void, and some columns refuse writes by returning early (PostStatusColumn → publish without `publish_posts`). A companion's optimistic UI would show a move that never happened.
**How:** InlineSaver returns the raw value before and after the save. `Api::save()` returns 403 `ck_not_saved` when a change was asked for and the value didn't move. Saving the value a field already has is still success.
**Test/proof:** `ApiTest::test_silently_refused_write_is_reported_not_faked`, `test_saving_the_current_value_is_success_not_refusal`.

### 4. Screen key and post type must agree
**What:** `Api::save( 'post_type:page', …, $post_id_of_a_post )` would look up a page view's column config and run it against a post.
**How:** 400 `ck_screen_mismatch` unless `$screen_key === 'post_type:' . $post->post_type`.
**Test/proof:** `ApiTest::test_save_rejects_bad_request_missing_post_and_screen_mismatch`.

### 5. Sanitise by input type, once
**What:** The AJAX handler sanitised with `sanitize_text_field()` and then re-read `$_POST` for textareas. An API caller has no `$_POST`.
**How:** Callers pass the unslashed, unsanitised value; InlineSaver sanitises per `get_edit_input_type()`.
**Test/proof:** `ApiTest::test_sanitises_by_input_type`, `InlinePostSaveTest::test_textarea_keeps_newlines`.

## Compatibility

### 6. `true|WP_Error` needs PHP 8.2
**What:** The BoardKit spec wrote `Api::save(): true|\WP_Error`. The standalone `true` type is PHP 8.2+; ColumnKit supports 8.0.
**How:** Declared `bool|\WP_Error`, documented as never returning false.

### 7. Detect through the action, not `class_exists()`
**What:** Plugin load order is alphabetical by folder, so `class_exists( 'ColumnKit\Api' )` at file load works or fails depending on the companion's name.
**How:** `do_action( 'columnkit/loaded', Api::VERSION )` is the last line of `Plugin::boot()` (on `plugins_loaded`, priority 10). Companions listen at file scope or on an earlier priority.

### 8. Self-updating a git checkout destroys it
**What:** On a dev site where the plugin folder is a link to the repo, accepting an update makes WordPress delete the folder and unzip the release over it.
**How:** `Plugin::boot()` skips the updater when `GitHubUpdater::is_dev_checkout( CK_DIR )` (a `.git` entry exists).
**Test/proof:** `GitHubUpdaterTest::test_dev_checkout_detection`.

## Not covered by unit tests (smoke / manual)
- `tests/smoke/api-v1.php` runs the API against real posts, a real ACF select field and real roles.
- Manual: inline edit on Posts (text, select, textarea, core title) still behaves as in 0.7.1.

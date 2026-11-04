# Security Backlog

Living log of security findings and hardening work. Source: full three-part review of v0.7.0 (2026-10-14) covering
write paths (inline/bulk edit), read/export/settings paths, and the self-updater + supply chain.

Unlike the `PITFALLS-*.md` docs (which record what each phase *did* handle), this file tracks what is still open.
When an item ships, set its status to **Fixed (vX.Y.Z)** and leave it here as a record.

**Severity:** High = a lower-privileged user can change data or gain access they shouldn't · Medium = data
disclosure or account-takeover assist · Low = minor disclosure, capability mismatch vs. core, or hardening ·
Info = robustness / forward-compat.

**Threat model note:** every code-level item below needs a logged-in user. There is no unauthenticated attack
surface. On a site where only administrators log in, the code items are low practical risk; the supply-chain
items (S-*) apply to every site regardless.

---

## Supply chain / distribution

| ID | Sev | Status | Item |
|----|-----|--------|------|
| S-1 | High | Open (owner action) | **Enable 2FA on the GitHub account** (passkey / hardware key preferred). Whoever holds the account can ship code to every install. |
| S-2 | High | Open | **Protect the release path.** Ruleset on `main` (no force-push, no deletion, PR required), tag ruleset on `v*` (restrict create/update/delete), enable immutable releases. Today: no branch protection, no rulesets, releases mutable. |
| S-3 | High | Open | **Sign release zips.** Ed25519 key kept offline (or in an Actions environment with required reviewer); publish `columnkit-X.Y.Z.zip.sig`; ship the public key in the plugin; verify in `upgrader_pre_download` (scoped to our package URL) with `sodium_crypto_sign_verify_detached`, returning `WP_Error` on mismatch. Removes trust in GitHub account/repo entirely. |
| S-4 | Medium | Open (policy) | **Never rename or delete the GitHub account or repo.** If `brendanoconnellwp/columnkit` is ever freed, whoever re-registers it can publish updates to every install (`GitHubUpdater::REPO` is hard-coded). S-3 neutralises this. |
| S-5 | Med-Low | Open | **Release workflow can overwrite an existing release's zip.** `workflow_dispatch` (or a tag re-push) for an already-released version reaches `gh release upload --clobber` and swaps in a build of current `main`, not the tagged commit. Fail when the release exists, or only re-upload when `GITHUB_SHA` equals the tag's commit. Immutable releases (S-2) also blocks it. Concurrency group is per-ref, so the "can't race" comment is inaccurate. |
| S-6 | Low | Fixed (0.7.1) | Updater picked "first .zip" asset as a fallback — a hand-uploaded zip bypassed CI. Now requires exact `columnkit-{version}.zip`. |
| S-7 | Low | Fixed (0.7.1) | Download URL and `tag_name` not validated. Now: tag must be `vX.Y.Z`, public download URL must start with `https://github.com/brendanoconnellwp/columnkit/releases/download/`. |
| S-8 | Low | Open | Private-repo token mode leaks the PAT to the S3 redirect host on WP 6.0–6.1 (Requests 1.x forwards `Authorization` cross-host). Disable token mode below WP 6.2, or raise `Requires at least` to 6.2. Repo is public, so token mode is probably unused. |
| S-9 | Low | Open | `delete_site_transient_update_plugins` also fires on every plugin (de)activation/upgrade, so the release cache is flushed more than intended → extra unauthenticated GitHub API calls (60/h/IP on shared hosts). Fails safe (delayed notice). Option: flush only on explicit force-check. |
| S-10 | Low | Open | Pin `actions/checkout` by SHA; restrict allowed Actions in repo settings. |
| S-11 | Info | Open | `plugins_api` answers for slug `columnkit` in any context — cosmetic collision if wordpress.org ever lists a same-slug plugin. |

## Write paths (inline / bulk edit)

| ID | Sev | Status | Item |
|----|-----|--------|------|
| W-1 | High | Fixed (0.7.1) | **Self-write of user meta.** Users-screen inline save only checked `edit_user`, which WP grants for your own account, and the `ck_inline_save` nonce is handed to anyone who can open the Posts list. A Contributor could write any user-meta / ACF / Meta Box / JetEngine column configured on the Users screen onto themselves (e.g. `membership_level`, `account_status`). `UserMetaColumn`/`TermMetaColumn::save_value` also had no protected-meta check. Fix: user saves require `list_users` + `edit_users`; meta columns check `can_write_key`. |
| W-2 | Medium | Fixed (0.7.1) | **Self email change without confirmation.** Inline Email edit wrote `user_email` directly, skipping core's confirm-new-address flow. Fix: refuse editing your own email inline (use Profile, which confirms). Covered also by W-1's `list_users` gate. |
| W-3 | Low | Fixed (0.7.1) | Term save trusted the taxonomy in `$_POST['screen']` without tying it to the term — a column configured for taxonomy B could be written onto a term in taxonomy A. Now checks the term's real taxonomy. |
| W-4 | Low | Fixed (0.7.1) | Status column could restore a post from Trash with only `edit_post` (core requires `delete_post`) and skipped `wp_untrash_post` hooks. |
| W-5 | Low | Fixed (0.7.1) | Parent column accepted any same-type post (private/draft of others) as parent and then rendered its title — title enumeration. Now requires `read_post` on the parent. |
| W-6 | Low | Open | Core-field inline edit (title/date/author/taxonomy) works on post types with `show_ui=false`; `TermAssigner::assign()` doesn't check taxonomy `show_ui` (UI does). Matches core `tax_input` gap; align with UI. |
| W-7 | Low | Fixed (0.7.1) | New-author validation used generic `edit_posts` instead of the post type's `cap->edit_posts`; no multisite membership check. |
| W-8 | Low | Open | Protected-meta gate only consults `is_protected_meta`; meta registered with a restrictive `auth_callback` (no `_` prefix) is writable with `edit_post`. Add `current_user_can('edit_post_meta' / 'edit_term_meta' / 'edit_user_meta', $id, $key)`. |
| W-9 | Low | Open | Backslashes silently stripped on save (values are unslashed, then passed to slash-expecting APIs). Wrap with `wp_slash()`. Also `sanitize_text_field` strips markup for `unfiltered_html` users — document or relax. |
| W-10 | Info | Fixed (0.7.1) | **Bulk edit never saved** — Posts list submits by GET; handler read `$_POST`. Functional bug, not security. |

## Read / export / settings paths

| ID | Sev | Status | Item |
|----|-----|--------|------|
| R-1 | Medium | Fixed (0.7.1) | **ACF export leaked whole objects.** `get_export_value()` JSON-encoded formatted values, so relationship/post_object fields dumped full `WP_Post` (content, `post_password`, private/draft posts) and user fields dumped `WP_User` (incl. `user_pass` hash, email). Contributor could point a relationship on their own post at an admin's private post and export it. Now exports titles/names only, filtered by `read_post`. |
| R-2 | Low | Fixed (0.7.1) | Private/draft titles disclosed: parent-picker options listed all statuses with no permission check, and ACF relationship/post_object titles rendered without `read_post`. Both now filter by `read_post`. (A parent set through core's own editor still renders its title — same as core's list table.) Also fixed: a single-value ACF post_object was `(array)`-cast into its property list, rendering titles of unrelated post IDs. |
| R-3 | Low | Fixed (0.7.1) | Settings import dropped role restrictions on views → restricted views became visible/exportable by everyone on the target site. |
| R-4 | Low | Open | Exports load up to 10k full objects at once; user/term exports are N+1. Page through IDs in chunks of 200–500 with cache priming and output flushing. |
| R-5 | Low | Fixed (0.7.1) | `fputcsv()` without `$escape` — PHP 8.4 deprecation notice can land inside downloaded CSVs. |
| R-6 | Low | Open | Strict scalar/array type hints on WP hook callbacks (`filter_user_or_term_cell`, `filter_columns`, view switchers) → `TypeError` fatal if another plugin returns `null`. Drop hints, cast inside. |
| R-7 | Info | Open | Author column offers `user_email` to anyone who can view the list (core gates emails behind `list_users`); ACF `password` fields render in plain text; user/term meta columns render any admin-typed key (e.g. `session_tokens`). |
| R-8 | Info | Open | CSV formula escaping: prefixes negative numbers (data integrity); misses leading-whitespace `=` and fullwidth `＝`. |
| R-9 | Info | Open | Option-name collision: `my-book` and `my_book` map to the same option. Uninstall misses the updater transient and other multisite sites; `add_option(..., 'no')` should be `false` on WP 6.6+. |
| R-10 | Info | Open | `NativeColumns::capture()` can rewrite `ck_native_columns` on every list view as different roles see different columns. Capture for `manage_options` only, or merge. |

## Forward compatibility (future WordPress / PHP)

| ID | Sev | Status | Item |
|----|-----|--------|------|
| F-1 | Info | Open | Inline title / term-name save hard-codes markup and leaves core's hidden `#inline_{id}` Quick Edit data stale — a later core Quick Edit on the same row can revert the change. Update the hidden fields in JS after save. |
| F-2 | Info | Open (watch) | JS depends on list-table markup (`#the-list > tr#post-N`, `.column-{name}`, `<strong>` in title). Will need rework when core moves `edit.php` to DataViews/React. Check every major WP release. |
| F-3 | Info | Open (watch) | Row-action filters (`post_row_actions`, `user_row_actions`, `{tax}_row_actions`) used as data collectors; footer-timing assumption for localized script. |

---

## Regression tests for fixed items

| Items | Test |
|-------|------|
| W-1, W-2, W-3, W-10 | `tests/Unit/ListScreens/InlineSaveSecurityTest.php` |
| W-1 (protected keys) | `tests/Unit/Columns/MetaColumnEditTest.php` |
| W-4, W-5 | `tests/Unit/Columns/PostFieldColumnsTest.php` |
| R-1, R-2 | `tests/Unit/Integrations/ACFFieldColumnTest.php` (`test_export_*`) |
| R-3 | `tests/Unit/Admin/SettingsImportTest.php` |
| S-6, S-7 | `tests/Unit/Updater/GitHubUpdaterTest.php` |

Each was confirmed to fail against the pre-fix code.

## Release checklist (security)

Before each release:
1. Re-run a security review of the diff since the last release (new write paths are where holes appear).
2. Every new AJAX/admin-post handler: nonce + per-object capability + server-side whitelist of the field written.
3. Every new export value: no raw objects, permission-filtered.
4. Test against the latest WP beta and PHP version.

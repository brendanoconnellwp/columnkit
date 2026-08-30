# Phase 10 — Pitfalls Reviewed

**Self-updates from GitHub Releases** via WP core's `Update URI` header + `update_plugins_github.com` filter.

---

## Security

### 1. The filter fires for every github.com-hosted plugin — gate on basename
**What:** `update_plugins_{$hostname}` is keyed by *hostname only*. Any other installed plugin whose `Update URI` points at github.com triggers our callback too; answering for it would push OUR zip onto THEIR plugin.
**How:** `check_update()` returns the incoming value untouched unless `$plugin_file === CK_BASENAME`. The foreign case is the first smoke assertion.
**Test/proof:** `GitHubUpdaterTest::test_other_plugins_pass_through_without_http`; smoke `phase10` "foreign plugin passthrough".

### 2. The token must never ride along on unrelated requests
**What:** `http_request_args` is global — a sloppy URL match would attach the PAT to every HTTP call WP makes (other plugins' APIs, oEmbeds, etc.), leaking it to arbitrary hosts.
**How:** `authorize_download()` adds headers only when a token exists AND the URL is prefix-matched against exactly `https://api.github.com/repos/brendanoconnellwp/columnkit/releases/assets/`. Everything else — including other GitHub repos — is untouched.
**Test/proof:** `test_authorize_download_scopes_headers_to_our_assets` (three cases).

### 3. Private-asset downloads and the S3 redirect
**What:** GitHub's asset API 302-redirects to S3 with signed query params. If the client forwards the `Authorization` header to S3, S3 rejects the request (two auth mechanisms). Old WP Requests versions forwarded it.
**How:** WP 6.2+ strips `Authorization` on cross-origin redirects (Requests 2.x hardening). Documented as a floor for private-repo mode; public-repo mode uses the plain `browser_download_url` and is unaffected.

## Robustness

### 4. Never hammer the API from cron
**What:** `wp_update_plugins()` runs twice daily via cron *and* on several admin screens. Unauthenticated GitHub API allows 60 req/h/IP; naive checking gets rate-limited, and a rate-limited site would then re-poll on every admin page load.
**How:** Success is cached in a transient for 6h (filterable via `columnkit/update_cache_ttl`); **failures are cached too** (1h, `ck_error` marker) so an outage or 403 backs off instead of retrying per-request.
**Test/proof:** `test_api_failure_is_cached_and_offers_nothing`, `test_success_is_cached_across_checks` (HTTP call counting); smoke `phase10` §3.

### 5. A broken release must degrade to "no update"
**What:** A release with no zip asset, a malformed tag, or a garbled API body should never produce an update offer with an empty/bogus package (WP would show the row, then fail the install).
**How:** Every parse step bails to passthrough: missing `tag_name` → cached failure; version not `>` installed → no offer; no `.zip` asset → no offer. The asset picker prefers `columnkit-{version}.zip` and falls back to the first `.zip` only.

### 6. Stale "update available" after updating
**What:** With a 6h cache, the update row would persist for hours after the user installs the new version.
**How:** `upgrader_process_complete` (type=plugin) deletes the transient, forcing a fresh check that now compares equal.
**Test/proof:** `test_flush_cache_on_plugin_upgrade`.

## Release-contract coupling

### 7. The zip's folder name is the upgrade
**What:** WP replaces the plugin *directory* during an update. A zip whose root folder isn't exactly `columnkit/` (e.g. GitHub's auto-generated `columnkit-0.6.0/` source archive) would install as a NEW plugin beside the old one, deactivated.
**How:** We only ever offer the workflow-built release asset (which stages into a literal `columnkit/` folder), never GitHub's `zipball_url`/source archives. The asset-name preference (`columnkit-{version}.zip`) pins this.

### 8. Sites older than the updater can't see the updater
**What:** 0.5.3 installs contain no update code — they will sit on 0.5.3 forever regardless of what we release.
**How:** Not solvable in code; documented in README: one manual zip install to ≥ 0.6.0, automatic from then on.

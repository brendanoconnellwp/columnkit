# Phase 2 — Pitfalls Reviewed

Sorting + Filtering. This doc is the record of common pitfalls explicitly handled in this phase. See `PITFALLS-phase1.md` for the foundation.

---

## Security

### 1. SQL injection via meta key / taxonomy slug in `posts_clauses`
**What:** `posts_clauses` is the lowest-level join/orderby filter in WP_Query. Any user-controlled value concatenated into the clause is a SQLi vector. Both PostMetaColumn::apply_sort (uses meta_key) and TaxonomyColumn::apply_sort (uses taxonomy slug) need to splice values into a SQL JOIN.
**How:**
- We funnel both through `$wpdb->prepare()` with `%s` placeholders rather than string concatenation.
- Meta keys are additionally character-whitelisted at the sanitiser (`[A-Za-z0-9_\-.]`), and taxonomy slugs through `sanitize_key()`, before they ever reach the SQL.
**Test/proof:** `phase2-sort-filter.php` test "SQL injection in filter value does not break query" — passes a value containing `DROP TABLE`; query runs cleanly and matches zero rows.

### 2. Order direction injection
**What:** ORDER BY direction is not parameterisable in a prepared statement, so it must be whitelisted in code.
**How:** `SortManager::apply_sort` clamps `$order` to literally `ASC` or `DESC` before any column sees it. Columns receive the already-validated string.

### 3. XSS via filter control rendering
**What:** Filter inputs echo `$_GET` values back into the page as `value` attributes. Raw values would allow `value="><script>...`.
**How:** Every filter renderer escapes via `esc_attr()` and `esc_html()`. `FilterManager::read_filter_values()` also pre-sanitises through `sanitize_text_field()`.
**Test/proof:** smoke test "filter input escapes attacker payload" — `<script>` payload from `$_GET` is rendered as `&quot;&gt;` not `"><script>`.

### 4. Capabilities — read-only operation
**What:** Sort/filter don't mutate state; they just narrow the list. WP's existing list-table cap checks (can the user `edit_posts` of this type?) gate visibility.
**How:** We layer on top of WP's `pre_get_posts` and `restrict_manage_posts`. We do not bypass capability checks; we don't expose any post the user couldn't already see.

---

## Performance

### 5. `pre_get_posts` blast radius
**What:** `pre_get_posts` fires on EVERY query — sidebar widgets, REST, admin secondary lists, related-posts lookups. If our hook does work unconditionally, every page load pays the cost.
**How:** Both `SortManager::apply_sort` and `FilterManager::apply_filters` short-circuit unless: `is_admin()`, `is_main_query()`, AND post_type matches the active screen's post_type. Combined with the Phase 1 gate of only activating on `current_screen`, this means the cost is paid only for the actual list-table query the user is looking at.

### 6. JOIN at scale on `wp_postmeta`
**What:** `LEFT JOIN wp_postmeta ... ON meta_key = ?` is fast if `meta_key` is indexed (it is — composite `(post_id, meta_key)` index by default). But sorting on the joined `meta_value` requires a filesort because `meta_value` (LONGTEXT) isn't indexable practically.
**How:** Accepted as a known cost — Admin Columns Pro has the same characteristic. For tables with millions of posts, the right answer is custom indexed columns; we document this in the pitfalls and don't try to be clever. The LEFT JOIN approach also means we don't INNER JOIN out posts that lack the meta, which is the user-friendly behaviour.
**Test/proof:** smoke test "sort includes posts WITHOUT the meta key (LEFT JOIN works)" — created a post with no meta value, confirmed it appears in the sorted result.

### 7. Taxonomy sort + GROUP BY de-duplication
**What:** A LEFT JOIN through `wp_term_relationships` can multiply a row by the number of its terms. Without a GROUP BY, a post in 3 categories would appear 3 times in the list.
**How:** TaxonomyColumn::apply_sort sets `$clauses['groupby'] = "{$wpdb->posts}.ID"` and orders by `MIN(ck_t.name)` (alphabetical first term). Posts with no terms still appear (LEFT JOIN).

### 8. N+1 still avoided
**What:** Phase 1's meta-cache prewarm runs on `the_posts`. Phase 2 didn't break that — we add SQL clauses but still go through one main WP_Query, so prewarm still sees all final post IDs.

---

## WordPress-specific

### 9. `WP_Query::query()` re-init clobbers pre-set query vars
**What:** A subtle WP gotcha: calling `$query->set('meta_query', X)` and then `$query->query($args)` does NOT preserve X. `query()` calls `init()` which resets state, then parses `$args` afresh. This caught our first round of smoke tests.
**How:** Production code only calls `set()` from inside a `pre_get_posts` callback, which fires *after* parse_query — so vars survive. Smoke tests now use a `pre_get_posts` shim to mirror the real flow. Documented here so the next person doesn't relearn it the hard way.

### 10. `is_main_query()` returns false in CLI / programmatic queries
**What:** Our gating in SortManager/FilterManager checks `$query->is_main_query()` — which is `$query === $GLOBALS['wp_the_query']`. In wp-cli or any nested query, that's false, so our hooks don't fire.
**How:** Intentional. CLI tests bypass the manager and call `Column::apply_sort()` / `apply_filter()` directly to verify behaviour. In real usage the main edit-post.php query *is* `wp_the_query`, so the hook fires.

### 11. `meta_query` array vs flat var
**What:** WP_Query accepts `meta_query` as either an array of clauses or — confusingly — a single flat `meta_key`/`meta_value`/`meta_compare` triple. Mixing them is undefined behaviour.
**How:** We always read `meta_query` as `(array) $query->get('meta_query')`, append our new clause, and write back. We never set the flat singular vars. This composes safely with other plugins that may have already added meta_query clauses.

### 12. `wp_dropdown_users()` capability filter
**What:** Older WP code passed `'who' => 'authors'` to filter the dropdown to users with author-level caps. That argument was deprecated in WP 5.9 and now does nothing on newer versions.
**How:** We pass `'capability' => ['edit_posts']` instead. Confirmed against WP 6.9.

### 13. `restrict_manage_posts` runs twice (once before, once after) on some screens
**What:** WP runs `restrict_manage_posts` both above and below the table on some flows. Echoing form controls in both is fine, but doing so can confuse JS that hooks the dropdowns by ID.
**How:** We use `name=` attributes only (no IDs) on filter controls. Same control appearing twice doesn't break anything; users see one set above the list.

### 14. Filter values persist after column removal
**What:** If a user removes a column, lingering `?ck_f_xxx=...` in the URL would be silently ignored — but if they later re-add a column with the same ID, the filter would resurrect.
**How:** Acceptable for v1. Column IDs are random short hashes, so collision is unlikely. Documented as a known minor edge case.

---

## Data integrity

### 15. Range filter — empty min or max should not apply that side
**What:** A user types only a min, no max. Naively passing `BETWEEN min, ''` would match almost nothing (empty string coerces to 0 in NUMERIC comparison).
**How:** `PostMetaColumn::apply_filter` branches: both → BETWEEN; min only → `>=`; max only → `<=`; neither → no clause added.
**Test/proof:** smoke test "min-only filter [>=30]" passes — only min applied.

### 16. Boolean filter "No" must include posts missing the meta
**What:** Many WP plugins store a boolean as "1" only when true, and don't write anything when false. A naive `value != '1'` filter would EXCLUDE posts without the meta entirely.
**How:** `PostMetaColumn::apply_filter` for `'0'` uses a nested OR: `NOT EXISTS` OR `value NOT IN [truthy values]`. Posts missing the meta are correctly counted as "No".

---

## Out-of-scope for Phase 2 (deferred)

- **Multi-value filters** (e.g. an array of term IDs, multiple authors) — Phase 5 / polish.
- **Range filter on date-pickers as type=datetime-local** — currently we use type=date. Sufficient for most users.
- **Sort by featured image** (e.g. "has image first") — useful only occasionally; deferred.
- **Saved filter sets** ("Saved Segments" in AC Pro) — a separate user-facing feature, future phase.

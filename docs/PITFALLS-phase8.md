# Phase 8 — Pitfalls Reviewed

**Per-column display formatting** — width, alignment, prefix/suffix, badge pill, text + background colour.

---

## Security

### 1. Colours go through `sanitize_hex_color`, never raw into a `style` attribute
**What:** A free-text colour field that lands in an inline `style=""` is a CSS-injection / attribute-breakout vector (e.g. `red" onmouseover="…`, or `expression(...)` on old IE, or `url(...)` exfiltration).
**How:** `Sanitizer::sanitize_format()` runs both colour fields through WP's `sanitize_hex_color()`, which only returns `#rgb` / `#rrggbb` or null (coerced to `''`). `ColumnPresenter` then emits the already-validated value inside an `esc_attr()`'d style string. Anything not a hex triple is dropped.
**Test/proof:** `SanitizerTest::test_format_rejects_bad_values`, `ColumnPresenterTest::test_badge_wraps_with_colours`; smoke `phase8` "invalid hex rejected".

### 2. Prefix / suffix are escaped at render
**What:** Prefix/suffix are user text wrapped around a cell value — if echoed raw they're stored XSS.
**How:** Stored via `sanitize_text_field()` (strips tags) and re-escaped with `esc_html()` at render time in `ColumnPresenter::format()`. Belt and braces.
**Test/proof:** `ColumnPresenterTest::test_prefix_is_escaped`; smoke `phase8` "prefix XSS escaped".

### 3. Width / align CSS is built from whitelisted values only
**What:** `print_column_styles()` writes an inline `<style>` block — any user value flowing into a CSS rule must be constrained or it can inject extra declarations/selectors.
**How:** Width is matched against `^\d+(px|%|em|rem)?$` (skipped otherwise); align is an in-array whitelist of `left|center|right`. The column id is stripped to `[a-z0-9_]`. No value reaches the stylesheet without passing one of these gates, so the `<style>` block can't be escaped even though it isn't `esc_*`'d.
**Test/proof:** smoke `phase8` width/align; the regex/whitelist are the proof.

---

## Correctness

### 4. Formatting survives an inline edit
**What:** Inline-edit replaces a cell's inner HTML with the AJAX re-render. If only the list table applied prefix/suffix/badge, the formatting would vanish the moment a user edits the cell.
**How:** `EditManager::ajax_inline_save()` runs the re-rendered HTML back through `ColumnPresenter::format()` with the column's stored format before returning it, so the replaced content matches the original.
**Test/proof:** manual — edit a badge/prefixed cell and confirm the pill/affix is still there after save.

### 5. Empty cells don't sprout lone badges or affixes
**What:** A blank value wrapped in a badge or given a prefix would render an empty pill or a stray `$` on every empty row — visual noise.
**How:** `ColumnPresenter::format()` returns early on an empty value string; `format_export()` does the same. Image/HTML values (non-empty, no text) still format correctly.
**Test/proof:** `ColumnPresenterTest::test_empty_value_stays_empty`, `test_export_format_is_plain_text`.

### 6. Width is column-level CSS, not a per-cell wrapper
**What:** Setting width by wrapping each cell value wouldn't widen the `<th>` header, so the header and body would disagree; alignment has the same issue.
**How:** Width + alignment are emitted as a single rule per column targeting `.wp-list-table .column-ck_{id}`, which WP applies to both the header and every cell. Content-level formatting (prefix/badge) stays in `ColumnPresenter`; layout stays in CSS. Width on an auto-layout table is a best-effort hint — same trade-off Admin Columns makes.

---

## Compatibility

### 7. Badge/affix CSS loads on every screen cells render, not just edit.php
**What:** Inline-edit assets only load on `edit.php`; badges also appear on Media, Users, and Taxonomy tables.
**How:** A dedicated `list-screen.css` is enqueued on `edit.php`, `upload.php`, `users.php`, and `edit-tags.php`. Inline colours work even without it; the stylesheet just makes the pill look like a pill.

### 8. Colour picker initialises on dynamically-added rows
**What:** `wpColorPicker()` wraps an input in extra markup on init. Rows cloned from a `<template>` after page load would otherwise get a plain text box, and double-init on existing rows corrupts the widget.
**How:** `admin.js` guards each input with a `ckColorInit` data flag and initialises only the new row's `.ck-color` inputs on add. Templates are inert (`<template>`) so they're never initialised until cloned.

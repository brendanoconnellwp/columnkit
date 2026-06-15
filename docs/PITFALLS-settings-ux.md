# Settings UX overhaul — Pitfalls Reviewed

**Collapsible column rows, live summaries, searchable column picker, sticky save bar.** All
client-side polish on the existing server-rendered form — no new storage or request handling.

---

## JS / DOM

### 1. Cloned rows must re-init their widgets
**What:** New rows are cloned from inert `<template>` blocks after page load. The colour picker and the collapsed-summary text don't exist for them until JS wires each one; double-initialising existing rows corrupts the colour widget.
**How:** `addColumn()` scopes `initColorPickers()` to the new row and guards each input with a `ckColorInit` data flag; it also seeds the summary and focuses the label. Existing rows are initialised once on `init()`.

### 2. Remove button inside a clickable head
**What:** The whole `.ck-head-text` is a collapse toggle. The remove "×" lives in the same head — a click there would both delete the row *and* toggle the (now-removed) row.
**How:** The remove handler calls `e.stopPropagation()` and the toggle is bound to `.ck-collapse-toggle` / `.ck-head-text` specifically, not the whole head, so the handle and remove button are exempt.

### 3. Picker filters on a precomputed haystack, not the DOM text
**What:** Filtering by reading visible text is fragile (markup, case, diacritics) and re-reads the DOM on every keystroke.
**How:** Each picker item carries a `data-search` attribute (lower-cased label + description, built server-side). The filter is a single `indexOf` per item; an empty-state message toggles when nothing matches.

### 4. `reindex()` still runs after every structural change
**What:** Column field names embed the array index (`columns[3][label]`). Adding, removing, or drag-reordering rows must renumber them or the POST payload collapses on duplicate indices.
**How:** Unchanged from before — `reindex()` is called on add, remove, and sortable `update`. The new collapse/summary behaviour never reorders DOM, so it can't desync indices.

---

## Accessibility / markup

### 5. Toggles expose state to assistive tech
**What:** A caret that only *looks* collapsed is invisible to screen readers.
**How:** `.ck-collapse-toggle` and `.ck-add-toggle` carry `aria-expanded`, kept in sync by `setCollapsed()` / `openPicker()`. The picker search and toggles have `aria-label`s; the empty-state uses the `hidden` attribute, not just CSS.

### 6. Sticky save bar can't hide content
**What:** A `position: sticky` save bar at the bottom risks overlaying the last row on short viewports.
**How:** It sticks to the bottom of the form flow (not `fixed`), so it only pins once scrolled to; normal flow keeps the last row reachable. `z-index: 1` keeps it above row shadows without trapping focus.

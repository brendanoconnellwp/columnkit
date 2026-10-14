<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * Marker: an EditableColumn that edits inline (popover) but NOT through WP's Bulk Edit panel —
 * its editor (a media picker, a term checklist) can't be expressed as one bulk-edit input, and
 * for taxonomies core's own Bulk Edit already covers it. EditManager skips these for bulk.
 */
interface InlineOnlyEditable extends EditableColumn {}

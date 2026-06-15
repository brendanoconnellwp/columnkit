/**
 * Click-to-edit popover for editable cells.
 *
 * Two sources of editable cells:
 *   1. Cells our plugin renders (configured columns) — already wrapped with
 *      `.ck-cell.ck-editable` server-side by ListScreenManager::render_cell.
 *   2. WP core columns (Title, Date, Author) — decorated client-side on page load
 *      using a lookup map (CK_INLINE.coreData) that the server emits in the footer.
 *
 * UX:
 *   - Hover a cell → pencil icon appears.
 *   - Click anywhere in the cell EXCEPT a real link/button → popover opens.
 *   - The explicit `.ck-edit-trigger` button always opens the popover (useful for
 *     Title/Author cells whose visible text IS a link).
 *   - Enter saves, Esc cancels, click outside cancels.
 *   - AJAX POST → server re-renders the cell HTML, JS replaces.
 */
/* global jQuery, CK_INLINE */
( function ( $ ) {
	'use strict';
	if ( typeof CK_INLINE === 'undefined' || ! CK_INLINE.ajaxUrl || ! CK_INLINE.nonce ) {
		return;
	}
	var I = CK_INLINE.i18n || {};
	var $activePopover = null;
	var $activeCell    = null;

	$( function () {
		decorateCoreColumns();
	} );

	function decorateCoreColumns() {
		if ( ! CK_INLINE.coreData || ! CK_INLINE.coreColumns ) {
			return;
		}
		var $tbody = $( '#the-list' );
		if ( ! $tbody.length ) {
			return;
		}
		$tbody.find( 'tr' ).each( function () {
			var $row  = $( this );
			var rowId = $row.attr( 'id' ) || '';
			var m     = rowId.match( /^post-(\d+)$/ );
			if ( ! m ) { return; }
			var postId = parseInt( m[1], 10 );
			var data   = CK_INLINE.coreData[ postId ];
			if ( ! data ) { return; }

			$.each( CK_INLINE.coreColumns, function ( field, spec ) {
				var $td = $row.find( 'td.' + spec.td_class ).first();
				if ( ! $td.length ) { return; }
				if ( $td.find( '> .ck-cell' ).length ) { return; }

				var raw   = data[ field ] !== undefined ? data[ field ] : '';
				var $wrap = $( '<span class="ck-cell ck-editable ck-has-trigger" />' )
					.attr( 'data-ck-col',   CK_INLINE.corePrefix + field )
					.attr( 'data-ck-input', spec.input )
					.attr( 'data-ck-raw',   raw );
				if ( spec.options ) {
					$wrap.attr( 'data-ck-options', JSON.stringify( spec.options ) );
				}
				$wrap.html( $td.html() );

				// Append a pencil trigger so cells whose text is a link (Title, Author) still
				// have an obvious way to open the editor.
				$( '<button type="button" class="ck-edit-trigger" />' )
					.attr( 'aria-label', I.edit || 'Edit' )
					.attr( 'title', I.edit || 'Edit' )
					.html( '✎' )
					.appendTo( $wrap );

				$td.empty().append( $wrap );
			} );
		} );
	}

	$( document ).on( 'click', '.ck-cell.ck-editable', function ( e ) {
		var $target = $( e.target );
		// Our edit-trigger always opens the popover.
		var isTrigger = $target.closest( '.ck-edit-trigger' ).length > 0;
		// Real interactive elements (links, inputs, native buttons) bail out, so they keep
		// their default behaviour. Our pencil button is `.ck-edit-trigger` and is exempted.
		if ( ! isTrigger && $target.closest( 'a, button:not(.ck-edit-trigger), input, select, textarea' ).length ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		if ( $activeCell && $activeCell.is( this ) ) {
			return;
		}
		closeActive();
		openEditor( $( this ) );
	} );

	function openEditor( $cell ) {
		var $row  = $cell.closest( 'tr' );
		var rowId = $row.attr( 'id' ) || '';
		var match = rowId.match( /^post-(\d+)$/ );
		if ( ! match ) { return; }

		var ctx = {
			postId:    parseInt( match[1], 10 ),
			colId:     $cell.attr( 'data-ck-col' ) || '',
			value:     $cell.attr( 'data-ck-raw' ) || '',
			inputType: $cell.attr( 'data-ck-input' ) || 'text',
			options:   null
		};
		var optsJson = $cell.attr( 'data-ck-options' );
		if ( optsJson ) {
			try { ctx.options = JSON.parse( optsJson ); } catch ( err ) { ctx.options = null; }
		}
		if ( ! ctx.colId || ! ctx.postId ) { return; }

		var $popover = buildPopover( ctx, $cell );
		$( 'body' ).append( $popover );
		position( $popover, $cell );
		$cell.addClass( 'ck-editing' );
		$activePopover = $popover;
		$activeCell    = $cell;
		$popover.find( '.ck-input' ).first().focus();
		var $first = $popover.find( '.ck-input' ).first();
		if ( $first.is( 'input[type=text]' ) ) { $first.select(); }

		setTimeout( function () {
			$( document ).on( 'click.ck-edit', function ( ev ) {
				if ( $activePopover && ! $.contains( $activePopover[0], ev.target ) &&
					 $activeCell && ! $.contains( $activeCell[0], ev.target ) ) {
					closeActive();
				}
			} );
		}, 0 );
		$( document ).on( 'keydown.ck-edit', function ( ev ) {
			if ( ev.which === 27 ) {
				ev.preventDefault();
				closeActive();
			}
		} );
		$( window ).on( 'resize.ck-edit scroll.ck-edit', function () {
			if ( $activePopover && $activeCell ) {
				position( $activePopover, $activeCell );
			}
		} );
	}

	function closeActive() {
		if ( $activePopover ) { $activePopover.remove(); }
		if ( $activeCell )    { $activeCell.removeClass( 'ck-editing' ); }
		$activePopover = null;
		$activeCell    = null;
		$( document ).off( 'click.ck-edit keydown.ck-edit' );
		$( window ).off( 'resize.ck-edit scroll.ck-edit' );
	}

	function buildPopover( ctx, $cell ) {
		var $p = $( '<div class="ck-edit-popover" role="dialog" />' );
		var $input = buildInput( ctx );
		$p.append( $input );

		var $actions = $( '<div class="ck-edit-popover-actions" />' ).appendTo( $p );
		var $status  = $( '<span class="ck-status" aria-live="polite" />' ).appendTo( $actions );
		var $cancel  = $( '<button type="button" class="button-link ck-cancel" />' ).text( I.cancel || 'Cancel' ).appendTo( $actions );
		var $save    = $( '<button type="button" class="button button-primary ck-save" />' ).text( I.save || 'Save' ).appendTo( $actions );

		$save.on( 'click',  function () { doSave( ctx, $p, $cell ); } );
		$cancel.on( 'click', closeActive );
		$input.on( 'keydown', function ( ev ) {
			if ( ev.which === 13 && ! $input.is( 'textarea' ) ) {
				ev.preventDefault();
				doSave( ctx, $p, $cell );
			}
		} );
		return $p;
	}

	function buildInput( ctx ) {
		var $i;
		var v = ctx.value || '';

		if ( ctx.inputType === 'boolean' ) {
			$i = $( '<select class="ck-input" />' );
			$( '<option/>' ).attr( 'value', '' ).text( I.unchanged || '— (unchanged)' ).appendTo( $i );
			$( '<option/>' ).attr( 'value', '1' ).text( I.yes || 'Yes' ).appendTo( $i );
			$( '<option/>' ).attr( 'value', '0' ).text( I.no  || 'No'  ).appendTo( $i );
			var nv = '';
			if ( /^(1|true|yes|on)$/i.test( v ) ) { nv = '1'; }
			else if ( /^(0|false|no|off)$/i.test( v ) && v !== '' ) { nv = '0'; }
			$i.val( nv );
			return $i;
		}
		if ( ctx.inputType === 'select' && ctx.options ) {
			$i = $( '<select class="ck-input" />' );
			$.each( ctx.options, function ( value, label ) {
				$( '<option/>' ).attr( 'value', value ).text( label ).appendTo( $i );
			} );
			$i.val( v );
			return $i;
		}
		if ( ctx.inputType === 'date' ) {
			return $( '<input type="date" class="ck-input" />' ).val( v );
		}
		if ( ctx.inputType === 'number' ) {
			return $( '<input type="number" step="any" class="ck-input" />' ).val( v );
		}
		return $( '<input type="text" class="ck-input" />' ).val( v );
	}

	function position( $popover, $cell ) {
		var off       = $cell.offset();
		var cellH     = $cell.outerHeight();
		var winH      = $( window ).height();
		var winW      = $( window ).width();
		var scrollTop = $( window ).scrollTop();
		var popH      = $popover.outerHeight();
		var popW      = $popover.outerWidth();

		var top  = off.top + cellH;
		var left = off.left;

		var spaceBelow = winH - ( off.top - scrollTop + cellH );
		if ( spaceBelow < popH + 12 && off.top - popH - 4 > scrollTop ) {
			top = off.top - popH - 4;
		}
		if ( left + popW > winW - 12 ) {
			left = Math.max( 12, winW - popW - 12 );
		}
		$popover.css( { top: top, left: left } );
	}

	function doSave( ctx, $popover, $cell ) {
		var $status = $popover.find( '.ck-status' ).removeClass( 'error success' ).text( I.saving || 'Saving…' );
		var $save   = $popover.find( '.ck-save' ).prop( 'disabled', true );
		var $input  = $popover.find( '.ck-input' );

		$.ajax( {
			url:      CK_INLINE.ajaxUrl,
			type:     'POST',
			dataType: 'json',
			data: {
				action:      CK_INLINE.action || 'ck_inline_save',
				_ajax_nonce: CK_INLINE.nonce,
				post_id:     ctx.postId,
				col_id:      ctx.colId,
				set:         CK_INLINE.set || 'default',
				value:       $input.val()
			}
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				// Preserve our wrapper + edit trigger; only replace the inner display content.
				var $trigger = $cell.find( '> .ck-edit-trigger' ).detach();
				$cell.html( resp.data.html );
				$cell.attr( 'data-ck-raw', resp.data.raw );
				if ( $trigger.length ) { $cell.append( $trigger ); }
				$status.addClass( 'success' ).text( I.saved || 'Saved' );
				setTimeout( closeActive, 250 );
			} else {
				var msg = ( resp && resp.data && resp.data.message ) || I.error || 'Save failed';
				$status.addClass( 'error' ).text( msg );
				$save.prop( 'disabled', false );
			}
		} ).fail( function () {
			$status.addClass( 'error' ).text( I.networkError || 'Network error' );
			$save.prop( 'disabled', false );
		} );
	}
} )( jQuery );

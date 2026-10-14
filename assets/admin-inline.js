/**
 * Click-to-edit popover for editable cells.
 *
 * Two sources of editable cells:
 *   1. Cells our plugin renders (configured columns) — already wrapped with
 *      `.ck-cell.ck-editable` server-side by ListScreenManager.
 *   2. WordPress's own columns — post Title / Date / Author / taxonomy columns, term Name /
 *      Slug / Description, user Email / Role — decorated client-side on page load from
 *      CK_INLINE.coreColumns (which columns) + CK_INLINE.coreData (each row's raw values,
 *      printed in the footer).
 *
 * Input types: text, textarea, number, date, boolean, select, terms (checklist + search +
 * "add new" for tag-like taxonomies; list shipped once in CK_INLINE.taxonomies), media
 * (WordPress media library, for featured images).
 *
 * UX:
 *   - Hover a row → a pencil appears on each editable cell.
 *   - Click anywhere in the cell EXCEPT a real link/button → popover opens.
 *   - The `.ck-edit-trigger` pencil always opens the popover (cells whose text is a link).
 *   - Enter saves (Ctrl+Enter in a textarea), Esc cancels, click outside cancels.
 *   - AJAX POST → server re-renders the cell HTML, JS replaces it.
 */
/* global jQuery, CK_INLINE, wp */
( function ( $ ) {
	'use strict';
	if ( typeof CK_INLINE === 'undefined' || ! CK_INLINE.ajaxUrl || ! CK_INLINE.nonce ) {
		return;
	}
	var I = CK_INLINE.i18n || {};

	var $activePopover = null;
	var $activeCell    = null;
	var mediaOpen      = false; // While the media modal is up, outside clicks must not close us.

	$( function () {
		decorateCoreColumns();
	} );

	// ------------------------------------------------------------------
	// Core-column decoration
	// ------------------------------------------------------------------

	function decorateCoreColumns() {
		var data = CK_INLINE.coreData;
		var cols = CK_INLINE.coreColumns;
		if ( ! data || ! cols ) {
			return;
		}
		var $tbody = $( '#the-list' );
		if ( ! $tbody.length ) {
			return;
		}
		$tbody.children( 'tr' ).each( function () {
			var $row = $( this );
			// post-12 (posts), tag-12 (terms), user-12 (users).
			var m = ( $row.attr( 'id' ) || '' ).match( /^(?:post|tag|user)-(\d+)$/ );
			if ( ! m ) { return; }
			var id  = parseInt( m[1], 10 );
			var row = data[ id ];
			if ( ! row ) { return; }

			$.each( cols, function ( field, spec ) {
				if ( row[ field ] === undefined ) { return; }
				if ( field === 'role' && CK_INLINE.selfId && id === parseInt( CK_INLINE.selfId, 10 ) ) {
					return; // Your own role isn't editable (server enforces this too).
				}
				var column = spec.column || ( spec.td_class || '' ).replace( /^column-/, '' );
				// The primary column is a <th scope="row"> in recent WordPress, others <td>.
				var $cellEl = $row.children( '.column-' + column ).first();
				if ( ! $cellEl.length || $cellEl.find( '.ck-cell' ).length ) { return; }

				var $wrap = $( '<span class="ck-cell ck-editable ck-has-trigger" />' )
					.attr( 'data-ck-col', CK_INLINE.corePrefix + field )
					.attr( 'data-ck-input', spec.input )
					.attr( 'data-ck-raw', row[ field ] );
				if ( spec.options ) {
					$wrap.attr( 'data-ck-options', JSON.stringify( spec.options ) );
				}
				if ( spec.taxonomy ) {
					$wrap.attr( 'data-ck-taxonomy', spec.taxonomy );
				}
				if ( CK_INLINE.coreObject && CK_INLINE.coreObject !== 'post' ) {
					$wrap.attr( 'data-ck-object', CK_INLINE.coreObject ).attr( 'data-ck-id', id );
				}

				// Wrap only the display part (e.g. the title's <strong>) so row actions and
				// core's hidden Quick Edit data stay where WordPress expects them.
				var $target = spec.wrap ? $cellEl.children( spec.wrap ).first() : $();
				if ( $target.length ) {
					$target.wrap( $wrap );
					$wrap = $target.parent();
				} else {
					$wrap.append( $cellEl.contents() );
					$cellEl.append( $wrap );
				}
				$( '<button type="button" class="ck-edit-trigger" />' )
					.attr( 'aria-label', I.edit || 'Edit' )
					.attr( 'title', I.edit || 'Edit' )
					.html( '<span class="dashicons dashicons-edit" aria-hidden="true"></span>' )
					.appendTo( $wrap );
			} );
		} );
	}

	// ------------------------------------------------------------------
	// Open / close
	// ------------------------------------------------------------------

	$( document ).on( 'click', '.ck-cell.ck-editable', function ( e ) {
		var $target = $( e.target );
		var isTrigger = $target.closest( '.ck-edit-trigger' ).length > 0;
		// Real interactive elements (links, inputs, native buttons) keep their default behaviour.
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
		var objectType = $cell.attr( 'data-ck-object' ) || 'post';
		var objectId   = parseInt( $cell.attr( 'data-ck-id' ) || '', 10 );
		if ( ! objectId ) {
			var m = ( ( $cell.closest( 'tr' ).attr( 'id' ) ) || '' ).match( /-(\d+)$/ );
			objectId = m ? parseInt( m[1], 10 ) : 0;
		}

		var ctx = {
			objectType: objectType,
			objectId:   objectId,
			colId:      $cell.attr( 'data-ck-col' ) || '',
			value:      $cell.attr( 'data-ck-raw' ) || '',
			inputType:  $cell.attr( 'data-ck-input' ) || 'text',
			taxonomy:   $cell.attr( 'data-ck-taxonomy' ) || '',
			options:    null,
			getValue:   null
		};
		var optsJson = $cell.attr( 'data-ck-options' );
		if ( optsJson ) {
			try { ctx.options = JSON.parse( optsJson ); } catch ( err ) { ctx.options = null; }
		}
		if ( ! ctx.colId || ! ctx.objectId ) { return; }

		var $popover = buildPopover( ctx, $cell );
		$( 'body' ).append( $popover );
		position( $popover, $cell );
		$cell.addClass( 'ck-editing' );
		$activePopover = $popover;
		$activeCell    = $cell;
		var $first = $popover.find( '.ck-focus, .ck-input' ).filter( ':visible' ).first();
		$first.trigger( 'focus' );
		if ( $first.is( 'input[type=text]' ) ) { $first.trigger( 'select' ); }

		setTimeout( function () {
			$( document ).on( 'click.ck-edit', function ( ev ) {
				if ( mediaOpen ) { return; }
				if ( $activePopover && ! $.contains( $activePopover[0], ev.target ) &&
					 $activeCell && ! $.contains( $activeCell[0], ev.target ) ) {
					closeActive();
				}
			} );
		}, 0 );
		$( document ).on( 'keydown.ck-edit', function ( ev ) {
			if ( ev.which === 27 && ! mediaOpen ) {
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

	// ------------------------------------------------------------------
	// Popover
	// ------------------------------------------------------------------

	function buildPopover( ctx, $cell ) {
		var $p = $( '<div class="ck-edit-popover" role="dialog" />' ).addClass( 'ck-input-' + ctx.inputType );
		var $input = buildInput( ctx, $p, $cell );
		$p.append( $input );

		var $actions = $( '<div class="ck-edit-popover-actions" />' ).appendTo( $p );
		$( '<span class="ck-status" aria-live="polite" />' ).appendTo( $actions );
		if ( ctx.inputType !== 'media' ) {
			$( '<button type="button" class="button-link ck-cancel" />' ).text( I.cancel || 'Cancel' ).appendTo( $actions )
				.on( 'click', closeActive );
			$( '<button type="button" class="button button-primary ck-save" />' ).text( I.save || 'Save' ).appendTo( $actions )
				.on( 'click', function () { doSave( ctx, $p, $cell ); } );
		}

		$p.on( 'keydown', 'input, select, textarea', function ( ev ) {
			if ( ev.which !== 13 ) { return; }
			if ( $( this ).is( 'textarea' ) && ! ( ev.ctrlKey || ev.metaKey ) ) { return; }
			if ( $( this ).hasClass( 'ck-terms-search' ) ) { ev.preventDefault(); return; }
			ev.preventDefault();
			doSave( ctx, $p, $cell );
		} );
		return $p;
	}

	function buildInput( ctx, $popover, $cell ) {
		var $i;
		var v = ctx.value || '';

		if ( ctx.inputType === 'terms' ) {
			return buildTermsInput( ctx );
		}
		if ( ctx.inputType === 'media' ) {
			return buildMediaInput( ctx, $popover, $cell );
		}
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
		if ( ctx.inputType === 'textarea' ) {
			return $( '<textarea class="ck-input" rows="4" />' ).val( v );
		}
		if ( ctx.inputType === 'date' ) {
			return $( '<input type="date" class="ck-input" />' ).val( v );
		}
		if ( ctx.inputType === 'number' ) {
			return $( '<input type="number" step="any" class="ck-input" />' ).val( v );
		}
		return $( '<input type="text" class="ck-input" />' ).val( v );
	}

	/** Searchable checklist of a taxonomy's terms (+ "add new" for tag-like taxonomies). */
	function buildTermsInput( ctx ) {
		var cfg = ( CK_INLINE.taxonomies || {} )[ ctx.taxonomy ] || null;
		var $wrap = $( '<div class="ck-terms" />' );
		if ( ! cfg ) {
			// Unknown / too-large taxonomy: fall back to editing the raw term IDs.
			var $raw = $( '<input type="text" class="ck-input" />' ).val( ctx.value );
			ctx.getValue = function () { return $raw.val(); };
			return $wrap.append( $raw );
		}
		var selected = {};
		String( ctx.value || '' ).split( ',' ).forEach( function ( id ) {
			if ( id ) { selected[ String( parseInt( id, 10 ) ) ] = true; }
		} );

		var $search = $( '<input type="search" class="ck-terms-search ck-focus" />' )
			.attr( 'placeholder', I.searchTerms || 'Search…' ).attr( 'aria-label', I.searchTerms || 'Search' );
		var $list = $( '<ul class="ck-terms-list" />' ).attr( 'aria-label', cfg.label || '' );
		( cfg.terms || [] ).forEach( function ( t ) {
			var id = String( t.id );
			var $li = $( '<li />' ).attr( 'data-name', String( t.name ).toLowerCase() )
				.css( 'padding-left', ( t.depth || 0 ) * 14 + 'px' );
			var $label = $( '<label />' );
			$( '<input type="checkbox" />' ).val( id ).prop( 'checked', !! selected[ id ] ).appendTo( $label );
			$( '<span />' ).text( t.name ).appendTo( $label );
			$li.append( $label ).appendTo( $list );
		} );
		if ( ! ( cfg.terms || [] ).length ) {
			$( '<li class="ck-terms-empty" />' ).text( I.noTerms || 'No terms yet.' ).appendTo( $list );
		}
		$search.on( 'input', function () {
			var q = String( $search.val() || '' ).toLowerCase().trim();
			$list.children( 'li[data-name]' ).each( function () {
				$( this ).toggle( q === '' || $( this ).attr( 'data-name' ).indexOf( q ) !== -1 );
			} );
		} );
		$wrap.append( $search, $list );

		var $new = null;
		if ( cfg.allowNew ) {
			$new = $( '<input type="text" class="ck-terms-new" />' )
				.attr( 'placeholder', I.addNewTerms || 'Add new (comma-separated)' );
			$wrap.append( $new );
		}
		ctx.getValue = function () {
			var ids = $list.find( 'input:checked' ).map( function () { return this.value; } ).get();
			var extra = $new ? String( $new.val() || '' ).replace( /\|/g, ' ' ).trim() : '';
			return ids.join( ',' ) + ( extra ? '|' + extra : '' );
		};
		return $wrap;
	}

	/** Featured image: current thumbnail + "Choose image…" (media library) + "Remove". */
	function buildMediaInput( ctx, $popover, $cell ) {
		var $wrap = $( '<div class="ck-media" />' );
		var $preview = $( '<div class="ck-media-preview" />' ).html( $cell.clone().children( '.ck-edit-trigger' ).remove().end().html() );
		var $choose = $( '<button type="button" class="button button-primary ck-focus" />' ).text( I.chooseImage || 'Choose image…' );
		var $remove = $( '<button type="button" class="button-link ck-media-remove" />' ).text( I.removeImage || 'Remove' );
		if ( ! ctx.value ) { $remove.prop( 'hidden', true ); }
		$wrap.append( $preview, $( '<div class="ck-media-actions" />' ).append( $choose, $remove ) );

		$choose.on( 'click', function () {
			if ( typeof wp === 'undefined' || ! wp.media ) { return; }
			var frame = wp.media( {
				title:    I.useImage || 'Set featured image',
				button:   { text: I.useImage || 'Set featured image' },
				library:  { type: 'image' },
				multiple: false
			} );
			frame.on( 'open', function () {
				var sel = frame.state().get( 'selection' );
				var att = ctx.value ? wp.media.attachment( ctx.value ) : null;
				if ( att ) { att.fetch(); sel.add( att ); }
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first();
				if ( att ) { saveValue( ctx, String( att.get( 'id' ) ), $popover, $cell ); }
			} );
			frame.on( 'close', function () { setTimeout( function () { mediaOpen = false; }, 0 ); } );
			mediaOpen = true;
			frame.open();
		} );
		$remove.on( 'click', function () { saveValue( ctx, '0', $popover, $cell ); } );
		return $wrap;
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

	// ------------------------------------------------------------------
	// Save
	// ------------------------------------------------------------------

	function doSave( ctx, $popover, $cell ) {
		var value = ctx.getValue ? ctx.getValue() : $popover.find( '.ck-input' ).first().val();
		saveValue( ctx, value, $popover, $cell );
	}

	function saveValue( ctx, value, $popover, $cell ) {
		var $status = $popover.find( '.ck-status' ).removeClass( 'error success' ).text( I.saving || 'Saving…' );
		var $buttons = $popover.find( 'button' ).prop( 'disabled', true );

		var payload = {
			action:      CK_INLINE.action || 'ck_inline_save',
			_ajax_nonce: CK_INLINE.nonce,
			col_id:      ctx.colId,
			set:         CK_INLINE.set || 'default',
			value:       value
		};
		if ( ctx.objectType === 'post' ) {
			payload.post_id = ctx.objectId;
		} else {
			payload.object    = ctx.objectType;
			payload.object_id = ctx.objectId;
			payload.screen    = CK_INLINE.screen || screenFromPage();
		}

		$.ajax( {
			url:      CK_INLINE.ajaxUrl,
			type:     'POST',
			dataType: 'json',
			data:     payload
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
				$buttons.prop( 'disabled', false );
			}
		} ).fail( function () {
			$status.addClass( 'error' ).text( I.networkError || 'Network error' );
			$buttons.prop( 'disabled', false );
		} );
	}

	/** Screen key for core term/user edits when no ColumnKit columns are configured there. */
	function screenFromPage() {
		if ( CK_INLINE.coreObject === 'user' ) { return 'users'; }
		var tax = $( 'input[name="taxonomy"]' ).first().val() || '';
		return tax ? 'taxonomy:' + tax : '';
	}
} )( jQuery );

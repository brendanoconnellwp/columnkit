/* global jQuery, CK_I18N */
/**
 * Column editor (Settings → Admin Columns).
 *
 * One ordered list holds WordPress's built-in columns and custom columns. Every row carries
 * inputs named columns[N][…]; N is rewritten on every reorder/add/remove so the POSTed order
 * IS the column order. Built-in rows toggle a hidden "hidden" input; custom rows expand to
 * show their settings.
 */
( function ( $ ) {
	'use strict';

	var i18n = window.CK_I18N || {};
	var $form, $list, $picker, $pickerSearch, $addToggle;
	var dirty = false;
	var submitting = false;

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	function reindex() {
		$list.children( '.ck-row' ).each( function ( i, el ) {
			$( el ).find( '[name^="columns["]' ).each( function () {
				this.name = this.name.replace( /^columns\[\d+\]/, 'columns[' + i + ']' );
			} );
		} );
		$( '.ck-empty-note' ).toggle( $list.children( '.ck-row' ).length === 0 );
	}

	function markDirty() {
		if ( dirty ) { return; }
		dirty = true;
		$( '.ck-dirty' ).prop( 'hidden', false );
		$( '.ck-savebar' ).addClass( 'is-dirty' );
	}

	function generateId() {
		return 'col' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function initColorPickers( $scope ) {
		if ( ! $.fn.wpColorPicker ) { return; }
		$scope.find( '.ck-color' ).each( function () {
			var $i = $( this );
			if ( $i.data( 'ckColorInit' ) ) { return; }
			$i.data( 'ckColorInit', true ).wpColorPicker( { change: markDirty, clear: markDirty } );
		} );
	}

	/** Short "which field / key" detail next to a custom row's type badge. */
	function updateDetail( $row ) {
		var text = '';
		var $sel = $row.find( '.ck-setting-select' ).first();
		if ( $sel.length && $sel.val() ) {
			text = $sel.find( 'option:selected' ).text().replace( /\s*\([^)]*\)\s*$/, '' );
		} else {
			var $txt = $row.find( '.ck-setting-text[data-setting="meta_key"]' ).first();
			if ( $txt.length ) { text = $txt.val() || ''; }
		}
		$row.find( '.ck-type-detail' ).text( text ? '› ' + text : '' );
	}

	function setOpen( $row, open ) {
		var $body = $row.children( '.ck-row-body' );
		$row.toggleClass( 'is-open', open );
		$body.prop( 'hidden', ! open );
		$row.find( '.ck-expand' ).attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) { initColorPickers( $body ); }
	}

	// ------------------------------------------------------------------
	// Add column
	// ------------------------------------------------------------------

	function addColumn( type, label, preset ) {
		var tpl = document.getElementById( 'ck-tpl-' + String( type ).replace( /[^a-z0-9_-]/gi, '' ) );
		if ( ! tpl ) { return; }
		var frag = tpl.content.cloneNode( true );
		var row = frag.querySelector( '.ck-row' );
		var id = generateId();

		var idInput = row.querySelector( 'input[name$="[id]"]' );
		if ( idInput ) { idInput.value = id; }
		var body = row.querySelector( '.ck-row-body' );
		if ( body ) {
			body.id = 'ck-body-' + id;
			var exp = row.querySelector( '.ck-expand' );
			if ( exp ) { exp.setAttribute( 'aria-controls', body.id ); }
		}
		if ( label ) {
			var labelInput = row.querySelector( '.ck-label-input' );
			if ( labelInput ) { labelInput.value = label; }
		}
		if ( preset && typeof preset === 'object' ) {
			Object.keys( preset ).forEach( function ( key ) {
				var field = row.querySelector( '[data-setting="' + key.replace( /"/g, '' ) + '"]' );
				if ( field ) { field.value = preset[ key ]; }
			} );
		}

		$list[ 0 ].appendChild( frag );
		var $row = $list.children( '.ck-row' ).last();
		reindex();
		updateDetail( $row );
		// A preset (field picked from the list) needs no further setup; otherwise open settings.
		setOpen( $row, ! ( preset && Object.keys( preset ).length ) );
		$row.addClass( 'is-new' );
		window.setTimeout( function () { $row.removeClass( 'is-new' ); }, 1600 );
		$row[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		$row.find( '.ck-label-input' ).trigger( 'focus' ).trigger( 'select' );
		markDirty();
	}

	// ------------------------------------------------------------------
	// Picker
	// ------------------------------------------------------------------

	function openPicker( open ) {
		$picker.prop( 'hidden', ! open );
		$addToggle.attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			$pickerSearch.val( '' );
			filterPicker();
			$pickerSearch.trigger( 'focus' );
		}
	}

	function filterPicker() {
		var q = String( $pickerSearch.val() || '' ).toLowerCase().trim();
		var shown = 0;
		$picker.find( '.ck-picker-group' ).each( function () {
			var groupShown = 0;
			$( this ).find( '.ck-picker-item' ).each( function () {
				var match = q === '' || ( $( this ).attr( 'data-search' ) || '' ).indexOf( q ) !== -1;
				$( this ).closest( 'li' ).prop( 'hidden', ! match );
				if ( match ) { groupShown++; }
			} );
			$( this ).prop( 'hidden', groupShown === 0 );
			shown += groupShown;
		} );
		$picker.find( '.ck-picker-empty' ).prop( 'hidden', shown !== 0 );
	}

	function pickItem( $item ) {
		var preset = {};
		try { preset = JSON.parse( $item.attr( 'data-preset' ) || '{}' ) || {}; } catch ( e ) { preset = {}; }
		addColumn( $item.attr( 'data-type' ), $item.attr( 'data-label' ), preset );
		openPicker( false );
	}

	// ------------------------------------------------------------------
	// Meta-key suggestions (datalist)
	// ------------------------------------------------------------------

	function loadMetaKeys() {
		var $dl = $( '#ck-meta-keys' );
		var screen = $dl.attr( 'data-screen' ) || '';
		if ( ! $dl.length || ! screen || ! i18n.ajaxUrl || ! i18n.metaNonce ) { return; }
		$.post( i18n.ajaxUrl, {
			action:      i18n.metaAction || 'ck_meta_keys',
			_ajax_nonce: i18n.metaNonce,
			screen:      screen
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success || ! resp.data || ! resp.data.keys ) { return; }
			var frag = document.createDocumentFragment();
			resp.data.keys.forEach( function ( key ) {
				var opt = document.createElement( 'option' );
				opt.value = key;
				frag.appendChild( opt );
			} );
			$dl.empty()[ 0 ].appendChild( frag );
		} );
	}

	// ------------------------------------------------------------------
	// Init
	// ------------------------------------------------------------------

	function init() {
		$form        = $( '#ck-form' );
		$list        = $( '#ck-columns' );
		$picker      = $( '#ck-picker' );
		$pickerSearch = $picker.find( '.ck-picker-search' );
		$addToggle   = $( '.ck-add-toggle' );
		if ( ! $list.length ) { return; }

		loadMetaKeys();
		$list.children( '.ck-row--custom' ).each( function () { updateDetail( $( this ) ); } );
		initColorPickers( $list.children( '.ck-row.is-open' ) );

		if ( $.fn.sortable ) {
			$list.sortable( {
				handle: '.ck-handle',
				axis: 'y',
				placeholder: 'ck-placeholder',
				forcePlaceholderSize: true,
				update: function () { reindex(); markDirty(); }
			} );
		}

		// Dirty tracking + leave warning.
		$form.on( 'input change', 'input, select', markDirty );
		$form.on( 'submit', function () { submitting = true; } );
		$( window ).on( 'beforeunload', function () {
			if ( dirty && ! submitting ) { return i18n.leaveWarning || ''; }
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.ctrlKey || e.metaKey ) && ( e.key === 's' || e.key === 'S' ) ) {
				e.preventDefault();
				submitting = true;
				if ( $form[ 0 ].requestSubmit ) {
					$form[ 0 ].requestSubmit();
				} else {
					$form[ 0 ].submit();
				}
			}
		} );

		// Built-in column show/hide.
		$list.on( 'click', '.ck-visibility', function () {
			var $row = $( this ).closest( '.ck-row' );
			var hide = ! $row.hasClass( 'is-hidden' );
			$row.toggleClass( 'is-hidden', hide );
			$row.find( '.ck-hidden-input' ).val( hide ? '1' : '0' );
			$( this ).attr( 'aria-pressed', hide ? 'false' : 'true' )
				.find( '.dashicons' ).toggleClass( 'dashicons-visibility', ! hide ).toggleClass( 'dashicons-hidden', hide );
			markDirty();
		} );

		// Custom column expand / remove / detail.
		$list.on( 'click', '.ck-expand', function () {
			var $row = $( this ).closest( '.ck-row' );
			setOpen( $row, ! $row.hasClass( 'is-open' ) );
		} );
		$list.on( 'click', '.ck-remove', function () {
			if ( i18n.removeConfirm && ! window.confirm( i18n.removeConfirm ) ) { return; }
			$( this ).closest( '.ck-row' ).remove();
			reindex();
			markDirty();
		} );
		$list.on( 'change input', '.ck-setting-select, .ck-setting-text', function () {
			updateDetail( $( this ).closest( '.ck-row' ) );
		} );

		// Picker.
		$addToggle.on( 'click', function ( e ) {
			e.stopPropagation();
			openPicker( $picker.prop( 'hidden' ) );
		} );
		$pickerSearch.on( 'input', filterPicker );
		$pickerSearch.on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { openPicker( false ); $addToggle.trigger( 'focus' ); }
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				var $first = $picker.find( 'li:not([hidden]) .ck-picker-item' ).first();
				if ( $first.length ) { pickItem( $first ); }
			}
		} );
		$picker.on( 'click', '.ck-picker-item', function () { pickItem( $( this ) ); } );
		$( document ).on( 'click', function ( e ) {
			if ( ! $picker.prop( 'hidden' ) && ! $( e.target ).closest( '.ck-add' ).length ) { openPicker( false ); }
			// Close any open dropdown menu when clicking elsewhere.
			$( 'details.ck-menu[open]' ).each( function () {
				if ( ! $.contains( this, e.target ) ) { this.removeAttribute( 'open' ); }
			} );
		} );

		// Sidebar screen filter.
		$( '#ck-screen-filter' ).on( 'input', function () {
			var q = String( $( this ).val() || '' ).toLowerCase().trim();
			$( '.ck-nav-group' ).each( function () {
				var any = false;
				$( this ).find( 'li' ).each( function () {
					var match = q === '' || ( $( this ).attr( 'data-search' ) || '' ).indexOf( q ) !== -1;
					$( this ).prop( 'hidden', ! match );
					any = any || match;
				} );
				$( this ).prop( 'hidden', ! any );
			} );
		} );

		// Import / export panel.
		$( '.ck-tools-toggle' ).on( 'click', function () {
			var $tools = $( '#ck-tools' );
			var open = $tools.prop( 'hidden' );
			$tools.prop( 'hidden', ! open );
			$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
		} );

		// Confirm destructive view actions.
		$( 'form[data-ck-confirm]' ).on( 'submit', function ( e ) {
			if ( ! window.confirm( $( this ).attr( 'data-ck-confirm' ) ) ) { e.preventDefault(); }
		} );

		// Only one dropdown menu open at a time.
		$( 'details.ck-menu' ).on( 'toggle', function () {
			if ( this.open ) {
				$( 'details.ck-menu[open]' ).not( this ).removeAttr( 'open' );
				$( this ).find( 'input[type="text"]' ).first().trigger( 'focus' );
			}
		} );
	}

	$( init );
} )( jQuery );

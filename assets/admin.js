/* global jQuery, CK_I18N */
( function ( $ ) {
	'use strict';

	var $list, $picker, $pickerSearch, $addToggle;

	function reindex() {
		$list.children( '.ck-column-row' ).each( function ( i, el ) {
			$( el )
				.find( 'input[name], select[name]' )
				.each( function () {
					var $f = $( this );
					var name = $f.attr( 'name' );
					if ( ! name ) { return; }
					$f.attr(
						'name',
						name.replace( /columns\[\d+\]/, 'columns[' + i + ']' )
					);
				} );
		} );
	}

	function generateId() {
		return 'col' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function initColorPickers( $scope ) {
		if ( ! $.fn.wpColorPicker ) { return; }
		( $scope || $list ).find( '.ck-color' ).each( function () {
			var $i = $( this );
			if ( $i.data( 'ckColorInit' ) ) { return; }
			$i.data( 'ckColorInit', true ).wpColorPicker();
		} );
	}

	function updateSummary( $row ) {
		var label = $row.find( '.ck-label-input' ).first().val() || '';
		$row.find( '.ck-head-summary' ).first().text( label );
	}

	function setCollapsed( $row, collapsed ) {
		$row.toggleClass( 'is-collapsed', collapsed );
		$row.find( '.ck-collapse-toggle' ).attr( 'aria-expanded', collapsed ? 'false' : 'true' );
	}

	function addColumn( type ) {
		var tplId = 'ck-tpl-' + type.replace( /[^a-z0-9_-]/gi, '' );
		var tpl = document.getElementById( tplId );
		if ( ! tpl ) { return; }
		var clone = tpl.content.cloneNode( true );
		var idInput = clone.querySelector( 'input[name$="[id]"]' );
		if ( idInput ) { idInput.value = generateId(); }
		var $row = $( clone.querySelector( '.ck-column-row' ) );
		$list[ 0 ].appendChild( clone );
		reindex();
		initColorPickers( $row );
		updateSummary( $row );
		// Newly added rows open expanded so the user can configure them immediately.
		setCollapsed( $row, false );
		$row[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		$row.find( '.ck-label-input' ).first().focus();
	}

	function openPicker( open ) {
		$picker.attr( 'hidden', open ? null : 'hidden' );
		$addToggle.attr( 'aria-expanded', open ? 'true' : 'false' );
		if ( open ) {
			$pickerSearch.val( '' ).trigger( 'input' ).focus();
		}
	}

	function filterPicker() {
		var q = ( $pickerSearch.val() || '' ).toString().toLowerCase().trim();
		var shown = 0;
		$picker.find( '.ck-picker-item' ).each( function () {
			var hay = $( this ).attr( 'data-search' ) || '';
			var match = q === '' || hay.indexOf( q ) !== -1;
			$( this ).closest( 'li' ).toggle( match );
			if ( match ) { shown++; }
		} );
		$picker.find( '.ck-picker-empty' ).attr( 'hidden', shown === 0 ? null : 'hidden' );
	}

	function loadMetaKeys() {
		var $dl = $( '#ck-meta-keys' );
		var screen = $dl.attr( 'data-screen' ) || '';
		if ( ! $dl.length || ! screen || ! CK_I18N || ! CK_I18N.ajaxUrl || ! CK_I18N.metaNonce ) {
			return;
		}
		$.post( CK_I18N.ajaxUrl, {
			action:      CK_I18N.metaAction || 'ck_meta_keys',
			_ajax_nonce: CK_I18N.metaNonce,
			screen:      screen
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success || ! resp.data || ! resp.data.keys ) {
				return;
			}
			var frag = document.createDocumentFragment();
			resp.data.keys.forEach( function ( key ) {
				var opt = document.createElement( 'option' );
				opt.value = key;
				frag.appendChild( opt );
			} );
			$dl.empty();
			$dl[ 0 ].appendChild( frag );
		} );
	}

	function init() {
		$list         = $( '#ck-columns' );
		$picker       = $( '.ck-picker' );
		$pickerSearch = $( '.ck-picker-search' );
		$addToggle    = $( '.ck-add-toggle' );

		loadMetaKeys();

		if ( $.fn.sortable ) {
			$list.sortable( {
				handle: '.ck-handle',
				placeholder: 'ck-placeholder',
				forcePlaceholderSize: true,
				update: reindex,
			} );
		}

		initColorPickers();

		// Collapse / expand a row. Clicking the head (but not the remove/handle) toggles too.
		$list.on( 'click', '.ck-collapse-toggle', function () {
			var $row = $( this ).closest( '.ck-column-row' );
			setCollapsed( $row, ! $row.hasClass( 'is-collapsed' ) );
		} );
		$list.on( 'click', '.ck-head-text', function () {
			var $row = $( this ).closest( '.ck-column-row' );
			setCollapsed( $row, ! $row.hasClass( 'is-collapsed' ) );
		} );

		// Keep the collapsed-row summary in sync with the label field.
		$list.on( 'input', '.ck-label-input', function () {
			updateSummary( $( this ).closest( '.ck-column-row' ) );
		} );

		$list.on( 'click', '.ck-remove', function ( e ) {
			e.stopPropagation();
			if ( CK_I18N && CK_I18N.removeConfirm && ! window.confirm( CK_I18N.removeConfirm ) ) {
				return;
			}
			$( this ).closest( '.ck-column-row' ).remove();
			reindex();
		} );

		// Column picker.
		$addToggle.on( 'click', function () {
			openPicker( $addToggle.attr( 'aria-expanded' ) !== 'true' );
		} );
		$pickerSearch.on( 'input', filterPicker );
		$pickerSearch.on( 'keydown', function ( e ) {
			if ( e.which === 27 ) { openPicker( false ); }
		} );
		$picker.on( 'click', '.ck-picker-item', function () {
			addColumn( $( this ).attr( 'data-type' ) );
			openPicker( false );
		} );
	}

	$( init );
} )( jQuery );

/* global jQuery, CK_I18N */
( function ( $ ) {
	'use strict';

	var $list, $addType, $addBtn;

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

	function addColumn( type ) {
		var tplId = 'ck-tpl-' + type.replace( /[^a-z0-9_-]/gi, '' );
		var tpl = document.getElementById( tplId );
		if ( ! tpl ) { return; }
		var clone = tpl.content.cloneNode( true );
		// Stamp a fresh id into the hidden [id] input.
		var idInput = clone.querySelector( 'input[name$="[id]"]' );
		if ( idInput ) { idInput.value = generateId(); }
		var $row = $( clone.querySelector( '.ck-column-row' ) );
		$list[ 0 ].appendChild( clone );
		reindex();
		initColorPickers( $row );
	}

	function init() {
		$list = $( '#ck-columns' );
		$addType = $( '#ck-add-type' );
		$addBtn = $( '#ck-add' );

		if ( $.fn.sortable ) {
			$list.sortable( {
				handle: '.ck-handle',
				placeholder: 'ck-placeholder',
				forcePlaceholderSize: true,
				update: reindex,
			} );
		}

		initColorPickers();

		$addBtn.on( 'click', function () {
			addColumn( $addType.val() );
		} );

		$list.on( 'click', '.ck-remove', function () {
			if ( CK_I18N && CK_I18N.removeConfirm && ! window.confirm( CK_I18N.removeConfirm ) ) {
				return;
			}
			$( this ).closest( '.ck-column-row' ).remove();
			reindex();
		} );
	}

	$( init );
} )( jQuery );

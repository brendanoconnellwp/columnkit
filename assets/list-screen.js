/* List-table helpers:
 *  - Column-view switcher: navigate to the selected view's URL. Option values are full URLs
 *    carrying ?ck_set={id}, built server-side with add_query_arg.
 *  - "Columns" shortcut next to the page title (admins only — CK_LIST is only printed for
 *    users who can manage columns), linking straight to this screen in the column editor. */
( function () {
	'use strict';

	function onChange( e ) {
		var el = e.target;
		if ( ! el || ! el.getAttribute || el.getAttribute( 'data-ck-switcher' ) !== '1' ) {
			return;
		}
		var url = el.value;
		if ( url ) {
			window.location.href = url;
		}
	}

	function addManageButton() {
		var cfg = window.CK_LIST;
		if ( ! cfg || ! cfg.manageUrl ) {
			return;
		}
		var heading = document.querySelector( '.wrap .wp-heading-inline' ) || document.querySelector( '.wrap > h1' );
		if ( ! heading || document.querySelector( '.ck-manage-columns' ) ) {
			return;
		}
		var link = document.createElement( 'a' );
		link.className = 'page-title-action ck-manage-columns';
		link.href = cfg.manageUrl;
		link.title = cfg.manageTitle || '';
		var icon = document.createElement( 'span' );
		icon.className = 'dashicons dashicons-columns';
		icon.setAttribute( 'aria-hidden', 'true' );
		link.appendChild( icon );
		link.appendChild( document.createTextNode( ' ' + ( cfg.manageLabel || 'Columns' ) ) );

		// After the last existing title action ("Add New" etc.), else right after the heading.
		var actions = document.querySelectorAll( '.wrap > .page-title-action' );
		var anchor = actions.length ? actions[ actions.length - 1 ] : heading;
		anchor.parentNode.insertBefore( link, anchor.nextSibling );
	}

	document.addEventListener( 'change', onChange );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', addManageButton );
	} else {
		addManageButton();
	}
} )();

/* Column-view switcher: navigate to the selected view's URL. Option values are full URLs
 * carrying ?ck_set={id}, built server-side with add_query_arg. */
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

	document.addEventListener( 'change', onChange );
} )();

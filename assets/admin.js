/**
 * WooCommerce → Toolbox POD: "Copy report" and the Disconnect confirmation. No libraries.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-toolbox-pod-copy]' );
		if ( ! button ) {
			return;
		}
		var field = document.getElementById( button.getAttribute( 'data-toolbox-pod-copy' ) );
		if ( ! field ) {
			return;
		}
		var done = function () {
			button.textContent = ( window.toolboxPodAdmin && window.toolboxPodAdmin.copied ) || 'Copied';
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( field.value ).then( done );
		} else {
			field.select();
			document.execCommand( 'copy' );
			done();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var message = event.target.getAttribute( 'data-toolbox-pod-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )();

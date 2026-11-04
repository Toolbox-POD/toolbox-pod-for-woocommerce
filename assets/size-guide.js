/**
 * Size guide tab: shows the unit switch and swaps the inch and cm tables (includes/class-toolbox-pod-size-guide.php).
 */
( function () {
	document.querySelectorAll( '[data-toolbox-pod-size-guide]' ).forEach( function ( guide ) {
		var units = guide.querySelector( '.toolbox-pod-size-guide__units' );
		if ( ! units ) {
			return;
		}
		units.hidden = false;
		units.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-unit]' );
			if ( ! button ) {
				return;
			}
			var unit = button.getAttribute( 'data-unit' );
			guide.querySelectorAll( 'table[data-unit]' ).forEach( function ( table ) {
				table.hidden = table.getAttribute( 'data-unit' ) !== unit;
			} );
			units.querySelectorAll( 'button[data-unit]' ).forEach( function ( other ) {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );
		} );
	} );
} )();

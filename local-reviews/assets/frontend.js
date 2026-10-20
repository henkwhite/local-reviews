/**
 * Local Reviews – „Weiterlesen“ bei gekürzten Bewertungstexten.
 *
 * Ohne JavaScript bleibt die gekürzte Fassung sichtbar und der Schalter versteckt.
 */
( function () {
	function init() {
		document.querySelectorAll( '.lr-card__more' ).forEach( function ( button ) {
			var text = button.closest( '.lr-card__text' );
			var short = text && text.querySelector( '.lr-card__short' );
			var full = text && text.querySelector( '.lr-card__full' );
			if ( ! short || ! full || button.dataset.ready ) {
				return;
			}
			var more = button.textContent;
			var less = button.getAttribute( 'data-less' ) || more;
			button.dataset.ready = '1';
			button.hidden = false;
			button.addEventListener( 'click', function () {
				var open = full.hidden;
				full.hidden = ! open;
				short.hidden = open;
				button.textContent = open ? less : more;
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

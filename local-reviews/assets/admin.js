/**
 * Local Reviews – Backend: Rückfragen, Mediathek-Auswahl, Freitext bei „Sonstige“.
 */
( function ( $ ) {
	var l10n = window.localReviewsAdmin || {};

	// Rückfrage vor dem Löschen.
	$( document ).on( 'submit', '.local-reviews-confirm-delete', function () {
		return window.confirm( l10n.confirmDelete );
	} );
	$( document ).on( 'submit', '.local-reviews-confirm-legacy', function () {
		return window.confirm( l10n.confirmDeleteLegacy );
	} );

	// Freitextfeld nur bei Plattform „Sonstige“.
	function toggleSourceLabel() {
		$( '#lr-source-label' ).toggle( $( '#lr-source' ).val() === 'other' );
	}
	$( document ).on( 'change', '#lr-source', toggleSourceLabel );
	$( toggleSourceLabel );

	// Bild aus der Mediathek wählen (Foto im Kopfbereich, Logo im Badge).
	// data-target: Präfix der Felder {target}-id und {target}-preview,
	// data-radio: Name der Einstellung, deren Option „Bild“ dabei ausgewählt wird.
	var frames = {};
	$( document ).on( 'click', '.lr-media-select', function ( event ) {
		event.preventDefault();
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var target = $( this ).data( 'target' );
		var radio = $( this ).data( 'radio' );
		if ( ! frames[ target ] ) {
			frames[ target ] = window.wp.media( {
				title: l10n.mediaTitle,
				button: { text: l10n.mediaButton },
				library: { type: 'image' },
				multiple: false
			} );
			frames[ target ].on( 'select', function () {
				var attachment = frames[ target ].state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				$( '#' + target + '-id' ).val( attachment.id );
				$( '#' + target + '-preview' ).attr( 'src', url ).prop( 'hidden', false );
				$( '.lr-media-remove[data-target="' + target + '"]' ).prop( 'hidden', false );
				$( 'input[name="local_reviews[' + radio + ']"][value="' + ( radio === 'photo_source' ? 'media' : 'image' ) + '"]' ).prop( 'checked', true );
			} );
		}
		frames[ target ].open();
	} );
	$( document ).on( 'click', '.lr-media-remove', function ( event ) {
		event.preventDefault();
		var target = $( this ).data( 'target' );
		$( '#' + target + '-id' ).val( '0' );
		$( '#' + target + '-preview' ).attr( 'src', '' ).prop( 'hidden', true );
		$( this ).prop( 'hidden', true );
	} );
} )( jQuery );

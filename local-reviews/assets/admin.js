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

	// Foto aus der Mediathek wählen.
	var frame;
	$( document ).on( 'click', '#lr-photo-select', function ( event ) {
		event.preventDefault();
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		if ( ! frame ) {
			frame = window.wp.media( {
				title: l10n.mediaTitle,
				button: { text: l10n.mediaButton },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
				$( '#lr-photo-id' ).val( attachment.id );
				$( '#lr-photo-preview' ).attr( 'src', url ).prop( 'hidden', false );
				$( '#lr-photo-remove' ).prop( 'hidden', false );
				$( 'input[name="local_reviews[photo_source]"][value="media"]' ).prop( 'checked', true );
			} );
		}
		frame.open();
	} );
	$( document ).on( 'click', '#lr-photo-remove', function ( event ) {
		event.preventDefault();
		$( '#lr-photo-id' ).val( '0' );
		$( '#lr-photo-preview' ).attr( 'src', '' ).prop( 'hidden', true );
		$( this ).prop( 'hidden', true );
	} );
} )( jQuery );

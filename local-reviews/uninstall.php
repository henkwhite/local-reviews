<?php
/**
 * Entfernt beim Deinstallieren alle Daten des Plugins: Optionen, Transients,
 * Cron und den Foto-Ordner uploads/local-reviews/.
 *
 * Daten der alten Fluent-Snippets (mein_google_reviews_archiv, google_profil_*,
 * uploads/google-profil/) gehören nicht dem Plugin und bleiben unangetastet.
 *
 * @package LocalReviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Löscht die Daten der aktuellen Site.
 */
function local_reviews_uninstall_site(): void {
	foreach ( [ 'local_reviews_settings', 'local_reviews_api_key', 'local_reviews_archive', 'local_reviews_profile', 'local_reviews_status', 'local_reviews_migrated' ] as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'local_reviews_fetch' );
	wp_clear_scheduled_hook( 'local_reviews_catchup' );

	// Import-Vorschauen (pro Benutzer) und Fehlermeldungen.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_local\\_reviews\\_%' OR option_name LIKE '\\_transient\\_timeout\\_local\\_reviews\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// Foto-Ordner des Plugins.
	$upload = wp_upload_dir( null, false );
	$dir    = trailingslashit( $upload['basedir'] ) . 'local-reviews';
	// Direkt löschen: Der Ordner enthält nur profile.{jpg,png,webp}; WP_Filesystem
	// bräuchte auf manchen Servern FTP-Zugangsdaten.
	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $local_reviews_site_id ) {
		switch_to_blog( (int) $local_reviews_site_id );
		local_reviews_uninstall_site();
		restore_current_blog();
	}
} else {
	local_reviews_uninstall_site();
}

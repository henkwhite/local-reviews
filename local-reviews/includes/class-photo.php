<?php
/**
 * Profilfoto lokal speichern.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Lädt das erste Ortsfoto (photos[0]) und legt es in uploads/local-reviews/ ab,
 * damit Besucher keine Verbindung zu Google aufbauen.
 */
final class Photo {

	/**
	 * Unterordner in uploads.
	 */
	public const DIR = 'local-reviews';

	/**
	 * Nach so vielen Tagen wird das Foto neu geladen, auch wenn es gleich geblieben ist.
	 * Google erlaubt Caching von Fotos höchstens 30 Tage.
	 */
	public const MAX_AGE_DAYS = 7;

	/**
	 * Ob das Foto neu geladen werden muss.
	 *
	 * Den Ressourcennamen von photos[0] vergibt Google bei jedem Abruf neu (vermutet,
	 * laut Doku nicht stabil). Deshalb zählt nur das Alter der Datei.
	 *
	 * @param bool $force Immer neu laden (Button „Jetzt aktualisieren“).
	 */
	public static function needs_refresh( bool $force ): bool {
		$profile = Profile::get();
		if ( $force || '' === $profile['photo_url'] || 0 === $profile['photo_time'] ) {
			return true;
		}
		return ( time() - $profile['photo_time'] ) > self::MAX_AGE_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Holt das Foto über den Media-Endpunkt und speichert es lokal.
	 *
	 * @param string $photo_name Ressourcenname, z. B. places/…/photos/….
	 * @return string|\WP_Error Lokale URL mit Cache-Buster oder Fehler.
	 */
	public static function fetch( string $photo_name ): string|\WP_Error {
		if ( ! preg_match( '#^places/[A-Za-z0-9_-]+/photos/[A-Za-z0-9_-]+$#', $photo_name ) ) {
			return new \WP_Error( 'local_reviews_photo', __( 'Unerwarteter Foto-Name von Google.', 'local-reviews' ) );
		}

		$res = wp_remote_get(
			'https://places.googleapis.com/v1/' . $photo_name . '/media?maxHeightPx=300&maxWidthPx=300&skipHttpRedirect=true',
			[
				'headers'     => [ 'X-Goog-Api-Key' => Settings::api_key() ],
				'timeout'     => 15,
				'redirection' => 0, // Den Key nie an ein Weiterleitungsziel schicken.
			]
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'local_reviews_photo', 'Foto HTTP ' . wp_remote_retrieve_response_code( $res ) . ': ' . substr( wp_remote_retrieve_body( $res ), 0, 300 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$uri  = is_array( $data ) ? (string) ( $data['photoUri'] ?? '' ) : '';
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
		if ( ! str_starts_with( $uri, 'https://' ) || ! preg_match( '/(^|\.)(googleusercontent\.com|ggpht\.com)$/', $host ) ) {
			return new \WP_Error( 'local_reviews_photo', __( 'Google hat keine gültige Foto-Adresse geliefert.', 'local-reviews' ) );
		}

		return self::download( $uri );
	}

	/**
	 * Lädt ein Bild herunter, prüft den Inhalt und speichert es als profile.{jpg|png|webp}.
	 *
	 * @param string $uri Öffentliche Bildadresse (lh3.googleusercontent.com).
	 * @return string|\WP_Error Lokale URL oder Fehler.
	 */
	private static function download( string $uri ): string|\WP_Error {
		$res = wp_safe_remote_get(
			$uri,
			[
				'timeout'             => 15,
				'redirection'         => 2,
				'limit_response_size' => 5 * MB_IN_BYTES,
			]
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = wp_remote_retrieve_body( $res );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || '' === $body ) {
			return new \WP_Error( 'local_reviews_photo', 'Foto-Download HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}

		// Inhalt prüfen statt dem Content-Type-Header zu vertrauen.
		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Ungültige Daten liefern false, die Warnung ist unnötig.
		$exts = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		];
		if ( ! is_array( $info ) || ! isset( $exts[ $info['mime'] ] ) ) {
			return new \WP_Error( 'local_reviews_photo', __( 'Die Foto-Datei ist kein gültiges Bild.', 'local-reviews' ) );
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			return new \WP_Error( 'local_reviews_photo', __( 'Upload-Ordner konnte nicht angelegt werden.', 'local-reviews' ) );
		}

		$file = 'profile.' . $exts[ $info['mime'] ];
		// Andere Endungen entfernen, damit kein veraltetes Foto liegen bleibt.
		foreach ( $exts as $ext ) {
			$old = $dir['path'] . '/profile.' . $ext;
			if ( 'profile.' . $ext !== $file && file_exists( $old ) ) {
				wp_delete_file( $old );
			}
		}

		// Direkt schreiben: Der Ordner gehört dem Plugin, und WP_Filesystem bräuchte
		// auf manchen Servern FTP-Zugangsdaten, die im Cron nicht vorliegen.
		if ( false === file_put_contents( $dir['path'] . '/' . $file, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new \WP_Error( 'local_reviews_photo', __( 'Foto konnte nicht gespeichert werden.', 'local-reviews' ) );
		}

		// Version aus dem Inhalt: Gleiches Foto ergibt gleiche URL, also kein unnötiges Cache-Leeren.
		return $dir['url'] . '/' . $file . '?v=' . substr( md5( $body ), 0, 8 );
	}

	/**
	 * Pfad und URL des Plugin-Ordners in uploads.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function dir(): array {
		$upload = wp_upload_dir( null, false );
		return [
			'path' => trailingslashit( $upload['basedir'] ) . self::DIR,
			'url'  => trailingslashit( $upload['baseurl'] ) . self::DIR,
		];
	}
}

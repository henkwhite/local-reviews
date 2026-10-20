<?php
/**
 * Profildaten (Note, Anzahl, Name, Links, Foto) und Abrufstatus.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf die Profil- und Statusoptionen.
 */
final class Profile {

	/**
	 * Gespeicherte Profildaten mit Standardwerten.
	 *
	 * @return array{rating: float, count: int, name: string, reviews_uri: string, write_uri: string, photo_url: string, photo_name: string, photo_time: int}
	 */
	public static function get(): array {
		$stored = get_option( OPTION_PROFILE, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'rating'      => (float) ( $stored['rating'] ?? 0 ),
			'count'       => (int) ( $stored['count'] ?? 0 ),
			'name'        => (string) ( $stored['name'] ?? '' ),
			'reviews_uri' => (string) ( $stored['reviews_uri'] ?? '' ),
			'write_uri'   => (string) ( $stored['write_uri'] ?? '' ),
			'photo_url'   => (string) ( $stored['photo_url'] ?? '' ),
			'photo_name'  => (string) ( $stored['photo_name'] ?? '' ),
			'photo_time'  => (int) ( $stored['photo_time'] ?? 0 ),
		];
	}

	/**
	 * Überschreibt einzelne Profilwerte.
	 *
	 * @param array<string, mixed> $values Zu ändernde Werte.
	 */
	public static function update( array $values ): void {
		update_option( OPTION_PROFILE, array_merge( self::get(), $values ), true );
	}

	/**
	 * Note und Anzahl laut Google.
	 *
	 * @return array{rating: float, count: int}
	 */
	public static function summary(): array {
		$profile = self::get();
		return [
			'rating' => $profile['rating'],
			'count'  => $profile['count'],
		];
	}

	/**
	 * Angezeigter Firmenname: Einstellung, sonst Name aus dem Google-Profil.
	 */
	public static function company_name(): string {
		$name = (string) Settings::get( 'company_name' );
		return '' !== $name ? $name : self::get()['name'];
	}

	/**
	 * URL des Fotos im Kopfbereich.
	 *
	 * Reihenfolge: Mediathek (falls gewählt), eigenes Google-Foto, Foto der alten
	 * Snippets (bis zum ersten Abruf), sonst leer.
	 */
	public static function photo_url(): string {
		if ( 'media' === Settings::get( 'photo_source' ) ) {
			$url = wp_get_attachment_image_url( (int) Settings::get( 'photo_id' ), 'thumbnail' );
			if ( $url ) {
				return $url;
			}
		}
		$own = self::get()['photo_url'];
		if ( '' !== $own ) {
			return $own;
		}
		return (string) get_option( 'google_profil_photo_url', '' );
	}

	/**
	 * Link zur Bewertungsliste bei Google.
	 */
	public static function reviews_url(): string {
		if ( 'custom' === Settings::get( 'button_mode' ) && '' !== (string) Settings::get( 'button_url' ) ) {
			return (string) Settings::get( 'button_url' );
		}
		$uri = self::get()['reviews_uri'];
		if ( '' !== $uri ) {
			return $uri;
		}
		$place_id = (string) Settings::get( 'place_id' );
		return '' !== $place_id ? 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $place_id ) : '';
	}

	/**
	 * Link „Bewertung schreiben“.
	 */
	public static function write_url(): string {
		$custom = (string) Settings::get( 'write_url' );
		if ( '' !== $custom ) {
			return $custom;
		}
		$uri = self::get()['write_uri'];
		if ( '' !== $uri ) {
			return $uri;
		}
		$place_id = (string) Settings::get( 'place_id' );
		return '' !== $place_id ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id ) : '';
	}

	/**
	 * Abrufstatus mit Standardwerten.
	 *
	 * @return array{last_error: string, last_error_time: int, last_success: int, last_attempt: int}
	 */
	public static function status(): array {
		$stored = get_option( OPTION_STATUS, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'last_error'      => (string) ( $stored['last_error'] ?? '' ),
			'last_error_time' => (int) ( $stored['last_error_time'] ?? 0 ),
			'last_success'    => (int) ( $stored['last_success'] ?? 0 ),
			'last_attempt'    => (int) ( $stored['last_attempt'] ?? 0 ),
		];
	}

	/**
	 * Überschreibt einzelne Statuswerte.
	 *
	 * @param array<string, mixed> $values Zu ändernde Werte.
	 */
	public static function update_status( array $values ): void {
		update_option( OPTION_STATUS, array_merge( self::status(), $values ), false );
	}

	/**
	 * Merkt sich einen Fehler. Ein eventuell enthaltener API-Key wird unkenntlich gemacht.
	 *
	 * @param string $message Fehlermeldung.
	 */
	public static function log_error( string $message ): void {
		$key = Settings::api_key();
		if ( '' !== $key ) {
			$message = str_replace( $key, '***', $message );
		}
		self::update_status(
			[
				'last_error'      => sanitize_textarea_field( mb_substr( $message, 0, 600, 'UTF-8' ) ),
				'last_error_time' => time(),
			]
		);
	}

	/**
	 * Ob der letzte erfolgreiche Abruf länger als 36 Stunden her ist.
	 */
	public static function is_stale(): bool {
		$last = self::status()['last_success'];
		return 0 === $last || ( time() - $last ) > 36 * HOUR_IN_SECONDS;
	}
}

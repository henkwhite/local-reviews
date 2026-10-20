<?php
/**
 * Plugin-Einstellungen und der getrennt gespeicherte API-Key.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf die Einstellungen. Alle Standardwerte sind kundenneutral.
 */
final class Settings {

	/**
	 * Standardwerte aller Einstellungen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'place_id'        => '',
			'language'        => '',        // Leer: Sprache der WordPress-Installation.
			'company_name'    => '',        // Leer: Name aus dem Google-Profil (displayName).
			'photo_source'    => 'google',  // google | media.
			'photo_id'        => 0,         // Anhang-ID bei photo_source = media.
			'star_color'      => '#fbbc05',
			'date_format'     => '',        // Leer: Datumsformat der WordPress-Installation.
			'sort'            => 'newest',  // newest | oldest | rating.
			'load_css'        => true,
			'button_mode'     => 'auto',    // auto | custom.
			'button_url'      => '',        // Eigene URL zur Bewertungsliste.
			'write_url'       => '',        // Eigene URL „Bewertung schreiben“, leer: automatisch.
			'badge_count'     => 'google',  // google: Anzahl laut Google | local: sichtbare Bewertungen im Plugin.
			'badge_logo'      => 'google',  // google: Schriftzug | none: kein Logo | image: Bild aus der Mediathek.
			'badge_logo_id'   => 0,        // Anhang-ID bei badge_logo = image.
			'badge_variant'   => 'classic', // classic: eine Zeile mit Logo | stacked: Sterne, Anzahl, Plattformen, Linktext rechts.
			'show_source'     => 'foreign', // Plattform-Hinweis „via …“: off | foreign | all.
			'source_position' => 'date', // date: hinter dem Datum | top: oben rechts neben dem Namen.
			'stars_position'  => 'below', // below: unter dem Namen | right: rechts neben dem Namen.
		];
	}

	/**
	 * Alle Einstellungen, fehlende mit Standardwerten aufgefüllt.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( OPTION_SETTINGS, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
	}

	/**
	 * Eine einzelne Einstellung.
	 *
	 * @param string $key Schlüssel der Einstellung.
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Überschreibt einzelne Einstellungen ohne Formular (z. B. bei der Migration).
	 *
	 * @param array<string, mixed> $values Zu ändernde Einstellungen.
	 */
	public static function update( array $values ): void {
		update_option( OPTION_SETTINGS, self::sanitize( array_merge( self::all(), $values ) ), true );
	}

	/**
	 * Der API-Key. Er liegt in einer eigenen Option ohne Autoload und
	 * erscheint nie im HTML der Einstellungsseite.
	 */
	public static function api_key(): string {
		return trim( (string) get_option( OPTION_API_KEY, '' ) );
	}

	/**
	 * Speichert oder löscht den API-Key.
	 *
	 * @param string $key Neuer Key, leer zum Löschen.
	 */
	public static function set_api_key( string $key ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			delete_option( OPTION_API_KEY );
			return;
		}
		if ( false === get_option( OPTION_API_KEY, false ) ) {
			add_option( OPTION_API_KEY, $key, '', false );
		} else {
			update_option( OPTION_API_KEY, $key, false );
		}
	}

	/**
	 * Maskierte Darstellung des Keys für die Einstellungsseite, z. B. „AIza…3fQ“.
	 */
	public static function api_key_masked(): string {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		return strlen( $key ) > 10 ? substr( $key, 0, 4 ) . '…' . substr( $key, -3 ) : '…';
	}

	/**
	 * Ob API-Key und Place ID gesetzt sind, also abgerufen werden kann.
	 */
	public static function is_configured(): bool {
		return '' !== self::api_key() && '' !== (string) self::get( 'place_id' );
	}

	/**
	 * Sprachcode für die Places API, z. B. „de“.
	 */
	public static function language(): string {
		$lang = (string) self::get( 'language' );
		if ( '' === $lang ) {
			$lang = substr( determine_locale(), 0, 2 );
		}
		return '' !== $lang ? $lang : 'en';
	}

	/**
	 * Datumsformat für die Ausgabe.
	 */
	public static function date_format(): string {
		$format = (string) self::get( 'date_format' );
		return '' !== $format ? $format : (string) get_option( 'date_format', 'd.m.Y' );
	}

	/**
	 * Legt beim Aktivieren Optionen und Cron an, ohne bestehende Werte zu überschreiben,
	 * und übernimmt einmalig die Daten der alten Snippets.
	 */
	public static function activate(): void {
		if ( false === get_option( OPTION_SETTINGS, false ) ) {
			add_option( OPTION_SETTINGS, self::defaults(), '', true ); // Autoload: wird im Frontend gebraucht.
		}
		Migration::maybe_run();
		if ( false === get_option( OPTION_ARCHIVE, false ) ) {
			add_option( OPTION_ARCHIVE, [], '', false );
		}
		Cron::schedule();
	}

	/**
	 * Beim Deaktivieren den Cron abmelden. Daten bleiben erhalten.
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Bereinigt Eingaben aus dem Einstellungsformular.
	 *
	 * Wird vom Formular-Handler und von update() aufgerufen. Erwartet den vollständigen
	 * Satz an Werten (fehlende Checkboxen gelten als „aus“).
	 * Der API-Key wird hier nicht gespeichert, siehe Admin_Page::handle_settings().
	 *
	 * @param mixed $input Rohdaten aus dem Formular oder aus update().
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::defaults();

		$place_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $input['place_id'] ?? '' ) );
		$language = strtolower( preg_replace( '/[^A-Za-z-]/', '', (string) ( $input['language'] ?? '' ) ) );
		$color    = sanitize_hex_color( (string) ( $input['star_color'] ?? '' ) );

		$photo_source = in_array( $input['photo_source'] ?? '', [ 'google', 'media' ], true ) ? $input['photo_source'] : 'google';
		$sort         = in_array( $input['sort'] ?? '', [ 'newest', 'oldest', 'rating' ], true ) ? $input['sort'] : 'newest';
		$button_mode  = in_array( $input['button_mode'] ?? '', [ 'auto', 'custom' ], true ) ? $input['button_mode'] : 'auto';

		return [
			'place_id'        => (string) $place_id,
			'language'        => substr( (string) $language, 0, 10 ),
			'company_name'    => sanitize_text_field( (string) ( $input['company_name'] ?? '' ) ),
			'photo_source'    => $photo_source,
			'photo_id'        => absint( $input['photo_id'] ?? 0 ),
			'star_color'      => $color ? $color : $defaults['star_color'],
			'date_format'     => sanitize_text_field( (string) ( $input['date_format'] ?? '' ) ),
			'sort'            => $sort,
			'load_css'        => ! empty( $input['load_css'] ),
			'button_mode'     => $button_mode,
			'button_url'      => esc_url_raw( (string) ( $input['button_url'] ?? '' ), [ 'http', 'https' ] ),
			'write_url'       => esc_url_raw( (string) ( $input['write_url'] ?? '' ), [ 'http', 'https' ] ),
			'badge_count'     => in_array( $input['badge_count'] ?? '', [ 'google', 'local' ], true ) ? $input['badge_count'] : 'google',
			'badge_logo'      => in_array( $input['badge_logo'] ?? '', [ 'google', 'none', 'image' ], true ) ? $input['badge_logo'] : 'google',
			'badge_logo_id'   => absint( $input['badge_logo_id'] ?? 0 ),
			'badge_variant'   => in_array( $input['badge_variant'] ?? '', [ 'classic', 'stacked' ], true ) ? $input['badge_variant'] : 'classic',
			'show_source'     => in_array( $input['show_source'] ?? '', [ 'off', 'foreign', 'all' ], true ) ? $input['show_source'] : 'foreign',
			'source_position' => in_array( $input['source_position'] ?? '', [ 'date', 'top' ], true ) ? $input['source_position'] : 'date',
			'stars_position'  => in_array( $input['stars_position'] ?? '', [ 'below', 'right' ], true ) ? $input['stars_position'] : 'below',
		];
	}
}

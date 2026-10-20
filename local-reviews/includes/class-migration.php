<?php
/**
 * Übernahme der Daten aus den alten Fluent-Snippets.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Kopiert die alten Optionen einmalig. Die alten Optionen bleiben als
 * Rückfallebene liegen, bis jemand „Altdaten löschen“ drückt.
 */
final class Migration {

	/**
	 * Optionen der alten Snippets.
	 */
	public const LEGACY_OPTIONS = [
		'mein_google_reviews_archiv',
		'google_profil_global_rating',
		'google_profil_global_count',
		'google_profil_photo_url',
		'google_profil_last_error',
	];

	/**
	 * Transient der alten Snippets mit dem Direktlink.
	 */
	public const LEGACY_TRANSIENT = 'kf_google_reviews_link';

	/**
	 * Cron-Hook der alten Snippets.
	 */
	public const LEGACY_CRON = 'google_reviews_sammler_hook';

	/**
	 * Ordner des alten Fotos in uploads.
	 */
	public const LEGACY_DIR = 'google-profil';

	/**
	 * Hooks registrieren (nur im Backend).
	 */
	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_run' ] );
		add_action( 'admin_init', [ self::class, 'unschedule_legacy_cron' ] );
		add_action( 'admin_notices', [ self::class, 'legacy_notice' ] );
	}

	/**
	 * Führt die Migration genau einmal aus.
	 *
	 * Läuft bei der Aktivierung und zur Sicherheit bei admin_init, falls das Plugin
	 * ohne Aktivierungs-Hook auf den Server kam.
	 */
	public static function maybe_run(): void {
		if ( get_option( OPTION_MIGRATED, false ) ) {
			return;
		}
		$legacy = get_option( 'mein_google_reviews_archiv', null );
		$own    = get_option( OPTION_ARCHIVE, null );

		// Eigenes Archiv hat schon Einträge: nichts übernehmen, Migration gilt als erledigt.
		if ( is_array( $own ) && [] !== $own ) {
			update_option( OPTION_MIGRATED, time(), false );
			return;
		}
		// Nur wenn alte Daten lesbar vorliegen. Sonst bleibt das Flag offen, damit eine
		// später eingespielte Altoption beim nächsten Backend-Aufruf noch übernommen wird.
		if ( is_array( $legacy ) && [] !== $legacy ) {
			self::migrate( $legacy );
			update_option( OPTION_MIGRATED, time(), false );
		}
	}

	/**
	 * Ob „Altdaten löschen“ gefahrlos ist: Alle alten Einträge müssen im eigenen Archiv stehen.
	 */
	public static function legacy_is_copied(): bool {
		$legacy = get_option( 'mein_google_reviews_archiv', null );
		if ( ! is_array( $legacy ) || [] === $legacy ) {
			return true;
		}
		$own = Store::all();
		foreach ( array_keys( $legacy ) as $id ) {
			if ( ! isset( $own[ (string) $id ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Kopiert Archiv, Profilwerte und Direktlink.
	 *
	 * @param array<mixed> $legacy Altes Archiv.
	 */
	private static function migrate( array $legacy ): void {
		$entries = [];
		foreach ( $legacy as $id => $old ) {
			if ( ! is_array( $old ) ) {
				continue;
			}
			// Der alte Schlüssel bleibt die ID, damit der Abruf keine Dubletten erzeugt.
			$entries[ (string) $id ] = array_merge(
				$old,
				[
					'id'     => (string) $id,
					'source' => 'google',
					'origin' => 'api',
					'text'   => Text::clean( (string) ( $old['text'] ?? '' ) ), // <br> aus dem alten Import.
				]
			);
		}
		Store::save_all( $entries );

		$profile = [];
		$rating  = get_option( 'google_profil_global_rating', '' );
		$count   = get_option( 'google_profil_global_count', '' );
		if ( '' !== $rating && false !== $rating ) {
			$profile['rating'] = round( (float) $rating, 1 );
		}
		if ( '' !== $count && false !== $count ) {
			$profile['count'] = (int) $count;
		}
		$link = get_transient( self::LEGACY_TRANSIENT );
		if ( is_string( $link ) && str_starts_with( $link, 'https://' ) && ! str_contains( $link, 'q=place_id:' ) ) {
			// Nur der echte reviewsUri, nicht der Fallback-Link auf die Place ID.
			$profile['reviews_uri'] = esc_url_raw( $link );
		}
		if ( $profile ) {
			Profile::update( $profile );
		}

		$error = (string) get_option( 'google_profil_last_error', '' );
		if ( '' !== $error ) {
			Profile::log_error( $error );
		}

		// Darstellung der Snippets übernehmen: Datum immer als TT.MM.JJJJ.
		$settings = [ 'date_format' => 'd.m.Y' ];
		// Konstanten existieren nur, solange die Snippets noch aktiv sind.
		if ( defined( 'KF_GOOGLE_PLACE_ID' ) && '' === (string) Settings::get( 'place_id' ) ) {
			$settings['place_id'] = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) constant( 'KF_GOOGLE_PLACE_ID' ) );
		}
		if ( defined( 'KF_GOOGLE_FIRMA' ) && '' === (string) Settings::get( 'company_name' ) ) {
			$settings['company_name'] = sanitize_text_field( (string) constant( 'KF_GOOGLE_FIRMA' ) );
		}
		Settings::update( $settings );
	}

	/**
	 * Ob noch alte Daten vorhanden sind.
	 */
	public static function has_legacy_data(): bool {
		foreach ( self::LEGACY_OPTIONS as $option ) {
			if ( false !== get_option( $option, false ) ) {
				return true;
			}
		}
		$upload = wp_upload_dir( null, false );
		return false !== get_transient( self::LEGACY_TRANSIENT ) || is_dir( trailingslashit( $upload['basedir'] ) . self::LEGACY_DIR );
	}

	/**
	 * Ob die alten Snippets noch aktiv sind.
	 */
	public static function snippets_active(): bool {
		return function_exists( 'google_reviews_sammler_cron' ) || function_exists( 'kf_google_reviews_link' ) || function_exists( 'kf_google_rezensionen_admin_seite' );
	}

	/**
	 * Meldet den alten Cron ab, sobald die Migration gelaufen ist.
	 * Solange die Snippets aktiv sind, melden sie ihn wieder an; dann warnt legacy_notice().
	 */
	public static function unschedule_legacy_cron(): void {
		// Solange die Snippets aktiv sind, planen sie bei jedem init neu; dann nicht bei jedem Aufruf löschen.
		if ( get_option( OPTION_MIGRATED, false ) && ! self::snippets_active() && wp_next_scheduled( self::LEGACY_CRON ) ) {
			wp_clear_scheduled_hook( self::LEGACY_CRON );
		}
	}

	/**
	 * Rote Meldung im ganzen Backend, solange die Snippets aktiv sind.
	 */
	public static function legacy_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::snippets_active() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Local Reviews:</strong> ';
		echo esc_html__( 'Die alten Fluent-Snippets für Google-Rezensionen sind noch aktiv. Bitte in Fluent Snippets deaktivieren (Hauptsnippet, Badge, Button, Backend). Sonst registrieren beide dieselben Shortcodes.', 'local-reviews' );
		echo '</p></div>';
	}

	/**
	 * Löscht die alten Optionen, den Transient und den alten Foto-Ordner.
	 */
	public static function delete_legacy_data(): void {
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( self::LEGACY_TRANSIENT );
		wp_clear_scheduled_hook( self::LEGACY_CRON );

		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . self::LEGACY_DIR;
		if ( is_dir( $dir ) ) {
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( WP_Filesystem() && $wp_filesystem ) {
				$wp_filesystem->delete( $dir, true );
			}
		}
	}
}

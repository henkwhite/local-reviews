<?php
/**
 * Seiten-Cache leeren, wenn sich sichtbare Daten geändert haben.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Vergleicht den Stand vor und nach einer Änderung und leert nur bei Bedarf.
 */
final class Cache {

	/**
	 * Fingerabdruck aller sichtbaren Daten: Note, Anzahl, Foto, sichtbare Einträge.
	 */
	public static function snapshot(): string {
		$profile = Profile::get();
		return md5( $profile['rating'] . '|' . $profile['count'] . '|' . $profile['name'] . '|' . $profile['photo_url'] . '|' . Store::signature() );
	}

	/**
	 * Leert den Cache, wenn sich der Fingerabdruck seit $before geändert hat.
	 *
	 * @param string $before Ergebnis von snapshot() vor der Änderung.
	 * @return bool Ob geleert wurde.
	 */
	public static function purge_if_changed( string $before ): bool {
		if ( self::snapshot() === $before ) {
			return false;
		}
		self::purge();
		return true;
	}

	/**
	 * Leert den Seiten-Cache bekannter Plugins und feuert die Hooks.
	 *
	 * Ein Fehler beim Leeren bricht nichts ab, er landet unter „Letzter Fehler“.
	 */
	public static function purge(): void {
		try {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain(); // WP Rocket und AccelerateWP.
			}
			if ( has_action( 'litespeed_purge_all' ) ) {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook von LiteSpeed Cache.
			}
		} catch ( \Throwable $e ) {
			Profile::log_error( __( 'Cache konnte nicht geleert werden:', 'local-reviews' ) . ' ' . $e->getMessage() );
		}

		/**
		 * Feuert, nachdem sich angezeigte Bewertungsdaten geändert haben.
		 * Für eigene Cache-Lösungen oder Benachrichtigungen.
		 */
		do_action( 'local_reviews_updated' );

		/**
		 * Veraltet, nur zur Kompatibilität mit den alten Snippets. Nutze local_reviews_updated.
		 */
		do_action( 'kf_google_reviews_updated' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook der alten Snippets, bewusst beibehalten.
	}

	/**
	 * Ob ein unterstütztes Cache-Plugin erkannt wurde, für die Statusseite.
	 */
	public static function detected(): string {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			return 'rocket_clean_domain';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			return 'litespeed_purge_all';
		}
		return '';
	}
}

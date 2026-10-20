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
	 * Unterstützte Cache-Plugins: Name => [ Funktion zum Leeren, Argumente ].
	 *
	 * Die Liste entspricht dem alten Snippet. Angesprochen wird nur, was aufrufbar ist.
	 * LiteSpeed Cache läuft über seinen Hook, siehe purge().
	 *
	 * @return array<string, array{0: string|array{0: string, 1: string}, 1: array<int, mixed>}>
	 */
	private static function purgers(): array {
		return [
			'WP Rocket / AccelerateWP' => [ 'rocket_clean_domain', [] ],
			'W3 Total Cache'           => [ 'w3tc_flush_all', [] ],
			'WP Super Cache'           => [ 'wp_cache_clear_cache', [] ],
			'WP Fastest Cache'         => [ 'wpfc_clear_all_cache', [ true ] ],
			'Cache Enabler'            => [ [ 'Cache_Enabler', 'clear_complete_cache' ], [] ],
			'SiteGround Optimizer'     => [ 'sg_cachepress_purge_cache', [] ],
			'Breeze'                   => [ 'breeze_clear_all_cache', [] ],
		];
	}

	/**
	 * Leert den Seiten-Cache aller erkannten Plugins und feuert die Hooks.
	 *
	 * Jedes Plugin einzeln: Ein Fehler bei einem bricht die anderen nicht ab,
	 * er landet unter „Letzter Fehler“.
	 */
	public static function purge(): void {
		foreach ( self::purgers() as $name => [ $callback, $args ] ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			try {
				call_user_func_array( $callback, $args );
			} catch ( \Throwable $e ) {
				/* translators: %s: Name des Cache-Plugins. */
				Profile::log_error( sprintf( __( 'Cache von %s konnte nicht geleert werden:', 'local-reviews' ), $name ) . ' ' . $e->getMessage() );
			}
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			try {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook von LiteSpeed Cache.
			} catch ( \Throwable $e ) {
				/* translators: %s: Name des Cache-Plugins. */
				Profile::log_error( sprintf( __( 'Cache von %s konnte nicht geleert werden:', 'local-reviews' ), 'LiteSpeed Cache' ) . ' ' . $e->getMessage() );
			}
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
	 * Namen der erkannten Cache-Plugins, für die Statusseite. Leer, wenn keins erkannt wurde.
	 */
	public static function detected(): string {
		$found = [];
		foreach ( self::purgers() as $name => [ $callback ] ) {
			if ( is_callable( $callback ) ) {
				$found[] = $name;
			}
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		return implode( ', ', $found );
	}

	/**
	 * Funktionen, die zusätzlich minifizierte bzw. zusammengefasste CSS- und JS-Dateien
	 * verwerfen. Für Plugins, deren „alles leeren“ diese Dateien nicht einschließt.
	 *
	 * @return array<string, string|array{0: string, 1: string}>
	 */
	private static function asset_purgers(): array {
		return [
			'WP Rocket / AccelerateWP (CSS/JS)' => 'rocket_clean_minify',
			'Autoptimize'                       => [ 'autoptimizeCache', 'clearall' ],
		];
	}

	/**
	 * Nach einem Plugin-Update: Seiten-Cache und minifizierte CSS-/JS-Dateien leeren,
	 * damit Besucher nicht die alte, zwischengespeicherte frontend.css bekommen.
	 *
	 * Hintergrund: AccelerateWP hängt seinen eigenen Zeitstempel an die minifizierte
	 * Kopie und erkennt eine neue Plugin-Version deshalb nicht.
	 */
	public static function purge_after_update(): void {
		foreach ( self::asset_purgers() as $name => $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			try {
				call_user_func( $callback );
			} catch ( \Throwable $e ) {
				/* translators: %s: Name des Cache-Plugins. */
				Profile::log_error( sprintf( __( 'Cache von %s konnte nicht geleert werden:', 'local-reviews' ), $name ) . ' ' . $e->getMessage() );
			}
		}
		self::purge();
	}

	/**
	 * Prüft bei jedem Aufruf (eine Option mit Autoload), ob eine neue Version läuft,
	 * und leert dann einmalig alle Caches.
	 */
	public static function maybe_purge_after_update(): void {
		$stored = (string) get_option( OPTION_VERSION, '' );
		if ( VERSION === $stored ) {
			return;
		}
		// Erst merken, dann leeren: verhindert doppelte Läufe bei parallelen Aufrufen.
		// Auch ohne gespeicherte Version leeren: Installationen vor 1.4.2 kennen die Option nicht.
		update_option( OPTION_VERSION, VERSION, true );
		self::purge_after_update();
	}
}

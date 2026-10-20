<?php
/**
 * Das Bewertungsarchiv.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Alle Zugriffe auf die gespeicherten Bewertungen laufen über diese Klasse.
 * Der Rest des Plugins weiß nicht, wo die Daten liegen.
 *
 * Aufbau eines Eintrags siehe normalize().
 */
final class Store {

	/**
	 * Gültige Werte für origin.
	 */
	public const ORIGINS = [ 'api', 'import', 'manual' ];

	/**
	 * Zwischenspeicher für den aktuellen Request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Vorberechnete Vergleichsdaten (normalisierter Text, Tag) für die Dublettensuche.
	 *
	 * @var array<string, array{text: string, name: string, day: int}>|null
	 */
	private static ?array $index = null;

	/**
	 * Alle Einträge, Schlüssel ist die ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( OPTION_ARCHIVE, [] );
			self::$cache = [];
			foreach ( is_array( $raw ) ? $raw : [] as $id => $entry ) {
				if ( is_array( $entry ) ) {
					$entry['id']                 = (string) $id;
					self::$cache[ (string) $id ] = self::normalize( $entry );
				}
			}
		}
		return self::$cache;
	}

	/**
	 * Ein Eintrag oder null.
	 *
	 * @param string $id ID des Eintrags.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Ersetzt das komplette Archiv.
	 *
	 * @param array<string, array<string, mixed>> $entries Einträge, Schlüssel ist die ID.
	 */
	public static function save_all( array $entries ): void {
		// Schutz vor Datenverlust: Ist die Option vorhanden, aber kein Array (z. B. beschädigt),
		// wird nicht überschrieben.
		$stored = get_option( OPTION_ARCHIVE, false );
		if ( false !== $stored && ! is_array( $stored ) ) {
			Profile::log_error( __( 'Das Bewertungsarchiv in der Datenbank ist beschädigt und wurde nicht überschrieben.', 'local-reviews' ) );
			return;
		}
		$clean = [];
		foreach ( $entries as $id => $entry ) {
			$entry['id']           = (string) $id;
			$clean[ (string) $id ] = self::normalize( $entry );
		}
		if ( false === get_option( OPTION_ARCHIVE, false ) ) {
			add_option( OPTION_ARCHIVE, $clean, '', false );
		} else {
			update_option( OPTION_ARCHIVE, $clean, false );
		}
		self::$cache = $clean;
		self::$index = null;
	}

	/**
	 * Ändert das Archiv sicher: Sperre setzen, frisch aus der Datenbank lesen,
	 * Änderung anwenden, speichern, Sperre lösen.
	 *
	 * Verhindert, dass ein langer Abruf (Cron) Änderungen aus dem Backend
	 * mit einem veralteten Stand überschreibt.
	 *
	 * @param callable $change Bekommt das Archiv (ID => Eintrag) und gibt das geänderte zurück.
	 *                         Gibt sie null zurück, wird nichts gespeichert.
	 */
	public static function mutate( callable $change ): void {
		$locked = self::lock();
		self::refresh();
		$result = $change( self::all() );
		if ( is_array( $result ) ) {
			self::save_all( $result );
		}
		if ( $locked ) {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Name der Sperr-Option.
	 */
	private const LOCK = 'local_reviews_lock';

	/**
	 * Setzt die Sperre. Wartet höchstens 10 Sekunden; eine Sperre älter als
	 * 60 Sekunden gilt als verwaist (abgebrochener Request) und wird übernommen.
	 *
	 * @return bool Ob die Sperre gesetzt wurde.
	 */
	private static function lock(): bool {
		for ( $i = 0; $i < 50; $i++ ) {
			if ( add_option( self::LOCK, time(), '', false ) ) {
				return true;
			}
			wp_cache_delete( self::LOCK, 'options' );
			$since = (int) get_option( self::LOCK, 0 );
			if ( $since > 0 && time() - $since > MINUTE_IN_SECONDS ) {
				delete_option( self::LOCK );
				continue;
			}
			usleep( 200000 );
		}
		return false;
	}

	/**
	 * Verwirft alle Zwischenspeicher und liest beim nächsten Zugriff aus der Datenbank.
	 */
	public static function refresh(): void {
		wp_cache_delete( OPTION_ARCHIVE, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		self::flush();
	}

	/**
	 * Legt einen Eintrag an oder ersetzt ihn.
	 *
	 * @param array<string, mixed> $entry Eintrag mit id.
	 */
	public static function put( array $entry ): void {
		$entry = self::normalize( $entry );
		if ( '' === $entry['id'] ) {
			return;
		}
		self::mutate(
			static function ( array $all ) use ( $entry ): array {
				$all[ $entry['id'] ] = $entry;
				return $all;
			}
		);
	}

	/**
	 * Löscht einen Eintrag endgültig. Nur für manuelle und importierte Einträge.
	 *
	 * @param string $id ID des Eintrags.
	 * @return bool Ob gelöscht wurde.
	 */
	public static function delete( string $id ): bool {
		$deleted = false;
		self::mutate(
			static function ( array $all ) use ( $id, &$deleted ): ?array {
				if ( ! isset( $all[ $id ] ) || 'api' === $all[ $id ]['origin'] ) {
					return null;
				}
				unset( $all[ $id ] );
				$deleted = true;
				return $all;
			}
		);
		return $deleted;
	}

	/**
	 * Blendet einen Eintrag aus oder wieder ein. Gilt für alle Herkünfte.
	 *
	 * @param string $id     ID des Eintrags.
	 * @param bool   $hidden Ausblenden ja/nein.
	 */
	public static function set_hidden( string $id, bool $hidden ): bool {
		$found = false;
		self::mutate(
			static function ( array $all ) use ( $id, $hidden, &$found ): ?array {
				if ( ! isset( $all[ $id ] ) ) {
					return null;
				}
				$all[ $id ]['hidden'] = $hidden;
				$found                = true;
				return $all;
			}
		);
		return $found;
	}

	/**
	 * Sucht einen Eintrag über die externe ID (Outscraper, Takeout, Places API).
	 *
	 * @param string $external_id Externe ID.
	 */
	public static function find_by_external_id( string $external_id ): ?string {
		if ( '' === $external_id ) {
			return null;
		}
		foreach ( self::all() as $id => $entry ) {
			if ( $entry['external_id'] === $external_id ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Erster Eintrag, der vermutlich dieselbe Bewertung ist.
	 *
	 * @param array<string, mixed> $candidate Eintrag zum Vergleich.
	 * @param string               $skip_id   ID, die übersprungen wird (der Eintrag selbst).
	 */
	public static function find_possible_duplicate( array $candidate, string $skip_id = '' ): ?string {
		$probe = Text::fingerprint( $candidate );
		foreach ( self::index() as $id => $other ) {
			if ( (string) $id !== $skip_id && Text::fingerprints_match( $probe, $other ) ) {
				return (string) $id;
			}
		}
		return null;
	}

	/**
	 * Vergleichsdaten aller Einträge, einmal pro Request berechnet.
	 *
	 * @return array<string, array{text: string, name: string, day: int}>
	 */
	private static function index(): array {
		if ( null === self::$index ) {
			self::$index = [];
			foreach ( self::all() as $id => $entry ) {
				self::$index[ (string) $id ] = Text::fingerprint( $entry );
			}
		}
		return self::$index;
	}

	/**
	 * Paare möglicher Dubletten unter den sichtbaren Einträgen, für den Hinweis in der Tabelle.
	 *
	 * Ist einer der beiden Einträge ausgeblendet, gilt das Paar als erledigt.
	 *
	 * @return array<string, string[]> ID => IDs der möglichen Dubletten.
	 */
	public static function duplicate_map(): array {
		$index   = self::index();
		$entries = array_values( array_filter( self::all(), static fn( array $entry ): bool => ! $entry['hidden'] ) );
		$map     = [];
		$count   = count( $entries );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $entries[ $i ]['id'];
				$b = $entries[ $j ]['id'];
				if ( Text::fingerprints_match( $index[ $a ], $index[ $b ] ) ) {
					$map[ $a ][] = $b;
					$map[ $b ][] = $a;
				}
			}
		}
		return $map;
	}

	/**
	 * Sichtbare Einträge nach Filtern, sortiert.
	 *
	 * @param array<string, mixed> $args sources (string[]), min_rating (int), hide_empty (bool), sort (string), limit (int).
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = [] ): array {
		$sources    = array_filter( (array) ( $args['sources'] ?? [] ) );
		$min_rating = (int) ( $args['min_rating'] ?? 0 );
		$hide_empty = ! empty( $args['hide_empty'] );
		$sort       = (string) ( $args['sort'] ?? Settings::get( 'sort' ) );
		$limit      = (int) ( $args['limit'] ?? 0 );

		$list = array_filter(
			self::all(),
			static function ( array $entry ) use ( $sources, $min_rating, $hide_empty ): bool {
				if ( $entry['hidden'] ) {
					return false;
				}
				if ( $sources && ! in_array( $entry['source'], $sources, true ) ) {
					return false;
				}
				if ( $entry['rating'] < $min_rating ) {
					return false;
				}
				return ! ( $hide_empty && '' === $entry['text'] );
			}
		);

		$list = array_values( $list );
		usort(
			$list,
			static function ( array $a, array $b ) use ( $sort ): int {
				if ( 'oldest' === $sort ) {
					return $a['timestamp'] <=> $b['timestamp'];
				}
				if ( 'rating' === $sort ) {
					return [ $b['rating'], $b['timestamp'] ] <=> [ $a['rating'], $a['timestamp'] ];
				}
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		return $limit > 0 ? array_slice( $list, 0, $limit ) : $list;
	}

	/**
	 * Zählt Einträge, z. B. für die Statusseite.
	 *
	 * @return array{total: int, google: int, hidden: int}
	 */
	public static function counts(): array {
		$counts = [
			'total'  => 0,
			'google' => 0,
			'hidden' => 0,
		];
		foreach ( self::all() as $entry ) {
			++$counts['total'];
			if ( 'google' === $entry['source'] ) {
				++$counts['google'];
			}
			if ( $entry['hidden'] ) {
				++$counts['hidden'];
			}
		}
		return $counts;
	}

	/**
	 * Prüfsumme der sichtbaren Inhalte, um echte Änderungen zu erkennen.
	 */
	public static function signature(): string {
		$parts = [];
		foreach ( self::all() as $id => $entry ) {
			if ( ! $entry['hidden'] ) {
				$parts[] = $id . '|' . $entry['rating'] . '|' . $entry['timestamp'] . '|' . $entry['source'] . '|' . md5( $entry['name'] . $entry['text'] . $entry['url'] . $entry['source_label'] );
			}
		}
		sort( $parts );
		return md5( implode( "\n", $parts ) );
	}

	/**
	 * Leert den Zwischenspeicher, z. B. nach direktem Schreiben der Option.
	 */
	public static function flush(): void {
		self::$cache = null;
		self::$index = null;
	}

	/**
	 * Bringt einen Eintrag in die vollständige, typsichere Form.
	 *
	 * Alte Einträge der Snippets haben nur name, text, rating, date, timestamp.
	 * Fehlendes source wird google, fehlendes origin wird api.
	 *
	 * @param array<string, mixed> $entry Roher Eintrag.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $entry ): array {
		$source = sanitize_key( (string) ( $entry['source'] ?? 'google' ) );
		$origin = (string) ( $entry['origin'] ?? 'api' );

		$timestamp = (int) ( $entry['timestamp'] ?? 0 );
		if ( $timestamp <= 0 && ! empty( $entry['date'] ) ) {
			$timestamp = Text::parse_date( (string) $entry['date'] );
		}

		return [
			'id'           => (string) ( $entry['id'] ?? '' ),
			'external_id'  => (string) ( $entry['external_id'] ?? '' ),
			'source'       => '' !== $source ? $source : 'google',
			'source_label' => sanitize_text_field( (string) ( $entry['source_label'] ?? '' ) ),
			'origin'       => in_array( $origin, self::ORIGINS, true ) ? $origin : 'api',
			'name'         => sanitize_text_field( (string) ( $entry['name'] ?? '' ) ),
			'rating'       => max( 1, min( 5, (int) ( $entry['rating'] ?? 5 ) ) ),
			// Nur Zeilenenden vereinheitlichen. HTML-Umbrüche wandeln Import, Abruf und Migration einmalig um.
			'text'         => trim( str_replace( [ "\r\n", "\r" ], "\n", (string) ( $entry['text'] ?? '' ) ) ),
			'timestamp'    => max( 0, $timestamp ),
			'url'          => esc_url_raw( (string) ( $entry['url'] ?? '' ), [ 'http', 'https' ] ),
			'hidden'       => ! empty( $entry['hidden'] ),
		];
	}
}

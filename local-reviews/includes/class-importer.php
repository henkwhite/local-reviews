<?php
/**
 * Import aus CSV, Outscraper-JSON und Google-Takeout-JSON.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Ablauf: parse() liest die Datei in einheitliche Zeilen, classify() ordnet
 * jede Zeile ein (neu, vorhanden, mögliche Dublette, ungültig), apply()
 * schreibt nach Bestätigung. Die Datei selbst wird nie gespeichert.
 */
final class Importer {

	/**
	 * Größte erlaubte Datei.
	 */
	public const MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Höchstzahl Zeilen pro Import, damit Vorschau und Dublettensuche im Zeitlimit bleiben.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Spalten der CSV-Datei.
	 */
	public const CSV_COLUMNS = [ 'source', 'name', 'rating', 'date', 'text', 'url' ];

	/**
	 * Erkennt das Format und liest die Zeilen.
	 *
	 * @param string $content  Dateiinhalt.
	 * @param string $filename Ursprünglicher Dateiname (für die Endung).
	 * @return array{format: string, rows: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function parse( string $content, string $filename ): array|\WP_Error {
		$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content ); // UTF-8-BOM entfernen.
		$ext     = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'csv' === $ext ) {
			$rows = self::parse_csv( $content );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			return [
				'format' => 'csv',
				'rows'   => $rows,
			];
		}

		if ( 'json' === $ext ) {
			$data = json_decode( $content, true );
			if ( ! is_array( $data ) ) {
				return new \WP_Error( 'local_reviews_format', __( 'Format nicht erkannt: Die Datei ist kein gültiges JSON.', 'local-reviews' ) );
			}
			$outscraper = self::outscraper_items( $data );
			if ( $outscraper ) {
				return [
					'format' => 'outscraper',
					'rows'   => array_map( [ self::class, 'row_from_outscraper' ], $outscraper ),
				];
			}
			$takeout = self::takeout_items( $data );
			if ( $takeout ) {
				return [
					'format' => 'takeout',
					'rows'   => array_map( [ self::class, 'row_from_takeout' ], $takeout ),
				];
			}
		}

		return new \WP_Error( 'local_reviews_format', __( 'Format nicht erkannt. Erlaubt sind CSV mit den Spalten source,name,rating,date,text,url, ein Outscraper-Export (JSON) oder die reviews.json aus Google Takeout.', 'local-reviews' ) );
	}

	/**
	 * Liest eine CSV-Datei. Trennzeichen Komma oder Semikolon, Kopfzeile Pflicht.
	 *
	 * @param string $content Dateiinhalt.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_csv( string $content ): array|\WP_Error {
		// Excel speichert oft Windows-1252 statt UTF-8.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = (string) mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		$first     = strtok( $content, "\n" );
		$delimiter = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';

		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Speicher-Stream für fgetcsv, keine Datei.
		if ( false === $stream ) {
			return [];
		}
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$header = fgetcsv( $stream, 0, $delimiter, '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return [];
		}
		$header = array_map( static fn( $col ) => strtolower( trim( (string) $col ) ), $header );
		if ( array_diff( [ 'name', 'rating', 'date' ], $header ) ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'local_reviews_format', __( 'Format nicht erkannt: Die CSV-Kopfzeile muss die Spalten source,name,rating,date,text,url enthalten.', 'local-reviews' ) );
		}

		$rows = [];
		$line = 1;
		while ( ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( [ null ] === $cells || [ '' ] === $cells ) {
				continue; // Leerzeile.
			}
			$record = [];
			foreach ( $header as $i => $col ) {
				$record[ $col ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			[ $source, $label ] = Sources::resolve( $record['source'] ?? '' );

			$row         = self::make_row(
				[
					'source'       => $source,
					'source_label' => $label,
					'name'         => $record['name'] ?? '',
					'rating'       => $record['rating'] ?? '',
					'timestamp'    => Text::parse_date( $record['date'] ?? '' ),
					'text'         => $record['text'] ?? '',
					'url'          => $record['url'] ?? '',
				]
			);
			$row['line'] = $line;
			$rows[]      = $row;
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Sucht Outscraper-Einträge. Erkannt am Feld author_title plus review_rating.
	 *
	 * Getestet mit dem Export „Google Maps Reviews“ (flache Liste). Die Varianten
	 * mit verschachtelter Liste oder reviews_data (API-Ausgabe) sind nach
	 * Outscraper-Doku eingebaut, aber nicht mit echter Datei geprüft.
	 *
	 * @param array<mixed> $data Dekodiertes JSON.
	 * @return array<int, array<string, mixed>>
	 */
	private static function outscraper_items( array $data ): array {
		$items = [];
		$walk  = static function ( $node ) use ( &$walk, &$items ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['author_title'], $node['review_rating'] ) ) {
				$items[] = $node;
				return;
			}
			if ( isset( $node['reviews_data'] ) && is_array( $node['reviews_data'] ) ) {
				$walk( $node['reviews_data'] );
				return;
			}
			if ( array_is_list( $node ) ) {
				foreach ( $node as $child ) {
					$walk( $child );
				}
			}
		};
		$walk( $data );
		return $items;
	}

	/**
	 * Sucht Takeout-Einträge. Erkannt an starRating plus reviewer oder comment.
	 *
	 * Nach Fremddoku gebaut (Business-Profile-Review-Objekt), nicht mit echter Datei getestet.
	 *
	 * @param array<mixed> $data Dekodiertes JSON.
	 * @return array<int, array<string, mixed>>
	 */
	private static function takeout_items( array $data ): array {
		$list = isset( $data['reviews'] ) && is_array( $data['reviews'] ) ? $data['reviews'] : $data;
		if ( ! array_is_list( $list ) ) {
			return [];
		}
		return array_values(
			array_filter(
				$list,
				static fn( $item ) => is_array( $item ) && isset( $item['starRating'] ) && ( isset( $item['reviewer'] ) || isset( $item['displayName'] ) || isset( $item['comment'] ) )
			)
		);
	}

	/**
	 * Zeile aus einem Outscraper-Eintrag.
	 *
	 * @param array<string, mixed> $item Outscraper-Eintrag.
	 * @return array<string, mixed>
	 */
	private static function row_from_outscraper( array $item ): array {
		$time = (int) ( $item['review_timestamp'] ?? 0 );
		if ( $time <= 0 ) {
			$time = Text::parse_date( (string) ( $item['review_datetime_utc'] ?? '' ) );
		}
		return self::make_row(
			[
				'source'      => 'google',
				'name'        => (string) ( $item['author_title'] ?? '' ),
				'rating'      => $item['review_rating'] ?? '',
				'timestamp'   => $time,
				'text'        => (string) ( $item['review_text'] ?? '' ),
				'url'         => (string) ( $item['review_link'] ?? '' ),
				'external_id' => (string) ( $item['review_id'] ?? '' ),
			]
		);
	}

	/**
	 * Zeile aus einem Takeout-Eintrag.
	 *
	 * @param array<string, mixed> $item Takeout-Eintrag.
	 * @return array<string, mixed>
	 */
	private static function row_from_takeout( array $item ): array {
		$stars = [
			'ONE'   => 1,
			'TWO'   => 2,
			'THREE' => 3,
			'FOUR'  => 4,
			'FIVE'  => 5,
		];
		$star  = strtoupper( (string) $item['starRating'] );
		return self::make_row(
			[
				'source'      => 'google',
				// Laut Business-Profile-API unter reviewer.displayName; ein Takeout-Parser nennt nur displayName.
				'name'        => (string) ( $item['reviewer']['displayName'] ?? $item['displayName'] ?? '' ),
				'rating'      => $stars[ $star ] ?? ( is_numeric( $item['starRating'] ) ? (int) $item['starRating'] : '' ),
				'timestamp'   => Text::parse_date( (string) ( $item['createTime'] ?? '' ) ),
				'text'        => self::strip_translation( (string) ( $item['comment'] ?? '' ) ),
				'url'         => '',
				'external_id' => (string) ( $item['name'] ?? $item['reviewId'] ?? '' ),
			]
		);
	}

	/**
	 * Entfernt eine Google-Übersetzung und behält den Originaltext.
	 *
	 * Bekannte Formen (ungetestet): „(Translated by Google) Übersetzung (Original) Original“
	 * oder „Original (Translated by Google) Übersetzung“.
	 *
	 * @param string $text Kommentar.
	 */
	public static function strip_translation( string $text ): string {
		$original = strpos( $text, '(Original)' );
		if ( false !== $original ) {
			return trim( substr( $text, $original + strlen( '(Original)' ) ) );
		}
		$translated = strpos( $text, '(Translated by Google)' );
		if ( false !== $translated && $translated > 0 ) {
			return trim( substr( $text, 0, $translated ) );
		}
		return $text;
	}

	/**
	 * Baut eine einheitliche Zeile und prüft Pflichtfelder.
	 *
	 * Die ID entsteht aus dem Rohtext (wie beim alten Outscraper-Import, mit <br>),
	 * zusätzlich wird die ID aus dem bereinigten Text als Kandidat geprüft.
	 *
	 * @param array<string, mixed> $raw Rohwerte.
	 * @return array<string, mixed>
	 */
	private static function make_row( array $raw ): array {
		$name      = trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) );
		$raw_text  = (string) ( $raw['text'] ?? '' );
		$text      = Text::clean( $raw_text );
		$rating    = is_numeric( $raw['rating'] ?? '' ) ? (int) round( (float) $raw['rating'] ) : 0;
		$timestamp = (int) ( $raw['timestamp'] ?? 0 );

		$error = '';
		if ( '' === $name ) {
			$error = __( 'Name fehlt', 'local-reviews' );
		} elseif ( $rating < 1 || $rating > 5 ) {
			$error = __( 'Sterne müssen 1 bis 5 sein', 'local-reviews' );
		} elseif ( $timestamp <= 0 ) {
			$error = __( 'Datum fehlt oder ist nicht lesbar', 'local-reviews' );
		}

		return [
			'id'           => Text::legacy_id( $name, $raw_text ),
			'id_alt'       => Text::legacy_id( $name, $text ),
			'external_id'  => sanitize_text_field( (string) ( $raw['external_id'] ?? '' ) ),
			'source'       => (string) ( $raw['source'] ?? 'other' ),
			'source_label' => (string) ( $raw['source_label'] ?? '' ),
			'name'         => $name,
			'rating'       => $rating,
			'timestamp'    => $timestamp,
			'text'         => $text,
			'url'          => esc_url_raw( (string) ( $raw['url'] ?? '' ), [ 'http', 'https' ] ),
			'error'        => $error,
		];
	}

	/**
	 * Ordnet jede Zeile ein.
	 *
	 * Feld status: new | existing | possible | invalid | repeat (doppelt in der Datei).
	 * Feld target: ID des passenden Eintrags im Archiv.
	 *
	 * @param array<int, array<string, mixed>> $rows Zeilen aus parse().
	 * @return array<int, array<string, mixed>>
	 */
	public static function classify( array $rows ): array {
		$seen = [];
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['target'] = '';
			if ( '' !== $row['error'] ) {
				$rows[ $i ]['status'] = 'invalid';
				continue;
			}
			$file_key = '' !== $row['external_id'] ? 'x:' . $row['external_id'] : 'i:' . $row['id'];
			if ( isset( $seen[ $file_key ] ) ) {
				$rows[ $i ]['status'] = 'repeat';
				continue;
			}
			$seen[ $file_key ] = true;

			$target = Store::find_by_external_id( $row['external_id'] );
			if ( null === $target && null !== Store::get( $row['id'] ) ) {
				$target = $row['id'];
			}
			if ( null === $target && null !== Store::get( $row['id_alt'] ) ) {
				$target = $row['id_alt'];
			}
			if ( null !== $target ) {
				$rows[ $i ]['status'] = 'existing';
				$rows[ $i ]['target'] = $target;
				continue;
			}

			$possible = Store::find_possible_duplicate( $row );
			if ( null !== $possible ) {
				$rows[ $i ]['status'] = 'possible';
				$rows[ $i ]['target'] = $possible;
				continue;
			}
			$rows[ $i ]['status'] = 'new';
		}
		return $rows;
	}

	/**
	 * Schreibt die bestätigten Zeilen.
	 *
	 * Modus import: neue anlegen, vorhandene (außer API-Einträge) bei Text, Sternen
	 * und URL aktualisieren, mögliche Dubletten nur mit Haken anlegen.
	 * Modus assign: nichts anlegen, vorhandene (und angehakte mögliche Dubletten)
	 * als importiert markieren und die externe ID setzen.
	 *
	 * @param array<int, array<string, mixed>> $rows     Zeilen aus classify().
	 * @param string                           $mode     import | assign.
	 * @param int[]                            $selected Indizes angehakter möglicher Dubletten.
	 * @return array{added: int, updated: int, assigned: int, skipped: int}
	 */
	public static function apply( array $rows, string $mode, array $selected ): array {
		$result = [
			'added'    => 0,
			'updated'  => 0,
			'assigned' => 0,
			'skipped'  => 0,
		];
		$before = Cache::snapshot();
		// Gesperrt und auf dem frischen Stand schreiben, siehe Store::mutate().
		Store::mutate(
			static function ( array $all ) use ( $rows, $mode, $selected, &$result ): array {
				foreach ( $rows as $i => $row ) {
					$status  = (string) ( $row['status'] ?? '' );
					$target  = (string) ( $row['target'] ?? '' );
					$checked = in_array( (int) $i, $selected, true );

					if ( 'assign' === $mode ) {
						if ( ( 'existing' === $status || ( 'possible' === $status && $checked ) ) && isset( $all[ $target ] ) ) {
							if ( 'manual' !== $all[ $target ]['origin'] ) {
								$all[ $target ]['origin'] = 'import';
							}
							if ( '' === $all[ $target ]['external_id'] && '' !== $row['external_id'] ) {
								$all[ $target ]['external_id'] = $row['external_id'];
							}
							if ( '' === $all[ $target ]['url'] && '' !== $row['url'] ) {
								$all[ $target ]['url'] = $row['url'];
							}
							++$result['assigned'];
						} else {
							++$result['skipped'];
						}
						continue;
					}

					if ( 'new' === $status || ( 'possible' === $status && $checked ) ) {
						$id = isset( $all[ $row['id'] ] ) ? $row['id_alt'] : $row['id'];
						if ( isset( $all[ $id ] ) ) {
							++$result['skipped'];
							continue;
						}
						$all[ $id ] = self::entry_from_row( $row, $id );
						++$result['added'];
					} elseif ( 'existing' === $status && isset( $all[ $target ] ) && 'api' !== $all[ $target ]['origin'] ) {
						$old                           = $all[ $target ];
						$all[ $target ]['text']        = $row['text'];
						$all[ $target ]['rating']      = $row['rating'];
						$all[ $target ]['url']         = '' !== $row['url'] ? $row['url'] : $old['url'];
						$all[ $target ]['external_id'] = '' !== $old['external_id'] ? $old['external_id'] : $row['external_id'];
						if ( $old !== $all[ $target ] ) {
							++$result['updated'];
						} else {
							++$result['skipped'];
						}
					} else {
						++$result['skipped'];
					}
				}
				return $all;
			}
		);
		Cache::purge_if_changed( $before );
		return $result;
	}

	/**
	 * Archiv-Eintrag aus einer Importzeile.
	 *
	 * @param array<string, mixed> $row Zeile.
	 * @param string               $id  ID.
	 * @return array<string, mixed>
	 */
	private static function entry_from_row( array $row, string $id ): array {
		return Store::normalize(
			[
				'id'           => $id,
				'external_id'  => $row['external_id'],
				'source'       => $row['source'],
				'source_label' => $row['source_label'],
				'origin'       => 'import',
				'name'         => $row['name'],
				'rating'       => $row['rating'],
				'text'         => $row['text'],
				'timestamp'    => $row['timestamp'],
				'url'          => $row['url'],
				'hidden'       => false,
			]
		);
	}
}

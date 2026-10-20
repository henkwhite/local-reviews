<?php
/**
 * Texthilfen: Schlüssel, Normalisierung und Dublettenvergleich.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Reine Funktionen ohne Zugriff auf die Datenbank.
 */
final class Text {

	/**
	 * Zeichen am Textanfang, die für eine mögliche Dublette übereinstimmen müssen.
	 */
	public const DUPLICATE_PREFIX = 30;

	/**
	 * Schlüssel eines Eintrags wie in den alten Snippets: md5( Name . erste 50 Bytes des Texts ).
	 *
	 * Bewusst substr() statt mb_substr(), damit bestehende Schlüssel gleich bleiben.
	 *
	 * @param string $name Name der bewertenden Person.
	 * @param string $text Bewertungstext.
	 */
	public static function legacy_id( string $name, string $text ): string {
		return md5( $name . substr( $text, 0, 50 ) );
	}

	/**
	 * Wandelt HTML-Umbrüche aus Importen in Zeilenumbrüche.
	 *
	 * Weiteres HTML wird nicht entfernt, weil Texte wie „<3“ sonst verloren gingen.
	 * Die Ausgabe escaped ohnehin alles.
	 *
	 * @param string $text Rohtext.
	 */
	public static function clean( string $text ): string {
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		return trim( $text );
	}

	/**
	 * Vergleichsform eines Texts: einheitliche Anführungszeichen, zusammengefasste
	 * Leerzeichen und Umbrüche, Kleinschreibung.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = self::clean( $text );
		$text = str_replace(
			[ '„', '“', '”', '‟', '«', '»', '‚', '‘', '’', '‛', '‹', '›', '´', '`' ],
			[ '"', '"', '"', '"', '"', '"', "'", "'", "'", "'", "'", "'", "'", "'" ],
			$text
		);
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return mb_strtolower( trim( $text ), 'UTF-8' );
	}

	/**
	 * Kalendertag eines Zeitstempels in der Zeitzone der Website, als fortlaufende Tageszahl.
	 *
	 * @param int $timestamp Unix-Zeitstempel.
	 */
	public static function day( int $timestamp ): int {
		$date = wp_date( 'Y-m-d', $timestamp );
		return (int) floor( (int) strtotime( (string) $date . ' 00:00:00 UTC' ) / DAY_IN_SECONDS );
	}

	/**
	 * Ob zwei Einträge vermutlich dieselbe Bewertung sind.
	 *
	 * Bedingung: Datum höchstens einen Kalendertag auseinander und die ersten
	 * 30 Zeichen des normalisierten Texts gleich. Ohne Text muss der Name gleich sein.
	 *
	 * @param array<string, mixed> $a Eintrag A (name, text, timestamp).
	 * @param array<string, mixed> $b Eintrag B (name, text, timestamp).
	 */
	public static function is_possible_duplicate( array $a, array $b ): bool {
		return self::fingerprints_match( self::fingerprint( $a ), self::fingerprint( $b ) );
	}

	/**
	 * Vergleichsdaten eines Eintrags: Textanfang und Name normalisiert, Kalendertag.
	 *
	 * @param array<string, mixed> $entry Eintrag (name, text, timestamp).
	 * @return array{text: string, name: string, day: int}
	 */
	public static function fingerprint( array $entry ): array {
		$timestamp = (int) ( $entry['timestamp'] ?? 0 );
		return [
			'text' => mb_substr( self::normalize( (string) ( $entry['text'] ?? '' ) ), 0, self::DUPLICATE_PREFIX, 'UTF-8' ),
			'name' => self::normalize( (string) ( $entry['name'] ?? '' ) ),
			'day'  => $timestamp > 0 ? self::day( $timestamp ) : 0,
		];
	}

	/**
	 * Vergleicht zwei Fingerabdrücke, siehe is_possible_duplicate().
	 *
	 * @param array{text: string, name: string, day: int} $a Fingerabdruck A.
	 * @param array{text: string, name: string, day: int} $b Fingerabdruck B.
	 */
	public static function fingerprints_match( array $a, array $b ): bool {
		if ( 0 === $a['day'] || 0 === $b['day'] || abs( $a['day'] - $b['day'] ) > 1 ) {
			return false;
		}
		if ( '' === $a['text'] && '' === $b['text'] ) {
			return $a['name'] === $b['name'];
		}
		return '' !== $a['text'] && $a['text'] === $b['text'];
	}

	/**
	 * Kürzt einen Text auf eine Zeichenzahl, an einer Wortgrenze, mit Auslassungszeichen.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Höchstzahl Zeichen, 0 für ungekürzt.
	 */
	public static function excerpt( string $text, int $chars ): string {
		if ( $chars <= 0 || mb_strlen( $text, 'UTF-8' ) <= $chars ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $chars, 'UTF-8' );
		$space = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $space && $space > $chars * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space, 'UTF-8' );
		}
		return rtrim( $cut, " \n,.;:-" ) . '…';
	}

	/**
	 * Liest ein Datum in verbreiteten Formaten und liefert einen Unix-Zeitstempel.
	 *
	 * Akzeptiert Zeitstempel, ISO 8601, JJJJ-MM-TT, TT.MM.JJJJ und MM/TT/JJJJ (Outscraper).
	 * Datumsangaben ohne Uhrzeit gelten als 12:00 Uhr Ortszeit, damit der Kalendertag
	 * in jeder Zeitzone stimmt.
	 *
	 * @param string $raw Datumsangabe.
	 * @return int Zeitstempel oder 0, wenn nicht lesbar.
	 */
	public static function parse_date( string $raw ): int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( preg_match( '/^\d{9,11}$/', $raw ) ) {
			return (int) $raw;
		}
		$tz = wp_timezone();
		// Ungültige Kalenderdaten (z. B. 31.02.) ablehnen statt still umzurechnen.
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:[ T]|$)/', $raw, $m ) && ! checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return 0;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]|$)/', $raw, $m ) && ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 0;
		}
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-n-j H:i', "{$m[3]}-{$m[2]}-{$m[1]} 12:00", $tz );
			return $date ? $date->getTimestamp() : 0;
		}
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})[ T](\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m ) ) {
			// TT.MM.JJJJ HH:MM gilt als Ortszeit der Website.
			$date = \DateTimeImmutable::createFromFormat( '!Y-n-j G:i', "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}", $tz );
			return $date ? $date->getTimestamp() : 0;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-n-j H:i', "{$m[1]}-{$m[2]}-{$m[3]} 12:00", $tz );
			return $date ? $date->getTimestamp() : 0;
		}
		try {
			// ISO 8601 mit Zeitzone, oder Outscraper „MM/DD/YYYY HH:MM:SS“ (UTC).
			$has_zone = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $raw );
			$date     = new \DateTimeImmutable( $raw, $has_zone ? null : new \DateTimeZone( 'UTC' ) );
			return $date->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}
}

<?php
/**
 * Bewertungsplattformen.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Liste der Plattformen und Anzeigenamen.
 */
final class Sources {

	/**
	 * Alle Plattformen als Kürzel => Anzeigename.
	 *
	 * „other“ steht immer am Ende und bekommt pro Eintrag einen eigenen Namen.
	 * Über den Filter local_reviews_sources lassen sich Plattformen ergänzen oder entfernen.
	 *
	 * Achtung: Rein numerische Kürzel wie „11880“ werden von PHP als int-Schlüssel
	 * gespeichert. Beim Vergleichen deshalb immer (string) casten.
	 *
	 * @return array<int|string, string>
	 */
	public static function all(): array {
		$sources = [
			'google'       => 'Google',
			'11880'        => '11880',
			'golocal'      => 'GoLocal',
			'facebook'     => 'Facebook',
			'provenexpert' => 'ProvenExpert',
			'myhammer'     => 'MyHammer',
			'houzz'        => 'Houzz',
			'trustpilot'   => 'Trustpilot',
		];

		/**
		 * Filtert die wählbaren Bewertungsplattformen.
		 *
		 * Ergänzen mit $sources['kuerzel'] = 'Name' oder $sources + [ … ], nicht mit
		 * array_merge(): Das nummeriert den Schlüssel 11880 um, die Einträge würden „Sonstige“.
		 *
		 * @param array<int|string, string> $sources Kürzel => Anzeigename.
		 */
		$sources = (array) apply_filters( 'local_reviews_sources', $sources );

		$clean = [];
		foreach ( $sources as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && 'other' !== $slug ) {
				$clean[ $slug ] = (string) $label;
			}
		}
		// Google ist Pflicht, weil der Abruf diese Quelle schreibt.
		if ( ! isset( $clean['google'] ) ) {
			$clean = [ 'google' => 'Google' ] + $clean;
		}
		$clean['other'] = __( 'Sonstige', 'local-reviews' );
		return $clean;
	}

	/**
	 * Ob ein Kürzel bekannt ist.
	 *
	 * @param string $slug Plattform-Kürzel.
	 */
	public static function exists( string $slug ): bool {
		return isset( self::all()[ $slug ] );
	}

	/**
	 * Anzeigename eines Eintrags. Bei „other“ zählt der eigene Name des Eintrags.
	 *
	 * @param string $slug  Plattform-Kürzel.
	 * @param string $label Freitext bei „other“.
	 */
	public static function label( string $slug, string $label = '' ): string {
		if ( 'other' === $slug || ! self::exists( $slug ) ) {
			return '' !== $label ? $label : __( 'Sonstige', 'local-reviews' );
		}
		return self::all()[ $slug ];
	}

	/**
	 * Wandelt einen beliebigen Plattformnamen aus einem Import in Kürzel und Freitext um.
	 *
	 * @param string $raw Name oder Kürzel, z. B. „11880“, „Google“, „Werkenntdenbesten“.
	 * @return array{0: string, 1: string} Kürzel und Freitext.
	 */
	public static function resolve( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [ 'other', '' ];
		}
		$key = sanitize_key( $raw );
		foreach ( self::all() as $slug => $label ) {
			$slug = (string) $slug;
			if ( $slug === $key || sanitize_key( $label ) === $key ) {
				return 'other' === $slug ? [ 'other', '' ] : [ $slug, '' ];
			}
		}
		return [ 'other', sanitize_text_field( $raw ) ];
	}
}

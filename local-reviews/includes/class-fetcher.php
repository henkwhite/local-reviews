<?php
/**
 * Abruf über die Places API (New).
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Holt Note, Anzahl, Name, Links, Foto und die (höchstens fünf) Rezensionen,
 * die Google liefert, und ergänzt das Archiv.
 */
final class Fetcher {

	/**
	 * Felder der Anfrage. reviews liefert höchstens 5 Einträge, nach Relevanz sortiert.
	 */
	private const FIELD_MASK = 'displayName,rating,userRatingCount,reviews,photos,googleMapsLinks';

	/**
	 * Führt einen Abruf aus.
	 *
	 * @param bool $force Foto in jedem Fall neu laden.
	 * @return true|\WP_Error
	 */
	public static function run( bool $force = false ): bool|\WP_Error {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error( 'local_reviews_not_configured', __( 'API-Key oder Place ID fehlen.', 'local-reviews' ) );
		}

		Profile::update_status( [ 'last_attempt' => time() ] );
		$before = Cache::snapshot();

		$data = self::request();
		if ( is_wp_error( $data ) ) {
			Profile::log_error( $data->get_error_message() );
			return $data;
		}

		self::update_profile( $data );

		// Foto nur, wenn Google als Quelle gewählt ist. Ein Fotofehler bricht den Abruf nicht ab.
		$photo_error = null;
		if ( 'google' === Settings::get( 'photo_source' ) && ! empty( $data['photos'][0]['name'] ) && Photo::needs_refresh( $force ) ) {
			$photo = Photo::fetch( (string) $data['photos'][0]['name'] );
			if ( is_wp_error( $photo ) ) {
				$photo_error = $photo;
			} else {
				Profile::update(
					[
						'photo_url'  => esc_url_raw( $photo ),
						'photo_time' => time(),
					]
				);
			}
		}

		if ( ! empty( $data['reviews'] ) && is_array( $data['reviews'] ) ) {
			self::merge_reviews( $data['reviews'] );
		}

		Profile::update_status(
			[
				'last_success' => time(),
				'last_error'   => '',
			]
		);
		if ( $photo_error ) {
			Profile::log_error( $photo_error->get_error_message() );
		}

		Cache::purge_if_changed( $before );
		return true;
	}

	/**
	 * Fragt die Places API ab.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request(): array|\WP_Error {
		$url = add_query_arg(
			'languageCode',
			rawurlencode( Settings::language() ),
			'https://places.googleapis.com/v1/places/' . rawurlencode( (string) Settings::get( 'place_id' ) )
		);

		$res = wp_remote_get(
			$url,
			[
				// Key im Header statt in der URL, damit er in keinem Log landet.
				'headers'     => [
					'X-Goog-Api-Key'   => Settings::api_key(),
					'X-Goog-FieldMask' => self::FIELD_MASK,
				],
				'timeout'     => 15,
				'redirection' => 0, // Den Key nie an ein Weiterleitungsziel schicken.
			]
		);

		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'local_reviews_http', 'WP-Fehler: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			$json    = json_decode( $body, true );
			$message = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : substr( $body, 0, 300 );
			return new \WP_Error( 'local_reviews_http', 'HTTP ' . $code . ': ' . $message );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'local_reviews_json', __( 'Antwort von Google war kein gültiges JSON.', 'local-reviews' ) );
		}
		return $data;
	}

	/**
	 * Speichert Note, Anzahl, Name und Links.
	 *
	 * @param array<string, mixed> $data Antwort der Places API.
	 */
	private static function update_profile( array $data ): void {
		$values = [];
		if ( isset( $data['rating'], $data['userRatingCount'] ) ) {
			$values['rating'] = round( (float) $data['rating'], 1 );
			$values['count']  = (int) $data['userRatingCount'];
		}
		if ( ! empty( $data['displayName']['text'] ) ) {
			$values['name'] = sanitize_text_field( (string) $data['displayName']['text'] );
		}
		if ( ! empty( $data['googleMapsLinks']['reviewsUri'] ) ) {
			$values['reviews_uri'] = esc_url_raw( (string) $data['googleMapsLinks']['reviewsUri'] );
		}
		if ( ! empty( $data['googleMapsLinks']['writeAReviewUri'] ) ) {
			$values['write_uri'] = esc_url_raw( (string) $data['googleMapsLinks']['writeAReviewUri'] );
		}
		if ( $values ) {
			Profile::update( $values );
		}
	}

	/**
	 * Ergänzt das Archiv um neue Rezensionen aus der API.
	 *
	 * Bestehende Einträge werden nie verändert (auch hidden bleibt).
	 * Ist eine Rezension schon als mögliche Dublette mit gleichem Namen vorhanden
	 * (z. B. aus einem Import mit anderen Anführungszeichen), wird sie nicht erneut angelegt.
	 * Geschrieben wird einmal, gesperrt und auf dem frischen Stand der Datenbank.
	 *
	 * @param array<int, mixed> $reviews Rezensionen aus der API.
	 * @return int Anzahl neu angelegter Einträge.
	 */
	public static function merge_reviews( array $reviews ): int {
		$added = 0;
		Store::mutate(
			static function ( array $all ) use ( $reviews, &$added ): ?array {
				foreach ( $reviews as $api ) {
					if ( ! is_array( $api ) ) {
						continue;
					}
					$name = (string) ( $api['authorAttribution']['displayName'] ?? '' );
					$name = '' !== $name ? $name : __( 'Anonym', 'local-reviews' );
					$text = (string) ( $api['text']['text'] ?? '' );
					$time = ! empty( $api['publishTime'] ) ? Text::parse_date( (string) $api['publishTime'] ) : time();

					// Gleiche ID-Logik wie in den alten Snippets.
					$id          = Text::legacy_id( $name, $text );
					$external_id = (string) ( $api['name'] ?? '' );
					if ( isset( $all[ $id ] ) || null !== Store::find_by_external_id( $external_id ) ) {
						continue;
					}

					$entry = Store::normalize(
						[
							'id'          => $id,
							'external_id' => $external_id,
							'source'      => 'google',
							'origin'      => 'api',
							'name'        => $name,
							'rating'      => (int) ( $api['rating'] ?? 5 ),
							'text'        => Text::clean( $text ),
							'timestamp'   => $time,
							'url'         => (string) ( $api['googleMapsUri'] ?? '' ),
						]
					);

					$duplicate = Store::find_possible_duplicate( $entry );
					if ( null !== $duplicate && isset( $all[ $duplicate ] ) && Text::normalize( $all[ $duplicate ]['name'] ) === Text::normalize( $name ) ) {
						continue;
					}

					$all[ $id ] = $entry;
					++$added;
				}
				return $added > 0 ? $all : null;
			}
		);
		return $added;
	}
}

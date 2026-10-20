<?php
/**
 * Öffentliche Funktionen für Themes und andere Plugins.
 *
 * @package LocalReviews
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'local_reviews_get_summary' ) ) {
	/**
	 * Note und Anzahl der Bewertungen laut Google, z. B. für Schema-Markup in SEOPress.
	 *
	 * Beispiel: $summary = local_reviews_get_summary(); // [ 'rating' => 5.0, 'count' => 83 ]
	 *
	 * @return array{rating: float, count: int}
	 */
	function local_reviews_get_summary(): array {
		return LocalReviews\Profile::summary();
	}
}

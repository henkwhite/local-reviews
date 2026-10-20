<?php
/**
 * Serverseitige Ausgabe des Blocks local-reviews/button.
 *
 * @package LocalReviews
 *
 * @var array<string, mixed> $attributes Block-Attribute.
 */

defined( 'ABSPATH' ) || exit;

// Im Block heißen style und class anders, weil „style“ ein Core-Attribut der Block-Supports ist.
$local_reviews_args          = $attributes;
$local_reviews_args['style'] = (string) ( $attributes['button_style'] ?? 'google' );
$local_reviews_args['class'] = (string) ( $attributes['button_class'] ?? '' );
$local_reviews_html          = LocalReviews\Render::button( $local_reviews_args );
if ( '' !== $local_reviews_html ) {
	// Der HTML-Anker steht bei dynamischen Blöcken nur in den Attributen, nicht im gespeicherten HTML.
	$local_reviews_extra = ! empty( $attributes['anchor'] ) ? [ 'id' => sanitize_html_class( (string) $attributes['anchor'] ) ] : [];
	echo '<div ' . get_block_wrapper_attributes( $local_reviews_extra ) . '>' . $local_reviews_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML wird in der Render-Klasse escaped.
}

<?php
/**
 * Frontend-Ausgabe für Shortcodes und Blöcke.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Eine gemeinsame Render-Klasse für Bewertungen, Badge und Button.
 * Blöcke und Shortcodes liefern identisches HTML.
 */
final class Render {

	/**
	 * Handle des Frontend-Stylesheets.
	 */
	public const STYLE = 'local-reviews-frontend';

	/**
	 * Google-„G“ als SVG (18 × 18), wie im alten Button-Snippet.
	 */
	private const G_ICON = '<svg class="lr-button__icon" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/></svg>';

	/**
	 * Platzhalter-Foto (graue Person), identisch mit dem alten Snippet.
	 */
	private const PERSON_ICON = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 24 24" fill="%23ccc"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_early' ] );
	}

	/**
	 * Shortcodes, Stylesheet und Blöcke registrieren.
	 */
	public static function register(): void {
		self::register_style();
		add_shortcode( 'google_reviews_lokal', [ self::class, 'shortcode_reviews' ] );
		add_shortcode( 'google_badge_einzeln', [ self::class, 'shortcode_badge' ] );
		add_shortcode( 'google_reviews_button', [ self::class, 'shortcode_button' ] );

		foreach ( [ 'reviews', 'badge', 'button' ] as $block ) {
			register_block_type( PLUGIN_DIR . 'block/' . $block );
		}
	}

	/**
	 * Registriert das Stylesheet (geladen wird es erst bei Bedarf).
	 */
	public static function register_style(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, PLUGIN_URL . 'assets/frontend.css', [], VERSION );
		}
	}

	/**
	 * Lädt das Stylesheet schon im <head>, wenn der aktuelle Beitrag einen der
	 * Shortcodes oder Blöcke enthält. Sonst würde es erst beim Rendern im Footer
	 * landen und kurz ungestylter Inhalt aufblitzen. Ausgaben in Theme-Elementen
	 * oder Overlays laden das CSS weiterhin beim Rendern nach.
	 */
	public static function enqueue_early(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post ) {
			return;
		}
		$content = (string) $post->post_content;
		foreach ( [ 'google_reviews_lokal', 'google_badge_einzeln', 'google_reviews_button' ] as $tag ) {
			if ( has_shortcode( $content, $tag ) ) {
				self::enqueue_style();
				return;
			}
		}
		if ( str_contains( $content, '<!-- wp:local-reviews/' ) ) {
			self::enqueue_style();
		}
	}

	/**
	 * Lädt das Stylesheet, sofern in den Einstellungen nicht abgeschaltet,
	 * und setzt die Sternfarbe als CSS-Variable.
	 */
	private static function enqueue_style(): void {
		if ( ! Settings::get( 'load_css' ) ) {
			return;
		}
		self::register_style();
		if ( ! wp_style_is( self::STYLE, 'enqueued' ) ) {
			wp_enqueue_style( self::STYLE );
			$color = sanitize_hex_color( (string) Settings::get( 'star_color' ) );
			if ( $color && '#fbbc05' !== strtolower( $color ) ) {
				wp_add_inline_style( self::STYLE, ':root{--lr-star-color:' . $color . ';}' );
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shortcodes
	 * ---------------------------------------------------------------------
	 */

	/**
	 * [google_reviews_lokal] – Kopfbereich und Bewertungsgrid.
	 *
	 * @param array<string, string>|string $atts Attribute.
	 */
	public static function shortcode_reviews( $atts ): string {
		$atts = shortcode_atts( self::reviews_defaults(), is_array( $atts ) ? $atts : [], 'google_reviews_lokal' );
		return self::reviews( $atts );
	}

	/**
	 * [google_badge_einzeln] – Google-Schriftzug, Sterne, Anzahl.
	 *
	 * @param array<string, string>|string $atts Attribute.
	 */
	public static function shortcode_badge( $atts ): string {
		$atts = shortcode_atts( self::badge_defaults(), is_array( $atts ) ? $atts : [], 'google_badge_einzeln' );
		return self::badge( $atts );
	}

	/**
	 * [google_reviews_button] – Button zu Google.
	 *
	 * @param array<string, string>|string $atts Attribute.
	 */
	public static function shortcode_button( $atts ): string {
		$atts = shortcode_atts( self::button_defaults(), is_array( $atts ) ? $atts : [], 'google_reviews_button' );
		return self::button( $atts );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Standardwerte (identisch für Block und Shortcode)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Standardwerte der Bewertungsausgabe.
	 *
	 * @return array<string, mixed>
	 */
	public static function reviews_defaults(): array {
		return [
			'header'         => true,
			'photo'          => true,
			'limit'          => 0,
			'columns'        => 1,
			'columns_tablet' => 0,  // 0: wie columns.
			'columns_mobile' => 1,
			'card_width'     => 400, // 0: volle Breite.
			'min_rating'     => 0,
			'hide_empty'     => false,
			'excerpt'        => 0,   // 0: ungekürzt, sonst Zeichen.
			'source'         => '',  // Leer: alle, sonst Kürzel mit Komma.
			'show_source'    => '',  // Leer: Einstellung | off | foreign | all.
			'wrapper_class'  => '',
		];
	}

	/**
	 * Standardwerte des Badges.
	 *
	 * @return array<string, mixed>
	 */
	public static function badge_defaults(): array {
		return [
			'brand'         => '',     // Leer/1: Einstellung | 0: kein Logo | google | none | image.
			'logo'          => '',     // Wie brand, Name im Block (brand ist dort ein alter Schalter).
			'rating'        => false,
			'label'         => __( 'Bewertungen', 'local-reviews' ),
			'arrow'         => true,
			'link'          => 'none', // none | google | custom.
			'url'           => '',
			'count'         => '',     // Leer: Einstellung | google | local.
			'wrapper_class' => '',
		];
	}

	/**
	 * Standardwerte des Buttons.
	 *
	 * @return array<string, mixed>
	 */
	public static function button_defaults(): array {
		return [
			'text'          => __( 'Alle Bewertungen auf Google lesen', 'local-reviews' ),
			'target'        => 'reviews', // reviews | write.
			'icon'          => true,
			'align'         => 'center',  // left | center | right.
			'style'         => 'google',  // google | theme | none.
			'class'         => '',
			'wrapper_class' => '',
		];
	}

	/*
	 * ---------------------------------------------------------------------
	 * Ausgaben
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Kopfbereich und Grid.
	 *
	 * @param array<string, mixed> $args Attribute, siehe reviews_defaults().
	 */
	public static function reviews( array $args ): string {
		$args = self::args( $args, self::reviews_defaults() );
		self::enqueue_style();

		$sources = array_filter( array_map( 'sanitize_key', explode( ',', (string) $args['source'] ) ) );
		$reviews = Store::query(
			[
				'sources'    => $sources,
				'min_rating' => absint( $args['min_rating'] ),
				'hide_empty' => self::bool( $args['hide_empty'] ),
				'limit'      => absint( $args['limit'] ),
			]
		);

		$show_source = in_array( $args['show_source'], [ 'off', 'foreign', 'all' ], true ) ? $args['show_source'] : (string) Settings::get( 'show_source' );
		$columns     = max( 1, min( 6, absint( $args['columns'] ) ) );
		$tablet      = absint( $args['columns_tablet'] );
		$mobile      = max( 1, min( 6, absint( $args['columns_mobile'] ) ) );
		$width       = absint( $args['card_width'] );

		$style = sprintf(
			'--lr-columns:%d;--lr-columns-tablet:%d;--lr-columns-mobile:%d;--lr-card-width:%s;',
			$columns,
			$tablet > 0 ? min( 6, $tablet ) : $columns,
			$mobile,
			$width > 0 ? $width . 'px' : 'none'
		);

		$html = '<div class="' . esc_attr( self::classes( 'lr-reviews', (string) $args['wrapper_class'] ) ) . '" style="' . esc_attr( $style ) . '">';

		if ( self::bool( $args['header'] ) ) {
			$html .= self::header( self::bool( $args['photo'] ) );
		}

		if ( ! $reviews ) {
			$html .= '<p class="lr-reviews__empty">' . esc_html( self::empty_message() ) . '</p>';
			return $html . '</div>';
		}

		$html .= '<div class="lr-reviews__grid">';
		foreach ( $reviews as $review ) {
			$html .= self::card( $review, absint( $args['excerpt'] ), $show_source );
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Kopfbereich: Foto, Firmenname, Sterne, Note und Anzahl laut Google.
	 *
	 * @param bool $with_photo Foto zeigen.
	 */
	private static function header( bool $with_photo ): string {
		$summary = Profile::summary();
		$name    = Profile::company_name();
		$photo   = $with_photo ? Profile::photo_url() : '';

		// Ohne Firmenname und ohne Daten von Google gibt es nichts zu zeigen.
		if ( '' === $name && $summary['count'] <= 0 ) {
			return '';
		}

		$html = '<div class="lr-header">';
		if ( $with_photo ) {
			if ( '' !== $photo ) {
				$html .= '<img src="' . esc_url( $photo ) . '" class="lr-header__photo" alt="' . esc_attr( $name ) . '" width="75" height="75" decoding="async">';
			} else {
				// Platzhalter wie im alten Snippet: graue Person.
				$html .= '<img src="' . esc_attr( self::PERSON_ICON ) . '" class="lr-header__photo" alt="" width="75" height="75">';
			}
		}
		$html .= '<div class="lr-header__info">';
		if ( '' !== $name ) {
			$html .= '<span class="lr-header__name">' . esc_html( $name ) . '</span>';
		}
		if ( $summary['count'] > 0 ) {
			$html .= '<div class="lr-header__stars lr-stars" role="img" aria-label="' . esc_attr( self::stars_label( $summary['rating'] ) ) . '">' . esc_html( self::stars( (int) round( $summary['rating'] ) ) ) . '</div>';
			$html .= '<span class="lr-header__meta">' . esc_html(
				sprintf(
					/* translators: 1: Note wie 5.0, 2: Anzahl Bewertungen. */
					__( '%1$s | %2$s Google Bewertungen', 'local-reviews' ),
					number_format( $summary['rating'], 1, '.', '' ),
					number_format_i18n( $summary['count'] )
				)
			) . '</span>';
		}
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Eine Bewertungskarte.
	 *
	 * @param array<string, mixed> $review      Eintrag.
	 * @param int                  $excerpt     Kürzen auf Zeichen, 0 für ungekürzt.
	 * @param string               $show_source off | foreign | all.
	 */
	private static function card( array $review, int $excerpt, string $show_source ): string {
		$rating = (int) $review['rating'];
		$text   = Text::excerpt( (string) $review['text'], $excerpt );

		$html  = '<div class="lr-card">';
		$html .= '<div class="lr-card__body">';
		$html .= '<span class="lr-card__name">' . esc_html( (string) $review['name'] ) . '</span>';
		$html .= '<div class="lr-card__stars lr-stars" role="img" aria-label="' . esc_attr( self::stars_label( $rating ) ) . '">' . esc_html( self::stars( $rating ) ) . '</div>';
		// Auch ohne Text ein leerer Absatz, damit die Karte gleich hoch ist wie im alten Snippet.
		$html .= '<p class="lr-card__text">' . nl2br( esc_html( $text ) ) . '</p>';
		$html .= '</div>';

		if ( $review['timestamp'] > 0 ) {
			$html .= '<span class="lr-card__date">' . esc_html( wp_date( Settings::date_format(), (int) $review['timestamp'] ) ) . '</span>';
		}

		$is_foreign = 'google' !== $review['source'];
		if ( 'all' === $show_source || ( 'foreign' === $show_source && $is_foreign ) ) {
			$html .= self::source_label( $review );
		}

		return $html . '</div>';
	}

	/**
	 * Plattform-Label „via 11880“, als Link, wenn eine Original-URL hinterlegt ist.
	 *
	 * @param array<string, mixed> $review Eintrag.
	 */
	private static function source_label( array $review ): string {
		$label   = Sources::label( (string) $review['source'], (string) $review['source_label'] );
		$classes = 'lr-source lr-source--' . sanitize_html_class( (string) $review['source'] );
		/* translators: %s: Name der Plattform, z. B. 11880. */
		$text = sprintf( __( 'via %s', 'local-reviews' ), $label );

		// Direktlink zur Bewertung; bei Google ohne Direktlink ersatzweise die Bewertungsliste.
		$url = (string) $review['url'];
		if ( '' === $url && 'google' === $review['source'] ) {
			$url = Profile::reviews_url();
		}
		if ( '' !== $url ) {
			return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $text ) . '</a>';
		}
		return '<span class="' . esc_attr( $classes ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Badge: „Google ★★★★★ 83 Bewertungen ›“.
	 *
	 * @param array<string, mixed> $args Attribute, siehe badge_defaults().
	 */
	public static function badge( array $args ): string {
		$args = self::args( $args, self::badge_defaults() );
		self::enqueue_style();

		$summary = Profile::summary();
		$parts   = '';

		// Anzahl laut Google oder die der sichtbaren Bewertungen im Plugin.
		$mode = in_array( $args['count'], [ 'google', 'local' ], true ) ? $args['count'] : (string) Settings::get( 'badge_count' );
		if ( 'local' === $mode ) {
			$local            = Profile::local_summary();
			$summary['count'] = $local['count'];
			// Sterne weiter nach Google-Note; ohne Google-Daten der Schnitt im Plugin.
			if ( $summary['rating'] <= 0 ) {
				$summary['rating'] = $local['rating'];
			}
		}

		// Ohne Daten nichts ausgeben, statt „☆☆☆☆☆ 0 Bewertungen“.
		if ( $summary['count'] <= 0 ) {
			return self::is_editor_preview() ? '<p class="lr-notice">' . esc_html__( 'Badge: Noch keine Bewertungen. API-Key und Place ID unter Einstellungen > Local Reviews eintragen oder Bewertungen importieren.', 'local-reviews' ) . '</p>' : '';
		}

		$parts .= self::badge_logo( self::logo_mode( $args ) );
		if ( self::bool( $args['rating'] ) && $summary['count'] > 0 ) {
			$parts .= '<span class="lr-badge__rating">' . esc_html( number_format_i18n( $summary['rating'], 1 ) ) . '</span>';
		}
		$parts .= '<span class="lr-badge__stars lr-stars" role="img" aria-label="' . esc_attr( self::stars_label( $summary['rating'] ) ) . '">' . esc_html( self::stars( (int) round( $summary['rating'] ) ) ) . '</span>';
		$label  = trim( number_format_i18n( $summary['count'] ) . ' ' . (string) $args['label'] );
		$parts .= '<span class="lr-badge__text">' . esc_html( $label ) . '</span>';
		if ( self::bool( $args['arrow'] ) ) {
			$parts .= '<span class="lr-badge__arrow" aria-hidden="true">›</span>';
		}

		$classes = self::classes( 'lr-badge', (string) $args['wrapper_class'] );
		$url     = '';
		if ( 'google' === $args['link'] ) {
			$url = Profile::reviews_url();
		} elseif ( 'custom' === $args['link'] ) {
			$url = (string) $args['url'];
		}

		if ( '' !== $url ) {
			$host     = wp_parse_url( $url, PHP_URL_HOST );
			$external = is_string( $host ) && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host;
			return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '"' . ( $external ? ' target="_blank" rel="noopener nofollow"' : '' ) . '>' . $parts . '</a>';
		}
		// Ohne Link ein <p> wie im alten Snippet, damit es in einem umgebenden Link (z. B. GB-Overlay) gleich aussieht.
		return '<p class="' . esc_attr( $classes ) . '">' . $parts . '</p>';
	}

	/**
	 * Welches Logo der Badge zeigt.
	 *
	 * Reihenfolge: Block-Attribut logo, Shortcode-Attribut brand (google|none|image
	 * oder alt 0/1), sonst die Einstellung. brand=1 bzw. der alte Block-Schalter „an“
	 * bedeuten „wie in den Einstellungen“.
	 *
	 * @param array<string, mixed> $args Attribute des Badges.
	 */
	private static function logo_mode( array $args ): string {
		$modes = [ 'google', 'none', 'image' ];
		foreach ( [ 'logo', 'brand' ] as $key ) {
			$value = is_bool( $args[ $key ] ) ? $args[ $key ] : strtolower( trim( (string) $args[ $key ] ) );
			if ( is_string( $value ) && in_array( $value, $modes, true ) ) {
				return $value;
			}
		}
		if ( '' !== $args['brand'] && ! self::bool( $args['brand'] ) ) {
			return 'none';
		}
		$setting = (string) Settings::get( 'badge_logo' );
		return in_array( $setting, $modes, true ) ? $setting : 'google';
	}

	/**
	 * HTML des Logos im Badge.
	 *
	 * @param string $mode google | none | image.
	 */
	private static function badge_logo( string $mode ): string {
		if ( 'image' === $mode ) {
			$id  = (int) Settings::get( 'badge_logo_id' );
			$src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : false;
			if ( ! $src ) {
				return '';
			}
			$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			return '<img class="lr-badge__logo" src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" decoding="async">';
		}
		if ( 'google' === $mode ) {
			return '<span class="lr-badge__brand" role="img" aria-label="Google"><span class="lr-g-blue" aria-hidden="true">G</span><span class="lr-g-red" aria-hidden="true">o</span><span class="lr-g-yellow" aria-hidden="true">o</span><span class="lr-g-blue" aria-hidden="true">g</span><span class="lr-g-green" aria-hidden="true">l</span><span class="lr-g-red" aria-hidden="true">e</span></span>';
		}
		return '';
	}

	/**
	 * Button zur Bewertungsliste oder zum Bewertungsformular bei Google.
	 *
	 * @param array<string, mixed> $args Attribute, siehe button_defaults().
	 */
	public static function button( array $args ): string {
		$args = self::args( $args, self::button_defaults() );

		$url = 'write' === $args['target'] ? Profile::write_url() : Profile::reviews_url();
		if ( '' === $url ) {
			return self::is_editor_preview() ? '<p class="lr-notice">' . esc_html__( 'Button: Place ID fehlt in Einstellungen > Local Reviews.', 'local-reviews' ) . '</p>' : '';
		}

		$style = in_array( $args['style'], [ 'google', 'theme', 'none' ], true ) ? $args['style'] : 'google';
		if ( 'google' === $style ) {
			self::enqueue_style();
		}
		$align = in_array( $args['align'], [ 'left', 'center', 'right' ], true ) ? $args['align'] : 'center';

		$link_classes = [ 'lr-button' ];
		if ( 'google' === $style ) {
			$link_classes[] = 'lr-button--google';
		} elseif ( 'theme' === $style ) {
			$link_classes[] = 'wp-element-button';
		}
		$link_classes = self::classes( implode( ' ', $link_classes ), (string) $args['class'] );

		$wrapper = self::classes( 'lr-button-wrap lr-button-wrap--' . $align, (string) $args['wrapper_class'] );
		$icon    = self::bool( $args['icon'] ) ? self::G_ICON : '';

		return '<div class="' . esc_attr( $wrapper ) . '" style="text-align:' . esc_attr( $align ) . '">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow" class="' . esc_attr( $link_classes ) . '">'
			. $icon . '<span class="lr-button__text">' . esc_html( (string) $args['text'] ) . '</span>'
			. '</a></div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Hilfen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Füllt Attribute mit Standardwerten auf und ersetzt nicht-skalare Werte
	 * (z. B. Arrays aus manipuliertem Block-Markup) durch den Standardwert.
	 *
	 * @param array<string, mixed> $args     Attribute.
	 * @param array<string, mixed> $defaults Standardwerte.
	 * @return array<string, mixed>
	 */
	private static function args( array $args, array $defaults ): array {
		$args = wp_parse_args( $args, $defaults );
		foreach ( $defaults as $key => $default ) {
			if ( ! is_scalar( $args[ $key ] ) ) {
				$args[ $key ] = $default;
			}
		}
		return $args;
	}

	/**
	 * Sterne als Zeichenkette, z. B. ★★★★☆.
	 *
	 * @param int $rating 0 bis 5.
	 */
	private static function stars( int $rating ): string {
		$rating = max( 0, min( 5, $rating ) );
		return str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
	}

	/**
	 * Vorlesetext der Sterne, z. B. „5,0 von 5 Sternen“.
	 *
	 * @param float $rating Note.
	 */
	private static function stars_label( float $rating ): string {
		/* translators: %s: Note mit einer Nachkommastelle. */
		return sprintf( __( '%s von 5 Sternen', 'local-reviews' ), number_format_i18n( $rating, 1 ) );
	}

	/**
	 * Klassenliste aus Basisklassen und eigenen Klassen.
	 *
	 * @param string $base  Basisklassen.
	 * @param string $extra Eigene Klassen, durch Leerzeichen getrennt.
	 */
	private static function classes( string $base, string $extra ): string {
		$parts = preg_split( '/\s+/', trim( $extra ) );
		$parts = array_filter( array_map( 'sanitize_html_class', is_array( $parts ) ? $parts : [] ) );
		return trim( $base . ' ' . implode( ' ', $parts ) );
	}

	/**
	 * Wahrheitswert aus Shortcode-Attributen („0“, „false“, „nein“) oder Block-Attributen.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ! in_array( strtolower( trim( (string) $value ) ), [ '', '0', 'false', 'no', 'nein', 'off' ], true );
	}

	/**
	 * Hinweis, wenn keine Bewertungen anzuzeigen sind.
	 */
	private static function empty_message(): string {
		if ( self::is_editor_preview() && [] === Store::all() ) {
			return __( 'Noch keine Bewertungen gespeichert. API-Key und Place ID unter Einstellungen > Local Reviews eintragen oder Bewertungen importieren.', 'local-reviews' );
		}
		return __( 'Aktuell sind keine Bewertungen verfügbar.', 'local-reviews' );
	}

	/**
	 * Ob die Ausgabe als Vorschau im Block-Editor läuft.
	 */
	private static function is_editor_preview(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
	}
}

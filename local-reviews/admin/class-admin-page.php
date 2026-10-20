<?php
/**
 * Backend-Seite unter Einstellungen > Local Reviews.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs: Status, Einstellungen, Bewertungen, Import, Anleitung.
 * Alle schreibenden Aktionen laufen über admin-post.php mit Nonce und Rechteprüfung.
 */
final class Admin_Page {

	/**
	 * Slug der Seite.
	 */
	public const SLUG = 'local-reviews';

	/**
	 * Benötigte Berechtigung.
	 */
	public const CAP = 'manage_options';

	/**
	 * Einträge pro Seite in der Bewertungstabelle.
	 */
	private const PER_PAGE = 50;

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), [ self::class, 'action_links' ] );

		foreach ( [ 'settings', 'refresh', 'delete_legacy', 'save_entry', 'entry_action', 'import_upload', 'import_confirm', 'import_cancel' ] as $action ) {
			add_action( 'admin_post_local_reviews_' . $action, [ self::class, 'handle_' . $action ] );
		}
	}

	/**
	 * Menüeintrag.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Local Reviews', 'local-reviews' ),
			__( 'Local Reviews', 'local-reviews' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Link „Einstellungen“ in der Plugin-Liste.
	 *
	 * @param array<int|string, string> $links Vorhandene Links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Einstellungen', 'local-reviews' ) . '</a>' );
		return $links;
	}

	/**
	 * CSS und JS nur auf der eigenen Seite.
	 *
	 * @param string $hook Hook-Suffix der Seite.
	 */
	public static function assets( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'local-reviews-admin', PLUGIN_URL . 'assets/admin.css', [], VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'local-reviews-admin', PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], VERSION, true );
		wp_localize_script(
			'local-reviews-admin',
			'localReviewsAdmin',
			[
				'confirmDelete'       => __( 'Diesen Eintrag endgültig löschen? Das lässt sich nicht rückgängig machen.', 'local-reviews' ),
				'confirmDeleteLegacy' => __( 'Alte Snippet-Daten löschen? Vorher prüfen, ob die Seite mit dem Plugin richtig aussieht.', 'local-reviews' ),
				'mediaTitle'          => __( 'Bild wählen', 'local-reviews' ),
				'mediaButton'         => __( 'Bild verwenden', 'local-reviews' ),
			]
		);
	}

	/**
	 * URL der Seite mit optionalen Parametern.
	 *
	 * @param array<string, string|int> $args Query-Parameter.
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Leitet nach einer Aktion zurück, mit Hinweis-Code.
	 *
	 * @param string                    $tab    Tab.
	 * @param string                    $notice Hinweis-Code, siehe notices().
	 * @param array<string, string|int> $extra  Weitere Parameter.
	 */
	private static function back( string $tab, string $notice, array $extra = [] ): void {
		wp_safe_redirect(
			self::url(
				array_merge(
					[
						'tab'       => $tab,
						'lr_notice' => $notice,
					],
					$extra
				)
			)
		);
		exit;
	}

	/**
	 * Prüft die Berechtigung. Die Nonce prüft jeder Handler direkt danach selbst.
	 */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'local-reviews' ), '', [ 'response' => 403 ] );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Aktionen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Einstellungen und API-Key speichern.
	 */
	public static function handle_settings(): void {
		self::guard();
		check_admin_referer( 'local_reviews_settings' );

		$input = isset( $_POST['local_reviews'] ) && is_array( $_POST['local_reviews'] ) ? wp_unslash( $_POST['local_reviews'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird in Settings::sanitize() bereinigt.
		$old   = Settings::all();
		$new   = Settings::sanitize( $input );
		update_option( OPTION_SETTINGS, $new, true );

		$notice      = 'saved';
		$key_changed = false;
		if ( ! empty( $_POST['local_reviews_clear_key'] ) ) {
			Settings::set_api_key( '' );
			$key_changed = true;
		} else {
			$key = isset( $_POST['local_reviews_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['local_reviews_api_key'] ) ) ) : '';
			if ( '' !== $key ) {
				if ( preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $key ) ) {
					Settings::set_api_key( $key );
					$key_changed = true;
				} else {
					$notice = 'bad_key';
				}
			}
		}

		// Nach geänderter Verbindung sofort abrufen, damit der Erfolg sichtbar ist.
		if ( 'saved' === $notice && Settings::is_configured() && ( $key_changed || $old['place_id'] !== $new['place_id'] || $old['language'] !== $new['language'] ) ) {
			$result = Fetcher::run( true );
			$notice = is_wp_error( $result ) ? 'saved_fetch_failed' : 'saved_fetched';
		} elseif ( 'saved' === $notice ) {
			// Darstellung geändert: Seiten-Cache leeren, damit sie sichtbar wird.
			if ( $old !== $new ) {
				Cache::purge();
			}
		}

		self::back( 'settings', $notice );
	}

	/**
	 * „Jetzt aktualisieren“.
	 */
	public static function handle_refresh(): void {
		self::guard();
		check_admin_referer( 'local_reviews_refresh' );
		$result = Fetcher::run( true );
		self::back( 'status', is_wp_error( $result ) ? 'fetch_failed' : 'fetched' );
	}

	/**
	 * „Altdaten löschen“.
	 */
	public static function handle_delete_legacy(): void {
		self::guard();
		check_admin_referer( 'local_reviews_delete_legacy' );
		if ( ! Migration::legacy_is_copied() ) {
			self::back( 'status', 'legacy_not_copied' );
		}
		Migration::delete_legacy_data();
		self::back( 'status', 'legacy_deleted' );
	}

	/**
	 * Einzelne Bewertung anlegen oder bearbeiten.
	 */
	public static function handle_save_entry(): void {
		self::guard();
		check_admin_referer( 'local_reviews_save_entry' );

		$id       = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$text     = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$rating   = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 5;
		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), [ 'http', 'https' ] ) : '';
		$source   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'other';
		$label    = isset( $_POST['source_label'] ) ? sanitize_text_field( wp_unslash( $_POST['source_label'] ) ) : '';
		$time     = Text::parse_date( $date );
		$existing = '' !== $id ? Store::get( $id ) : null;

		if ( '' === $name || $rating < 1 || $rating > 5 || $time <= 0 || ! Sources::exists( $source ) ) {
			self::back( 'reviews', 'entry_invalid', '' !== $id ? [ 'edit' => $id ] : [] );
		}
		if ( null !== $existing && 'api' === $existing['origin'] ) {
			self::back( 'reviews', 'entry_readonly' );
		}

		$before = Cache::snapshot();
		if ( null === $existing ) {
			$id = Text::legacy_id( $name, $text );
			if ( null !== Store::get( $id ) ) {
				self::back( 'reviews', 'entry_exists' );
			}
		}

		Store::put(
			[
				'id'           => $id,
				'external_id'  => null !== $existing ? $existing['external_id'] : '',
				'source'       => $source,
				'source_label' => 'other' === $source ? $label : '',
				'origin'       => null !== $existing ? $existing['origin'] : 'manual',
				'name'         => $name,
				'rating'       => $rating,
				'text'         => $text,
				'timestamp'    => $time,
				'url'          => $url,
				'hidden'       => null !== $existing && $existing['hidden'],
			]
		);
		Cache::purge_if_changed( $before );

		self::back( 'reviews', null !== $existing ? 'entry_updated' : 'entry_added' );
	}

	/**
	 * Ausblenden, Einblenden, Löschen.
	 */
	public static function handle_entry_action(): void {
		self::guard();
		check_admin_referer( 'local_reviews_entry_action' );

		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$raw  = isset( $_POST['back'] ) && is_array( $_POST['back'] ) ? wp_unslash( $_POST['back'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird unten einzeln bereinigt.
		$back = array_filter(
			[
				'source' => sanitize_key( (string) ( $raw['source'] ?? '' ) ),
				'view'   => sanitize_key( (string) ( $raw['view'] ?? '' ) ),
				'paged'  => absint( $raw['paged'] ?? 0 ),
			]
		);

		$before = Cache::snapshot();
		$notice = 'entry_missing';
		if ( 'hide' === $task || 'show' === $task ) {
			$notice = Store::set_hidden( $id, 'hide' === $task ) ? ( 'hide' === $task ? 'entry_hidden' : 'entry_shown' ) : 'entry_missing';
		} elseif ( 'delete' === $task ) {
			$exists = null !== Store::get( $id );
			$notice = Store::delete( $id ) ? 'entry_deleted' : ( $exists ? 'entry_readonly' : 'entry_missing' );
		}
		Cache::purge_if_changed( $before );

		self::back( 'reviews', $notice, $back );
	}

	/**
	 * Datei hochladen und Vorschau erzeugen. Die Datei wird nicht gespeichert.
	 */
	public static function handle_import_upload(): void {
		self::guard();
		check_admin_referer( 'local_reviews_import_upload' );

		$mode = isset( $_POST['mode'] ) && 'assign' === $_POST['mode'] ? 'assign' : 'import';
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Wird unten einzeln geprüft.

		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::back( 'import', 'upload_failed' );
		}
		if ( (int) $file['size'] > Importer::MAX_BYTES ) {
			self::back( 'import', 'upload_too_big' );
		}
		$name = sanitize_file_name( (string) $file['name'] );
		if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), [ 'csv', 'json' ], true ) ) {
			self::back( 'import', 'upload_type' );
		}

		$content = file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lokale, frisch hochgeladene Datei.
		$parsed  = Importer::parse( false === $content ? '' : $content, $name );
		if ( is_wp_error( $parsed ) ) {
			set_transient( self::import_key() . '_error', $parsed->get_error_message(), 10 * MINUTE_IN_SECONDS );
			self::back( 'import', 'parse_failed' );
		}
		if ( ! $parsed['rows'] ) {
			self::back( 'import', 'no_rows' );
		}
		if ( count( $parsed['rows'] ) > Importer::MAX_ROWS ) {
			self::back( 'import', 'too_many_rows' );
		}

		set_transient(
			self::import_key(),
			[
				'token'  => wp_generate_uuid4(),
				'mode'   => $mode,
				'format' => $parsed['format'],
				'file'   => $name,
				'rows'   => Importer::classify( $parsed['rows'] ),
			],
			HOUR_IN_SECONDS
		);
		self::back( 'import', 'preview' );
	}

	/**
	 * Vorschau bestätigen und schreiben.
	 */
	public static function handle_import_confirm(): void {
		self::guard();
		check_admin_referer( 'local_reviews_import_confirm' );

		$preview = get_transient( self::import_key() );
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		// Das Token verhindert, dass eine Vorschau aus einem anderen Tab bestätigt wird.
		if ( ! is_array( $preview ) || empty( $preview['rows'] ) || ! hash_equals( (string) ( $preview['token'] ?? '' ), $token ) ) {
			self::back( 'import', 'preview_expired' );
		}
		$selected = isset( $_POST['selected'] ) && is_array( $_POST['selected'] ) ? array_map( 'absint', wp_unslash( $_POST['selected'] ) ) : [];

		$result = Importer::apply( (array) $preview['rows'], (string) $preview['mode'], $selected );
		delete_transient( self::import_key() );

		self::back(
			'import',
			'imported',
			[
				'added'    => $result['added'],
				'updated'  => $result['updated'],
				'assigned' => $result['assigned'],
				'skipped'  => $result['skipped'],
			]
		);
	}

	/**
	 * Vorschau verwerfen.
	 */
	public static function handle_import_cancel(): void {
		self::guard();
		check_admin_referer( 'local_reviews_import_cancel' );
		delete_transient( self::import_key() );
		self::back( 'import', 'import_cancelled' );
	}

	/**
	 * Transient-Schlüssel der Import-Vorschau, pro Benutzer.
	 */
	private static function import_key(): string {
		return 'local_reviews_import_' . get_current_user_id();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Ausgabe
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Seite mit Tabs.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Anzeige-Parameter.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
		$tabs = [
			'status'   => __( 'Status', 'local-reviews' ),
			'settings' => __( 'Einstellungen', 'local-reviews' ),
			'reviews'  => __( 'Bewertungen', 'local-reviews' ),
			'import'   => __( 'Import', 'local-reviews' ),
			'guide'    => __( 'Anleitung', 'local-reviews' ),
		];
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'status';
		}

		echo '<div class="wrap local-reviews-admin">';
		echo '<h1>' . esc_html__( 'Local Reviews', 'local-reviews' ) . '</h1>';
		self::render_notice();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( [ 'tab' => $slug ] ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="local-reviews-tab">';
		match ( $tab ) {
			'settings' => self::tab_settings(),
			'reviews'  => self::tab_reviews(),
			'import'   => self::tab_import(),
			'guide'    => self::tab_guide(),
			default    => self::tab_status(),
		};
		echo '</div></div>';
	}

	/**
	 * Hinweis nach einer Aktion.
	 */
	private static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nur Anzeige nach Redirect.
		$code = isset( $_GET['lr_notice'] ) ? sanitize_key( wp_unslash( $_GET['lr_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$error   = Profile::status()['last_error'];
		$success = [
			'saved'            => __( 'Einstellungen gespeichert.', 'local-reviews' ),
			'saved_fetched'    => __( 'Einstellungen gespeichert, Abruf bei Google erfolgreich.', 'local-reviews' ),
			'fetched'          => __( 'Abruf ausgeführt. Die Werte unten sind der neue Stand.', 'local-reviews' ),
			'legacy_deleted'   => __( 'Alte Snippet-Daten gelöscht.', 'local-reviews' ),
			'entry_added'      => __( 'Bewertung angelegt.', 'local-reviews' ),
			'entry_updated'    => __( 'Bewertung gespeichert.', 'local-reviews' ),
			'entry_hidden'     => __( 'Bewertung ausgeblendet.', 'local-reviews' ),
			'entry_shown'      => __( 'Bewertung wieder eingeblendet.', 'local-reviews' ),
			'entry_deleted'    => __( 'Bewertung gelöscht.', 'local-reviews' ),
			'import_cancelled' => __( 'Import abgebrochen, nichts gespeichert.', 'local-reviews' ),
		];
		$errors  = [
			'bad_key'            => __( 'Das sieht nicht nach einem Google-API-Key aus. Der bisherige Key bleibt gespeichert.', 'local-reviews' ),
			'saved_fetch_failed' => __( 'Einstellungen gespeichert, aber der Abruf bei Google ist fehlgeschlagen:', 'local-reviews' ) . ' ' . $error,
			'fetch_failed'       => __( 'Abruf fehlgeschlagen:', 'local-reviews' ) . ' ' . $error,
			'entry_invalid'      => __( 'Bitte Name, Sterne (1–5), Datum und Plattform angeben.', 'local-reviews' ),
			'entry_readonly'     => __( 'Einträge aus dem Google-Abruf lassen sich nicht bearbeiten oder löschen, nur ausblenden.', 'local-reviews' ),
			'entry_exists'       => __( 'Eine Bewertung mit diesem Namen und Textanfang gibt es schon.', 'local-reviews' ),
			'entry_missing'      => __( 'Eintrag nicht gefunden.', 'local-reviews' ),
			'legacy_not_copied'  => __( 'Altdaten nicht gelöscht: Nicht alle alten Bewertungen stehen im Archiv des Plugins.', 'local-reviews' ),
			'upload_failed'      => __( 'Upload fehlgeschlagen. Bitte eine Datei wählen.', 'local-reviews' ),
			'upload_too_big'     => __( 'Datei zu groß (höchstens 5 MB).', 'local-reviews' ),
			'upload_type'        => __( 'Nur .csv- oder .json-Dateien.', 'local-reviews' ),
			'parse_failed'       => (string) get_transient( self::import_key() . '_error' ),
			'no_rows'            => __( 'In der Datei wurden keine Bewertungen gefunden.', 'local-reviews' ),
			'preview_expired'    => __( 'Die Vorschau ist abgelaufen oder wurde in einem anderen Tab ersetzt. Bitte die Datei erneut hochladen.', 'local-reviews' ),
			'too_many_rows'      => sprintf( /* translators: %d: Höchstzahl Zeilen. */ __( 'Die Datei hat mehr als %d Zeilen. Bitte aufteilen.', 'local-reviews' ), Importer::MAX_ROWS ),
		];

		if ( 'imported' === $code ) {
			$message = sprintf(
				/* translators: 1: neu, 2: aktualisiert, 3: zugeordnet, 4: übersprungen. */
				__( 'Import abgeschlossen: %1$d neu, %2$d aktualisiert, %3$d zugeordnet, %4$d übersprungen.', 'local-reviews' ),
				isset( $_GET['added'] ) ? absint( $_GET['added'] ) : 0,
				isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0,
				isset( $_GET['assigned'] ) ? absint( $_GET['assigned'] ) : 0,
				isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( isset( $success[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success[ $code ] ) . '</p></div>';
		} elseif ( isset( $errors[ $code ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $code ] ) . '</p></div>';
			if ( 'parse_failed' === $code ) {
				delete_transient( self::import_key() . '_error' );
			}
		}
		// phpcs:enable
	}

	/**
	 * Tab Status.
	 */
	private static function tab_status(): void {
		$profile = Profile::get();
		$status  = Profile::status();
		$counts  = Store::counts();
		$next    = wp_next_scheduled( CRON_HOOK );
		$photo   = Profile::photo_url();
		$format  = 'd.m.Y H:i';

		if ( ! Settings::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: Link zum Tab Einstellungen. */
				esc_html__( 'Noch kein Abruf möglich: API-Key und Place ID fehlen. %s', 'local-reviews' ),
				'<a href="' . esc_url( self::url( [ 'tab' => 'settings' ] ) ) . '">' . esc_html__( 'Zu den Einstellungen', 'local-reviews' ) . '</a>'
			);
			echo '</p></div>';
		}

		if ( $profile['count'] > $counts['google'] ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: 1: Anzahl bei Google, 2: Google-Einträge im Archiv, 3: Link zur Anleitung. */
				esc_html__( 'Google zählt %1$d Bewertungen, im Archiv sind %2$d Google-Bewertungen. Google liefert über die API nur 5 Bewertungen. Fehlende per Import nachholen: %3$s', 'local-reviews' ),
				(int) $profile['count'],
				(int) $counts['google'],
				'<a href="' . esc_url( self::url( [ 'tab' => 'guide' ] ) . '#import' ) . '">' . esc_html__( 'Anleitung', 'local-reviews' ) . '</a>'
			);
			echo '</p></div>';
		}

		if ( '' !== $status['last_error'] ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Letzter Fehler:', 'local-reviews' ) . '</strong> ' . esc_html( $status['last_error'] );
			if ( $status['last_error_time'] ) {
				echo ' <span class="description">(' . esc_html( wp_date( $format, $status['last_error_time'] ) ) . ')</span>';
			}
			echo '</p></div>';
		}

		$stale = Settings::is_configured() && Profile::is_stale();
		?>
		<table class="widefat striped local-reviews-status">
			<tbody>
				<tr><th><?php esc_html_e( 'Gesamtnote (Google)', 'local-reviews' ); ?></th><td><?php echo $profile['count'] > 0 ? esc_html( number_format_i18n( $profile['rating'], 1 ) ) : '–'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Anzahl Bewertungen (Google)', 'local-reviews' ); ?></th><td><?php echo $profile['count'] > 0 ? esc_html( number_format_i18n( $profile['count'] ) ) : '–'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Bewertungen im Archiv', 'local-reviews' ); ?></th><td>
					<?php
					printf(
						/* translators: 1: gesamt, 2: davon Google, 3: ausgeblendet. */
						esc_html__( '%1$d gesamt, davon %2$d von Google, %3$d ausgeblendet', 'local-reviews' ),
						(int) $counts['total'],
						(int) $counts['google'],
						(int) $counts['hidden']
					);
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Foto', 'local-reviews' ); ?></th><td>
					<?php if ( '' !== $photo ) : ?>
						<img src="<?php echo esc_url( $photo ); ?>" class="local-reviews-thumb" alt="">
						<code><?php echo esc_html( $photo ); ?></code>
					<?php else : ?>
						–
					<?php endif; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Letzter erfolgreicher Abruf', 'local-reviews' ); ?></th><td>
					<?php
					echo $status['last_success'] ? esc_html( wp_date( $format, $status['last_success'] ) ) : esc_html__( 'noch nie', 'local-reviews' );
					if ( $stale ) {
						echo ' <span class="local-reviews-alert">' . esc_html__( '(älter als 36 Stunden – WP-Cron läuft vermutlich nicht zuverlässig, siehe Anleitung „Server-Cron“)', 'local-reviews' ) . '</span>';
					}
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Nächster geplanter Abruf', 'local-reviews' ); ?></th><td>
					<?php
					echo $next ? esc_html( wp_date( $format, $next ) ) : esc_html__( 'nicht geplant', 'local-reviews' );
					if ( $next && $next < time() - HOUR_IN_SECONDS ) {
						echo ' <span class="local-reviews-alert">' . esc_html__( '(überfällig)', 'local-reviews' ) . '</span>';
					}
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Server-Cron', 'local-reviews' ); ?></th><td>
					<?php echo ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? esc_html__( 'ja (DISABLE_WP_CRON gesetzt)', 'local-reviews' ) : esc_html__( 'nein, WP-Cron über Seitenaufrufe', 'local-reviews' ); ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Seiten-Cache leeren', 'local-reviews' ); ?></th><td>
					<?php
					$cache = Cache::detected();
					echo '' !== $cache ? esc_html( sprintf( /* translators: %s: Funktion. */ __( '%s – wird nur bei geänderten Daten geleert', 'local-reviews' ), $cache ) ) : esc_html__( 'kein unterstütztes Cache-Plugin erkannt, Hook local_reviews_updated', 'local-reviews' );
					?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Alte Snippet-Daten', 'local-reviews' ); ?></th><td>
					<?php
					if ( Migration::snippets_active() ) {
						echo '<span class="local-reviews-alert">' . esc_html__( 'Snippets noch aktiv', 'local-reviews' ) . '</span>';
					} elseif ( Migration::has_legacy_data() && ! Migration::legacy_is_copied() ) {
						echo '<span class="local-reviews-alert">' . esc_html__( 'vorhanden, aber noch nicht vollständig übernommen', 'local-reviews' ) . '</span>';
					} elseif ( Migration::has_legacy_data() ) {
						esc_html_e( 'vorhanden (Rückfallebene)', 'local-reviews' );
						if ( '' === Profile::get()['photo_url'] && 'google' === Settings::get( 'photo_source' ) ) {
							echo '<br><span class="local-reviews-alert">' . esc_html__( 'Das Foto kommt noch aus den Altdaten. Erst nach einem erfolgreichen Abruf löschen.', 'local-reviews' ) . '</span>';
						}
					} else {
						esc_html_e( 'keine', 'local-reviews' );
					}
					?>
				</td></tr>
			</tbody>
		</table>

		<div class="local-reviews-actions">
			<?php if ( Settings::is_configured() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="local_reviews_refresh">
					<?php wp_nonce_field( 'local_reviews_refresh' ); ?>
					<?php submit_button( __( 'Jetzt aktualisieren', 'local-reviews' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( Migration::has_legacy_data() && ! Migration::snippets_active() && Migration::legacy_is_copied() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="local-reviews-confirm-legacy">
					<input type="hidden" name="action" value="local_reviews_delete_legacy">
					<?php wp_nonce_field( 'local_reviews_delete_legacy' ); ?>
					<?php submit_button( __( 'Altdaten löschen', 'local-reviews' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tab Einstellungen.
	 */
	private static function tab_settings(): void {
		$s      = Settings::all();
		$masked = Settings::api_key_masked();
		$photo  = $s['photo_id'] ? wp_get_attachment_image_url( (int) $s['photo_id'], 'thumbnail' ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="local_reviews_settings">
			<?php wp_nonce_field( 'local_reviews_settings' ); ?>

			<h2><?php esc_html_e( 'Verbindung zu Google', 'local-reviews' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lr-api-key"><?php esc_html_e( 'API-Key', 'local-reviews' ); ?></label></th>
					<td>
						<input type="password" id="lr-api-key" name="local_reviews_api_key" value="" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( '' !== $masked ? $masked : __( 'nicht gesetzt', 'local-reviews' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Places API (New) muss im Google-Cloud-Projekt aktiviert sein. Der Key darf nicht auf HTTP-Referrer beschränkt sein (Abruf läuft auf dem Server). Feld leer lassen, um den gespeicherten Key zu behalten.', 'local-reviews' ); ?></p>
						<?php if ( '' !== $masked ) : ?>
							<label><input type="checkbox" name="local_reviews_clear_key" value="1"> <?php esc_html_e( 'Gespeicherten Key löschen', 'local-reviews' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-place-id"><?php esc_html_e( 'Place ID', 'local-reviews' ); ?></label></th>
					<td>
						<input type="text" id="lr-place-id" name="local_reviews[place_id]" value="<?php echo esc_attr( $s['place_id'] ); ?>" class="regular-text code" spellcheck="false">
						<p class="description"><?php esc_html_e( 'Beginnt meist mit „ChIJ“. Zu finden mit dem Place ID Finder von Google, siehe Anleitung.', 'local-reviews' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-language"><?php esc_html_e( 'Sprache', 'local-reviews' ); ?></label></th>
					<td>
						<input type="text" id="lr-language" name="local_reviews[language]" value="<?php echo esc_attr( $s['language'] ); ?>" class="small-text" placeholder="<?php echo esc_attr( substr( determine_locale(), 0, 2 ) ); ?>">
						<p class="description"><?php esc_html_e( 'Sprachcode für Google, z. B. de. Leer: Sprache der WordPress-Installation.', 'local-reviews' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Darstellung', 'local-reviews' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lr-company"><?php esc_html_e( 'Firmenname', 'local-reviews' ); ?></label></th>
					<td>
						<input type="text" id="lr-company" name="local_reviews[company_name]" value="<?php echo esc_attr( $s['company_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( Profile::get()['name'] ); ?>">
						<p class="description"><?php esc_html_e( 'Leer: Name aus dem Google-Profil.', 'local-reviews' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Foto im Kopfbereich', 'local-reviews' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="local_reviews[photo_source]" value="google" <?php checked( $s['photo_source'], 'google' ); ?>> <?php esc_html_e( 'Erstes Foto des Google-Profils (wird lokal gespeichert)', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="local_reviews[photo_source]" value="media" <?php checked( $s['photo_source'], 'media' ); ?>> <?php esc_html_e( 'Bild aus der Mediathek', 'local-reviews' ); ?></label>
						</fieldset>
						<div class="local-reviews-media">
							<input type="hidden" id="lr-photo-id" name="local_reviews[photo_id]" value="<?php echo esc_attr( (string) $s['photo_id'] ); ?>">
							<img id="lr-photo-preview" src="<?php echo esc_url( (string) $photo ); ?>" alt="" class="local-reviews-thumb"<?php echo $photo ? '' : ' hidden'; ?>>
							<button type="button" class="button lr-media-select" data-target="lr-photo" data-radio="photo_source"><?php esc_html_e( 'Bild wählen', 'local-reviews' ); ?></button>
							<button type="button" class="button-link lr-media-remove" data-target="lr-photo"<?php echo $photo ? '' : ' hidden'; ?>><?php esc_html_e( 'Entfernen', 'local-reviews' ); ?></button>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-star-color"><?php esc_html_e( 'Sternfarbe', 'local-reviews' ); ?></label></th>
					<td><input type="color" id="lr-star-color" name="local_reviews[star_color]" value="<?php echo esc_attr( $s['star_color'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-date-format"><?php esc_html_e( 'Datumsformat', 'local-reviews' ); ?></label></th>
					<td>
						<input type="text" id="lr-date-format" name="local_reviews[date_format]" value="<?php echo esc_attr( $s['date_format'] ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( (string) get_option( 'date_format' ) ); ?>">
						<p class="description"><?php esc_html_e( 'PHP-Datumsformat, z. B. d.m.Y. Leer: Format aus Einstellungen > Allgemein.', 'local-reviews' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-sort"><?php esc_html_e( 'Sortierung', 'local-reviews' ); ?></label></th>
					<td>
						<select id="lr-sort" name="local_reviews[sort]">
							<option value="newest" <?php selected( $s['sort'], 'newest' ); ?>><?php esc_html_e( 'Neueste zuerst', 'local-reviews' ); ?></option>
							<option value="oldest" <?php selected( $s['sort'], 'oldest' ); ?>><?php esc_html_e( 'Älteste zuerst', 'local-reviews' ); ?></option>
							<option value="rating" <?php selected( $s['sort'], 'rating' ); ?>><?php esc_html_e( 'Beste zuerst', 'local-reviews' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo im Badge', 'local-reviews' ); ?></th>
					<td>
						<?php $local_reviews_logo = $s['badge_logo_id'] ? wp_get_attachment_image_url( (int) $s['badge_logo_id'], 'medium' ) : ''; ?>
						<fieldset>
							<label><input type="radio" name="local_reviews[badge_logo]" value="google" <?php checked( $s['badge_logo'], 'google' ); ?>> <?php esc_html_e( 'Google-Schriftzug', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="local_reviews[badge_logo]" value="none" <?php checked( $s['badge_logo'], 'none' ); ?>> <?php esc_html_e( 'Kein Logo', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="local_reviews[badge_logo]" value="image" <?php checked( $s['badge_logo'], 'image' ); ?>> <?php esc_html_e( 'Eigenes Bild aus der Mediathek (wird auf 20 px Höhe skaliert)', 'local-reviews' ); ?></label>
						</fieldset>
						<div class="local-reviews-media">
							<input type="hidden" id="lr-logo-id" name="local_reviews[badge_logo_id]" value="<?php echo esc_attr( (string) $s['badge_logo_id'] ); ?>">
							<img id="lr-logo-preview" src="<?php echo esc_url( (string) $local_reviews_logo ); ?>" alt="" class="local-reviews-logo"<?php echo $local_reviews_logo ? '' : ' hidden'; ?>>
							<button type="button" class="button lr-media-select" data-target="lr-logo" data-radio="badge_logo"><?php esc_html_e( 'Bild wählen', 'local-reviews' ); ?></button>
							<button type="button" class="button-link lr-media-remove" data-target="lr-logo"<?php echo $local_reviews_logo ? '' : ' hidden'; ?>><?php esc_html_e( 'Entfernen', 'local-reviews' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Pro Badge überschreibbar (Block-Einstellung „Logo“ bzw. brand="google|none|image").', 'local-reviews' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-show-source"><?php esc_html_e( 'Plattform-Hinweis', 'local-reviews' ); ?></label></th>
					<td>
						<select id="lr-show-source" name="local_reviews[show_source]">
							<option value="off" <?php selected( $s['show_source'], 'off' ); ?>><?php esc_html_e( 'Aus', 'local-reviews' ); ?></option>
							<option value="foreign" <?php selected( $s['show_source'], 'foreign' ); ?>><?php esc_html_e( 'Nur bei anderen Plattformen als Google', 'local-reviews' ); ?></option>
							<option value="all" <?php selected( $s['show_source'], 'all' ); ?>><?php esc_html_e( 'Bei allen Bewertungen, auch „via Google“', 'local-reviews' ); ?></option>
						</select>
						<select name="local_reviews[source_position]" aria-label="<?php esc_attr_e( 'Position des Plattform-Hinweises', 'local-reviews' ); ?>">
							<option value="date" <?php selected( $s['source_position'], 'date' ); ?>><?php esc_html_e( 'Hinter dem Datum (14.10.2026 · via Google)', 'local-reviews' ); ?></option>
							<option value="top" <?php selected( $s['source_position'], 'top' ); ?>><?php esc_html_e( 'Oben rechts neben dem Namen', 'local-reviews' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '„via 11880“ usw. an jeder Karte, als Link zur Original-Bewertung. Google-Bewertungen ohne Direktlink verlinken auf die Bewertungsliste bei Google. Pro Block bzw. mit show_source="…" überschreibbar.', 'local-reviews' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Anzahl im Badge', 'local-reviews' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="local_reviews[badge_count]" value="google" <?php checked( $s['badge_count'], 'google' ); ?>> <?php esc_html_e( 'Anzahl laut Google', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="local_reviews[badge_count]" value="local" <?php checked( $s['badge_count'], 'local' ); ?>> <?php esc_html_e( 'Sichtbare Bewertungen im Plugin (alle Plattformen, ohne ausgeblendete)', 'local-reviews' ); ?></label>
						</fieldset>
						<p class="description">
							<?php
							$local_reviews_local = Profile::local_summary();
							printf(
								/* translators: 1: Anzahl laut Google, 2: Anzahl im Plugin. */
								esc_html__( 'Aktuell: %1$d laut Google, %2$d im Plugin. Die Sterne folgen der Google-Note. Pro Badge überschreibbar (Block-Einstellung bzw. count="google|local").', 'local-reviews' ),
								(int) Profile::get()['count'],
								(int) $local_reviews_local['count']
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin-CSS', 'local-reviews' ); ?></th>
					<td>
						<label><input type="checkbox" name="local_reviews[load_css]" value="1" <?php checked( (bool) $s['load_css'] ); ?>> <?php esc_html_e( 'Mitgeliefertes CSS laden', 'local-reviews' ); ?></label>
						<p class="description"><?php esc_html_e( 'Abschalten, wenn das Theme die Klassen lr-* selbst gestaltet.', 'local-reviews' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Links zu Google', 'local-reviews' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Bewertungsliste', 'local-reviews' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="local_reviews[button_mode]" value="auto" <?php checked( $s['button_mode'], 'auto' ); ?>> <?php esc_html_e( 'Automatisch (Direktlink von Google)', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="local_reviews[button_mode]" value="custom" <?php checked( $s['button_mode'], 'custom' ); ?>> <?php esc_html_e( 'Eigene URL:', 'local-reviews' ); ?></label>
							<input type="url" name="local_reviews[button_url]" value="<?php echo esc_attr( $s['button_url'] ); ?>" class="regular-text">
						</fieldset>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: URL. */ __( 'Aktuell: %s', 'local-reviews' ), '' !== Profile::reviews_url() ? Profile::reviews_url() : '–' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lr-write-url"><?php esc_html_e( '„Bewertung schreiben“', 'local-reviews' ); ?></label></th>
					<td>
						<input type="url" id="lr-write-url" name="local_reviews[write_url]" value="<?php echo esc_attr( $s['write_url'] ); ?>" class="regular-text">
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: URL. */ __( 'Leer: automatisch. Aktuell: %s', 'local-reviews' ), '' !== Profile::write_url() ? Profile::write_url() : '–' ) ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Einstellungen speichern', 'local-reviews' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Tab Bewertungen: Formular und Tabelle.
	 */
	private static function tab_reviews(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nur Filter und Anzeige.
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$filter  = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$edit = '' !== $edit_id ? Store::get( $edit_id ) : null;
		if ( null !== $edit && 'api' === $edit['origin'] ) {
			$edit = null;
		}
		self::entry_form( $edit );

		$duplicates = Store::duplicate_map();
		// Für die Tabelle alle Einträge, auch ausgeblendete, neueste zuerst.
		$all = array_values( Store::all() );
		usort( $all, static fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );

		$list  = array_filter(
			$all,
			static function ( array $e ) use ( $filter, $view, $duplicates ): bool {
				if ( '' !== $filter && $e['source'] !== $filter ) {
					return false;
				}
				if ( 'hidden' === $view ) {
					return $e['hidden'];
				}
				if ( 'visible' === $view ) {
					return ! $e['hidden'];
				}
				if ( 'duplicates' === $view ) {
					return isset( $duplicates[ $e['id'] ] );
				}
				return true;
			}
		);
		$total = count( $list );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$list  = array_slice( array_values( $list ), ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$back  = [
			'source' => $filter,
			'view'   => $view,
			'paged'  => $paged,
		];

		echo '<h2 id="liste">' . esc_html__( 'Alle Bewertungen', 'local-reviews' ) . '</h2>';

		if ( $duplicates ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: Link zum Filter. */
				esc_html__( 'Im Archiv gibt es mögliche Dubletten (gleiches Datum, gleicher Textanfang). Einen der Einträge ausblenden oder löschen. %s', 'local-reviews' ),
				'<a href="' . esc_url(
					self::url(
						[
							'tab'  => 'reviews',
							'view' => 'duplicates',
						]
					) . '#liste'
				) . '">' . esc_html__( 'Anzeigen', 'local-reviews' ) . '</a>'
			);
			echo '</p></div>';
		}

		// Filter.
		echo '<form method="get" class="local-reviews-filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="reviews">';
		echo '<select name="source"><option value="">' . esc_html__( 'Alle Plattformen', 'local-reviews' ) . '</option>';
		foreach ( Sources::all() as $slug => $label ) {
			$slug = (string) $slug;
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $filter, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <select name="view">';
		foreach (
			[
				''           => __( 'Alle', 'local-reviews' ),
				'visible'    => __( 'Sichtbar', 'local-reviews' ),
				'hidden'     => __( 'Ausgeblendet', 'local-reviews' ),
				'duplicates' => __( 'Mögliche Dubletten', 'local-reviews' ),
			] as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $view, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filtern', 'local-reviews' ), 'secondary', '', false );
		/* translators: %d: Anzahl Einträge. */
		echo ' <span class="description">' . esc_html( sprintf( _n( '%d Eintrag', '%d Einträge', $total, 'local-reviews' ), $total ) ) . '</span>';
		echo '</form>';

		$origins = [
			'api'    => __( 'Google-Abruf', 'local-reviews' ),
			'import' => __( 'Import', 'local-reviews' ),
			'manual' => __( 'Manuell', 'local-reviews' ),
		];
		?>
		<table class="widefat striped local-reviews-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Datum', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Name', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Sterne', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Text', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Plattform', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Herkunft', 'local-reviews' ); ?></th>
					<th><?php esc_html_e( 'Aktionen', 'local-reviews' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $list ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Keine Einträge.', 'local-reviews' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $list as $e ) : ?>
				<tr class="<?php echo $e['hidden'] ? 'local-reviews-hidden' : ''; ?>">
					<td><?php echo $e['timestamp'] ? esc_html( wp_date( 'd.m.Y', $e['timestamp'] ) ) : '–'; ?></td>
					<td>
						<?php echo esc_html( $e['name'] ); ?>
						<?php if ( $e['hidden'] ) : ?>
							<br><span class="description"><?php esc_html_e( 'ausgeblendet', 'local-reviews' ); ?></span>
						<?php endif; ?>
						<?php if ( isset( $duplicates[ $e['id'] ] ) ) : ?>
							<br><span class="local-reviews-alert">
							<?php
							$names = array_map(
								static fn( $other ) => ( Store::get( $other )['name'] ?? '' ) . ' (' . ( Store::get( $other )['timestamp'] ?? 0 ? wp_date( 'd.m.Y', (int) Store::get( $other )['timestamp'] ) : '–' ) . ')',
								$duplicates[ $e['id'] ]
							);
							/* translators: %s: Name und Datum des anderen Eintrags. */
							echo esc_html( sprintf( __( 'mögliche Dublette von %s', 'local-reviews' ), implode( ', ', $names ) ) );
							?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( str_repeat( '★', $e['rating'] ) ); ?></td>
					<td class="local-reviews-text"><?php echo '' !== $e['text'] ? esc_html( Text::excerpt( $e['text'], 140 ) ) : '<span class="description">' . esc_html__( 'ohne Text', 'local-reviews' ) . '</span>'; ?></td>
					<td>
						<?php
						$label = Sources::label( $e['source'], $e['source_label'] );
						echo '' !== $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>' : esc_html( $label );
						?>
					</td>
					<td><?php echo esc_html( $origins[ $e['origin'] ] ?? $e['origin'] ); ?></td>
					<td class="local-reviews-row-actions">
						<?php if ( 'api' !== $e['origin'] ) : ?>
							<?php $local_reviews_edit_url = self::url( [ 'tab' => 'reviews', 'edit' => $e['id'] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
							<a class="button button-small" href="<?php echo esc_url( $local_reviews_edit_url ); ?>"><?php esc_html_e( 'Bearbeiten', 'local-reviews' ); ?></a>
						<?php endif; ?>
						<?php self::row_action( $e['id'], $e['hidden'] ? 'show' : 'hide', $e['hidden'] ? __( 'Einblenden', 'local-reviews' ) : __( 'Ausblenden', 'local-reviews' ), $back ); ?>
						<?php if ( 'api' !== $e['origin'] ) : ?>
							<?php self::row_action( $e['id'], 'delete', __( 'Löschen', 'local-reviews' ), $back ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					[
						'base'    => add_query_arg(
							'paged',
							'%#%',
							self::url(
								array_filter(
									[
										'tab'    => 'reviews',
										'source' => $filter,
										'view'   => $view,
									]
								)
							)
						),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					]
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Kleines Formular für eine Zeilenaktion.
	 *
	 * @param string               $id    ID des Eintrags.
	 * @param string               $task  hide | show | delete.
	 * @param string               $label Beschriftung.
	 * @param array<string, mixed> $back  Filter für den Rücksprung.
	 */
	private static function row_action( string $id, string $task, string $label, array $back ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo 'delete' === $task ? 'local-reviews-confirm-delete' : ''; ?>">
			<input type="hidden" name="action" value="local_reviews_entry_action">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<?php foreach ( $back as $key => $value ) : ?>
				<input type="hidden" name="back[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( 'local_reviews_entry_action' ); ?>
			<button type="submit" class="button button-small<?php echo 'delete' === $task ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Formular für eine neue oder zu bearbeitende Bewertung.
	 *
	 * @param array<string, mixed>|null $entry Eintrag beim Bearbeiten.
	 */
	private static function entry_form( ?array $entry ): void {
		$is_edit = null !== $entry;
		$e       = $entry ?? Store::normalize(
			[
				'source' => 'other',
				'origin' => 'manual',
				'rating' => 5,
			]
		);
		$date    = $e['timestamp'] ? wp_date( 'Y-m-d', $e['timestamp'] ) : wp_date( 'Y-m-d' );
		?>
		<details class="local-reviews-form-box" <?php echo $is_edit ? 'open' : ''; ?>>
			<summary><h2><?php echo $is_edit ? esc_html__( 'Bewertung bearbeiten', 'local-reviews' ) : esc_html__( 'Bewertung hinzufügen', 'local-reviews' ); ?></h2></summary>
			<p class="description"><?php esc_html_e( 'Für Bewertungen von anderen Plattformen (z. B. 11880) oder um einzelne Google-Bewertungen nachzutragen. Einträge aus dem Google-Abruf lassen sich nicht bearbeiten, nur ausblenden.', 'local-reviews' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="local_reviews_save_entry">
				<input type="hidden" name="id" value="<?php echo esc_attr( $is_edit ? $e['id'] : '' ); ?>">
				<?php wp_nonce_field( 'local_reviews_save_entry' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lr-source"><?php esc_html_e( 'Plattform', 'local-reviews' ); ?></label></th>
						<td>
							<select id="lr-source" name="source">
								<?php foreach ( Sources::all() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $e['source'], (string) $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" id="lr-source-label" name="source_label" value="<?php echo esc_attr( $e['source_label'] ); ?>" placeholder="<?php esc_attr_e( 'Name der Plattform', 'local-reviews' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lr-name"><?php esc_html_e( 'Name', 'local-reviews' ); ?></label></th>
						<td><input type="text" id="lr-name" name="name" value="<?php echo esc_attr( $e['name'] ); ?>" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="lr-rating"><?php esc_html_e( 'Sterne', 'local-reviews' ); ?></label></th>
						<td>
							<select id="lr-rating" name="rating">
								<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( (int) $e['rating'], $i ); ?>><?php echo esc_html( str_repeat( '★', $i ) ); ?></option>
								<?php endfor; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lr-date"><?php esc_html_e( 'Datum', 'local-reviews' ); ?></label></th>
						<td><input type="date" id="lr-date" name="date" value="<?php echo esc_attr( (string) $date ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="lr-text"><?php esc_html_e( 'Text', 'local-reviews' ); ?></label></th>
						<td><textarea id="lr-text" name="text" rows="5" class="large-text"><?php echo esc_textarea( $e['text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="lr-url"><?php esc_html_e( 'Link zur Original-Bewertung', 'local-reviews' ); ?></label></th>
						<td>
							<input type="url" id="lr-url" name="url" value="<?php echo esc_attr( $e['url'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Optional. Dann wird „via Plattform“ im Grid zum Link, damit Besucher die Bewertung prüfen können.', 'local-reviews' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $is_edit ? __( 'Speichern', 'local-reviews' ) : __( 'Bewertung hinzufügen', 'local-reviews' ), 'primary', 'submit', false ); ?>
				<?php if ( $is_edit ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( [ 'tab' => 'reviews' ] ) ); ?>"><?php esc_html_e( 'Abbrechen', 'local-reviews' ); ?></a>
				<?php endif; ?>
			</form>
		</details>
		<?php
	}

	/**
	 * Tab Import: Upload oder Vorschau.
	 */
	private static function tab_import(): void {
		$preview = get_transient( self::import_key() );
		if ( is_array( $preview ) && ! empty( $preview['rows'] ) ) {
			self::import_preview( $preview );
			return;
		}
		?>
		<h2><?php esc_html_e( 'Bewertungen importieren', 'local-reviews' ); ?></h2>
		<p><?php esc_html_e( 'Unterstützte Formate:', 'local-reviews' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Outscraper-Export „Google Maps Reviews“ als JSON (getestet)', 'local-reviews' ); ?></li>
			<li><?php esc_html_e( 'Google Takeout: die Datei reviews.json aus dem Unternehmensprofil-Export, vorher entpacken (nicht mit echter Datei getestet)', 'local-reviews' ); ?></li>
			<li>
				<?php esc_html_e( 'CSV mit Kopfzeile:', 'local-reviews' ); ?>
				<code><?php echo esc_html( implode( ',', Importer::CSV_COLUMNS ) ); ?></code>
				<?php esc_html_e( '(Komma oder Semikolon, Datum als TT.MM.JJJJ oder JJJJ-MM-TT)', 'local-reviews' ); ?>
			</li>
		</ul>
		<p class="description"><?php esc_html_e( 'Vor dem Speichern zeigt das Plugin eine Vorschau. Die Datei selbst wird nicht auf dem Server abgelegt.', 'local-reviews' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="local_reviews_import_upload">
			<?php wp_nonce_field( 'local_reviews_import_upload' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lr-file"><?php esc_html_e( 'Datei', 'local-reviews' ); ?></label></th>
					<td><input type="file" id="lr-file" name="file" accept=".csv,.json" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Modus', 'local-reviews' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="mode" value="import" checked> <?php esc_html_e( 'Importieren: neue Bewertungen anlegen', 'local-reviews' ); ?></label><br>
							<label><input type="radio" name="mode" value="assign"> <?php esc_html_e( 'Nur zuordnen: nichts anlegen, vorhandene Einträge als „Import“ markieren und die externe ID übernehmen', 'local-reviews' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( '„Nur zuordnen“ ist für den Umstieg von den alten Snippets: einmal den alten Outscraper-Export hochladen, damit die übernommenen Einträge bearbeitet werden können.', 'local-reviews' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Vorschau anzeigen', 'local-reviews' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Vorschau eines Imports mit Bestätigung.
	 *
	 * @param array<string, mixed> $preview Gespeicherte Vorschau.
	 */
	private static function import_preview( array $preview ): void {
		$rows    = (array) $preview['rows'];
		$formats = [
			'csv'        => 'CSV',
			'outscraper' => 'Outscraper',
			'takeout'    => 'Google Takeout',
		];
		$labels  = [
			'new'      => __( 'neu', 'local-reviews' ),
			'existing' => __( 'schon vorhanden', 'local-reviews' ),
			'possible' => __( 'mögliche Dublette', 'local-reviews' ),
			'invalid'  => __( 'ungültig', 'local-reviews' ),
			'repeat'   => __( 'doppelt in der Datei', 'local-reviews' ),
		];
		$counts  = array_fill_keys( array_keys( $labels ), 0 );
		foreach ( $rows as $row ) {
			++$counts[ $row['status'] ];
		}
		$assign = 'assign' === $preview['mode'];
		?>
		<h2><?php esc_html_e( 'Vorschau', 'local-reviews' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: Dateiname, 2: Format, 3: Modus. */
				esc_html__( 'Datei %1$s, erkannt als %2$s, Modus: %3$s.', 'local-reviews' ),
				'<code>' . esc_html( (string) $preview['file'] ) . '</code>',
				esc_html( $formats[ $preview['format'] ] ?? (string) $preview['format'] ),
				$assign ? esc_html__( 'nur zuordnen', 'local-reviews' ) : esc_html__( 'importieren', 'local-reviews' )
			);
			?>
		</p>
		<p><strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: neu, 2: vorhanden, 3: mögliche Dubletten, 4: ungültig, 5: doppelt. */
					__( '%1$d neu, %2$d schon vorhanden, %3$d mögliche Dubletten, %4$d ungültig, %5$d doppelt in der Datei', 'local-reviews' ),
					$counts['new'],
					$counts['existing'],
					$counts['possible'],
					$counts['invalid'],
					$counts['repeat']
				)
			);
			?>
		</strong></p>
		<p class="description">
			<?php
			if ( $assign ) {
				esc_html_e( 'Schon vorhandene Einträge werden als „Import“ markiert und bekommen die externe ID. Mögliche Dubletten nur, wenn der Haken gesetzt ist. Neue Zeilen werden nicht angelegt.', 'local-reviews' );
			} else {
				esc_html_e( 'Neue Zeilen werden angelegt. Schon vorhandene werden übersprungen, bei importierten und manuellen Einträgen werden Text, Sterne und Link aktualisiert; Einträge aus dem Google-Abruf bleiben unverändert. Mögliche Dubletten nur, wenn der Haken gesetzt ist.', 'local-reviews' );
			}
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="local_reviews_import_confirm">
			<input type="hidden" name="token" value="<?php echo esc_attr( (string) ( $preview['token'] ?? '' ) ); ?>">
			<?php wp_nonce_field( 'local_reviews_import_confirm' ); ?>
			<table class="widefat striped local-reviews-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Status', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Name', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Sterne', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Text', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Plattform', 'local-reviews' ); ?></th>
						<th><?php esc_html_e( 'Passender Eintrag', 'local-reviews' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<?php $target = '' !== $row['target'] ? Store::get( (string) $row['target'] ) : null; ?>
					<tr class="local-reviews-status-<?php echo esc_attr( $row['status'] ); ?>">
						<td>
							<?php if ( 'possible' === $row['status'] ) : ?>
								<label><input type="checkbox" name="selected[]" value="<?php echo esc_attr( (string) $i ); ?>">
								<?php echo $assign ? esc_html__( 'trotzdem zuordnen', 'local-reviews' ) : esc_html__( 'trotzdem importieren', 'local-reviews' ); ?></label>
							<?php else : ?>
								<?php echo esc_html( $labels[ $row['status'] ] ); ?>
							<?php endif; ?>
							<?php if ( 'invalid' === $row['status'] ) : ?>
								<br><span class="description"><?php echo esc_html( $row['error'] . ( isset( $row['line'] ) ? ' (' . sprintf( /* translators: %d: Zeilennummer. */ __( 'Zeile %d', 'local-reviews' ), (int) $row['line'] ) . ')' : '' ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $row['timestamp'] > 0 ? esc_html( wp_date( 'd.m.Y', (int) $row['timestamp'] ) ) : '–'; ?></td>
						<td><?php echo esc_html( (string) $row['name'] ); ?></td>
						<td><?php echo $row['rating'] > 0 ? esc_html( str_repeat( '★', (int) $row['rating'] ) ) : '–'; ?></td>
						<td class="local-reviews-text"><?php echo esc_html( Text::excerpt( (string) $row['text'], 100 ) ); ?></td>
						<td><?php echo esc_html( Sources::label( (string) $row['source'], (string) $row['source_label'] ) ); ?></td>
						<td class="local-reviews-text">
							<?php
							if ( null !== $target ) {
								echo esc_html( $target['name'] . ', ' . ( $target['timestamp'] ? wp_date( 'd.m.Y', $target['timestamp'] ) : '–' ) . ': ' . Text::excerpt( $target['text'], 60 ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<?php submit_button( $assign ? __( 'Zuordnung speichern', 'local-reviews' ) : __( 'Import speichern', 'local-reviews' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="local_reviews_import_cancel">
			<?php wp_nonce_field( 'local_reviews_import_cancel' ); ?>
			<?php submit_button( __( 'Abbrechen', 'local-reviews' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Tab Anleitung.
	 */
	private static function tab_guide(): void {
		require PLUGIN_DIR . 'admin/guide.php';
	}
}

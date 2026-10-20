<?php
/**
 * Täglicher Abruf und Nachholen bei ausgefallenem WP-Cron.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Cron-Anmeldung und Sicherheitsnetz.
 */
final class Cron {

	/**
	 * Hooks registrieren.
	 */
	public static function init(): void {
		add_action( CRON_HOOK, [ self::class, 'run' ] );
		add_action( CATCHUP_HOOK, [ self::class, 'run' ] );
		add_action( 'admin_init', [ self::class, 'maybe_catch_up' ] );
		add_action( 'admin_init', [ self::class, 'ensure_scheduled' ] );
		add_action( 'admin_init', [ self::class, 'maybe_run_stuck_catchup' ] );
	}

	/**
	 * Cron-Handler.
	 */
	public static function run(): void {
		Fetcher::run();
	}

	/**
	 * Meldet den täglichen Abruf an, falls er fehlt.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', CRON_HOOK );
		}
	}

	/**
	 * Meldet alle eigenen Cron-Ereignisse ab.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( CRON_HOOK );
		wp_clear_scheduled_hook( CATCHUP_HOOK );
	}

	/**
	 * Stellt sicher, dass der tägliche Abruf geplant ist (z. B. nach einer Datenbank-Migration).
	 */
	public static function ensure_scheduled(): void {
		self::schedule();
	}

	/**
	 * Zweites Sicherheitsnetz: Hängt das Nachhol-Ereignis seit über 10 Minuten
	 * (WP-Cron startet auf diesem Server gar nicht), läuft der Abruf am Ende des
	 * aktuellen Backend-Aufrufs, nachdem die Seite ausgeliefert ist.
	 */
	public static function maybe_run_stuck_catchup(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$next = wp_next_scheduled( CATCHUP_HOOK );
		if ( ! $next || time() - $next < 10 * MINUTE_IN_SECONDS ) {
			return;
		}
		wp_unschedule_event( $next, CATCHUP_HOOK );
		add_action(
			'shutdown',
			static function (): void {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				Fetcher::run();
			}
		);
	}

	/**
	 * Sicherheitsnetz: Ist der letzte erfolgreiche Abruf älter als 36 Stunden,
	 * wird beim nächsten Backend-Aufruf ein Abruf im Hintergrund angestoßen.
	 *
	 * Höchstens ein Versuch pro Stunde, damit ein dauerhafter Fehler (z. B. falscher Key)
	 * nicht bei jedem Seitenaufruf die API belastet.
	 */
	public static function maybe_catch_up(): void {
		// Nur für angemeldete Admins: admin_init feuert auch für anonyme Aufrufe von admin-post.php.
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! Settings::is_configured() || ! Profile::is_stale() ) {
			return;
		}
		$status = Profile::status();
		if ( ( time() - $status['last_attempt'] ) < HOUR_IN_SECONDS || wp_next_scheduled( CATCHUP_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), CATCHUP_HOOK );
		// Läuft als eigener, nicht blockierender Request; die Seite wartet nicht.
		spawn_cron();
	}
}

<?php
/**
 * Updates aus GitHub-Releases.
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Bindet plugin-update-checker (YahnisElsts, MIT) ein. Das Plugin prüft das
 * öffentliche Repo henkwhite/local-reviews auf neue Releases und lädt die
 * angehängte ZIP-Datei.
 */
final class Updater {

	/**
	 * Repo mit den Releases.
	 */
	public const REPO = 'https://github.com/henkwhite/local-reviews/';

	/**
	 * Startet den Update-Check, sofern die Bibliothek mitgeliefert ist.
	 */
	public static function init(): void {
		$autoload = PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;
		if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			return;
		}
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( self::REPO, PLUGIN_FILE, 'local-reviews' );
		$api     = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			// Nur die angehängte Release-ZIP verwenden: Sie enthält vendor/, der
			// automatische Quellcode-Download von GitHub nicht. 2 = REQUIRE_RELEASE_ASSETS.
			$api->enableReleaseAssets( '/local-reviews.*\.zip$/i', 2 );
		}
	}
}

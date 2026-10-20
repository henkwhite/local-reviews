<?php
/**
 * Plugin Name:       Local Reviews
 * Plugin URI:        https://github.com/henkwhite/local-reviews
 * Description:       Google-Bewertungen lokal speichern und anzeigen, ergänzt um Bewertungen anderer Plattformen. Kein Nachladen von Google im Frontend.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Kronfink
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       local-reviews
 * Update URI:        https://github.com/henkwhite/local-reviews
 *
 * @package LocalReviews
 */

namespace LocalReviews;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.1.0';

// Optionen. Das Archiv und der API-Key werden ohne Autoload gespeichert.
const OPTION_SETTINGS = 'local_reviews_settings';
const OPTION_API_KEY  = 'local_reviews_api_key';
const OPTION_ARCHIVE  = 'local_reviews_archive';
const OPTION_PROFILE  = 'local_reviews_profile';
const OPTION_STATUS   = 'local_reviews_status';
const OPTION_MIGRATED = 'local_reviews_migrated';

// Cron-Hooks: täglicher Abruf und einmaliges Nachholen.
const CRON_HOOK    = 'local_reviews_fetch';
const CATCHUP_HOOK = 'local_reviews_catchup';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PLUGIN_DIR . 'includes/class-settings.php';
require_once PLUGIN_DIR . 'includes/class-sources.php';
require_once PLUGIN_DIR . 'includes/class-text.php';
require_once PLUGIN_DIR . 'includes/class-store.php';
require_once PLUGIN_DIR . 'includes/class-profile.php';
require_once PLUGIN_DIR . 'includes/class-cache.php';
require_once PLUGIN_DIR . 'includes/class-photo.php';
require_once PLUGIN_DIR . 'includes/class-fetcher.php';
require_once PLUGIN_DIR . 'includes/class-cron.php';
require_once PLUGIN_DIR . 'includes/class-migration.php';
require_once PLUGIN_DIR . 'includes/class-importer.php';
require_once PLUGIN_DIR . 'includes/class-render.php';
require_once PLUGIN_DIR . 'includes/class-updater.php';
require_once PLUGIN_DIR . 'includes/functions.php';
require_once PLUGIN_DIR . 'admin/class-admin-page.php';

register_activation_hook( __FILE__, [ Settings::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Settings::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function () {
		Updater::init();
		Cron::init();
		Render::init();
		if ( is_admin() ) {
			Migration::init();
			Admin_Page::init();
		}
	}
);

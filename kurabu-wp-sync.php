<?php
/**
 * Plugin Name:       KURABU WP Sync
 * Plugin URI:        https://github.com/linus1423/Kurabu-WP-Sync
 * Description:       Synchronisiert Abteilungen, Teams, Trainingszeiten, Trainingsorte, News und Events aus der KURABU-API des TV 1848 Coburg nach WordPress und stellt sie lokal gecacht über Shortcodes bereit.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TV 1848 Coburg
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kurabu-wp-sync
 * Domain Path:       /languages
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync;

defined( 'ABSPATH' ) || exit;

define( 'KURABU_WP_SYNC_VERSION', '0.1.0' );
define( 'KURABU_WP_SYNC_FILE', __FILE__ );
define( 'KURABU_WP_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'KURABU_WP_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once KURABU_WP_SYNC_DIR . 'includes/Autoloader.php';

Autoloader::register();

// Sync engine and display layer hook themselves into kurabu_wp_sync_booted, so
// both have to be listening before the container boots.
Sync\Engine::bootstrap();
Shortcode\ShortcodeManager::bootstrap();

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/**
 * Returns the plugin container.
 *
 * This is the single entry point other layers (sync engine, shortcodes,
 * template engine) use to reach the shared services.
 */
function plugin(): Plugin {
	return Plugin::instance();
}

plugin()->boot();

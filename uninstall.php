<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * Deactivating keeps the cached data; deleting the plugin drops it.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'KURABU_WP_SYNC_DIR' ) ) {
	define( 'KURABU_WP_SYNC_DIR', plugin_dir_path( __FILE__ ) );
}

require_once KURABU_WP_SYNC_DIR . 'includes/Autoloader.php';

\Kurabu\WPSync\Autoloader::register();

\Kurabu\WPSync\Database\Schema::drop();
\Kurabu\WPSync\Support\Settings::delete();

wp_clear_scheduled_hook( \Kurabu\WPSync\Plugin::CRON_HOOK );

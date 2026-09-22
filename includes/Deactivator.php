<?php
/**
 * Plugin deactivation.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync;

defined( 'ABSPATH' ) || exit;

/**
 * Stops scheduled work. Cached data and settings are kept, so deactivating
 * and reactivating the plugin does not cost a full resync.
 */
final class Deactivator {

	/**
	 * Runs on deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Plugin::CRON_HOOK );

		/**
		 * Fires after the scheduled runs were cleared.
		 */
		do_action( 'kurabu_wp_sync_deactivated' );
	}
}

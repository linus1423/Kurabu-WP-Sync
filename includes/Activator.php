<?php
/**
 * Plugin activation.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync;

use Kurabu\WPSync\Database\Repository\AbstractRepository;
use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the local cache tables and seeds the default settings.
 */
final class Activator {

	/**
	 * Runs on activation.
	 */
	public static function activate(): void {
		Schema::install();
		AbstractRepository::flush_columns_cache();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		/**
		 * Fires after the tables exist.
		 *
		 * The sync engine uses this to schedule its first run.
		 */
		do_action( 'kurabu_wp_sync_activated' );
	}
}

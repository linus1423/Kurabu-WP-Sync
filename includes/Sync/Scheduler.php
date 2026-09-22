<?php
/**
 * WP-Cron scheduling for the sync runs.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the cron event in step with the configured interval.
 *
 * WordPress only knows hourly, twice daily and daily out of the box, so every
 * interval the specification asks for is registered as an own schedule, built
 * from Settings::INTERVALS.
 */
final class Scheduler {

	/**
	 * Prefix of the registered schedule names.
	 */
	public const SCHEDULE_PREFIX = 'kurabu_wp_sync_';

	/**
	 * Adds a cron schedule per configurable interval.
	 *
	 * @param mixed $schedules Registered schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$labels    = Settings::interval_labels();

		foreach ( Settings::INTERVALS as $key => $seconds ) {
			$schedules[ self::schedule_name( $key ) ] = array(
				'interval' => $seconds,
				'display'  => sprintf(
					/* translators: %s: interval label. */
					__( 'KURABU: %s', 'kurabu-wp-sync' ),
					$labels[ $key ] ?? $key
				),
			);
		}

		return $schedules;
	}

	/**
	 * The schedule name of an interval key.
	 *
	 * @param string $interval_key One of the Settings::INTERVALS keys.
	 */
	public static function schedule_name( string $interval_key ): string {
		return self::SCHEDULE_PREFIX . $interval_key;
	}

	/**
	 * Schedules, reschedules or removes the recurring run.
	 *
	 * Called after activation and after the settings were saved. An already
	 * correct schedule is left alone, so saving the settings does not keep
	 * pushing the next run into the future.
	 */
	public static function reschedule(): void {
		if ( ! Settings::get( 'sync_enabled', true ) ) {
			self::unschedule();

			return;
		}

		$wanted    = self::schedule_name( Settings::interval_key() );
		$scheduled = wp_get_schedule( Plugin::CRON_HOOK );

		if ( $scheduled === $wanted ) {
			return;
		}

		self::unschedule();

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $wanted, Plugin::CRON_HOOK );
	}

	/**
	 * Queues one run to start on the next cron tick.
	 *
	 * Used by the "im Hintergrund starten" button, so a long run does not have
	 * to finish inside an admin request.
	 *
	 * @return bool False when a run is already queued.
	 */
	public static function schedule_single_run(): bool {
		$timestamp = time() + 5;

		if ( wp_next_scheduled( Plugin::CRON_HOOK ) === $timestamp ) {
			return false;
		}

		return false !== wp_schedule_single_event( $timestamp, Plugin::CRON_HOOK );
	}

	/**
	 * Removes every scheduled run.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( Plugin::CRON_HOOK );
	}

	/**
	 * The timestamp of the next scheduled run, or 0.
	 */
	public static function next_run(): int {
		return (int) wp_next_scheduled( Plugin::CRON_HOOK );
	}

	/**
	 * Whether the recurring run is scheduled at all.
	 */
	public static function is_scheduled(): bool {
		return false !== wp_get_schedule( Plugin::CRON_HOOK );
	}
}

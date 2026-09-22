<?php
/**
 * The selectable calendar targets.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Calendar;

use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the calendar adapters and which one the site selected.
 */
final class CalendarRegistry {

	/**
	 * Every adapter, whether available or not.
	 *
	 * @return CalendarAdapter[]
	 */
	public static function adapters(): array {
		$adapters = array(
			new EventsCalendarAdapter(),
			new PostTypeAdapter(),
		);

		/**
		 * Filters the calendar adapters.
		 *
		 * A site with a different calendar plugin adds its adapter here.
		 *
		 * @param CalendarAdapter[] $adapters Registered adapters.
		 */
		return array_values(
			array_filter(
				(array) apply_filters( 'kurabu_wp_sync_calendar_adapters', $adapters ),
				static function ( $adapter ): bool {
					return $adapter instanceof CalendarAdapter;
				}
			)
		);
	}

	/**
	 * The adapter with the given key, or null.
	 *
	 * @param string $key Adapter key.
	 */
	public static function get( string $key ): ?CalendarAdapter {
		foreach ( self::adapters() as $adapter ) {
			if ( $adapter->key() === $key ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * The configured adapter, or null when events have no target yet.
	 */
	public static function active(): ?CalendarAdapter {
		$key = trim( (string) Settings::get( 'calendar_target', '' ) );

		if ( '' === $key ) {
			return null;
		}

		$adapter = self::get( $key );

		return ( null !== $adapter && $adapter->is_available() ) ? $adapter : null;
	}
}

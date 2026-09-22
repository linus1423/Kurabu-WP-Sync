<?php
/**
 * Formats cached KURABU values for display.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Model\AbstractModel;

defined( 'ABSPATH' ) || exit;

/**
 * Small formatting helpers for weekdays, times and addresses.
 *
 * Weekdays are stored as numbers. KURABU is read as ISO-8601 (1 = Monday …
 * 7 = Sunday); a 0 is treated as Sunday as well, so both common numberings
 * produce the right label.
 */
final class Format {

	/**
	 * The weekday names, ISO-8601 order.
	 *
	 * @return array<int, string>
	 */
	public static function weekdays(): array {
		return array(
			1 => __( 'Montag', 'kurabu-wp-sync' ),
			2 => __( 'Dienstag', 'kurabu-wp-sync' ),
			3 => __( 'Mittwoch', 'kurabu-wp-sync' ),
			4 => __( 'Donnerstag', 'kurabu-wp-sync' ),
			5 => __( 'Freitag', 'kurabu-wp-sync' ),
			6 => __( 'Samstag', 'kurabu-wp-sync' ),
			7 => __( 'Sonntag', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * The name of a weekday, or an empty string when the number is unknown.
	 *
	 * @param mixed $weekday Stored weekday number.
	 */
	public static function weekday( $weekday ): string {
		if ( ! is_numeric( $weekday ) ) {
			return '';
		}

		$number = (int) $weekday;

		if ( 0 === $number ) {
			$number = 7;
		}

		return self::weekdays()[ $number ] ?? '';
	}

	/**
	 * A stored time as HH:MM, or an empty string.
	 *
	 * @param mixed $time Stored time, e.g. "15:00:00".
	 */
	public static function time( $time ): string {
		if ( ! is_string( $time ) || '' === $time ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', $time, $matches ) ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
	}

	/**
	 * "15:00 – 16:00", or just the start when there is no end.
	 *
	 * @param mixed $start Stored start time.
	 * @param mixed $end   Stored end time.
	 */
	public static function time_range( $start, $end ): string {
		$start = self::time( $start );
		$end   = self::time( $end );

		if ( '' === $start ) {
			return $end;
		}

		return '' === $end ? $start : $start . ' – ' . $end;
	}

	/**
	 * One Trainingszeit as "Montag 15:00 – 16:00".
	 *
	 * @param AbstractModel $time A TrainingTime.
	 */
	public static function slot( AbstractModel $time ): string {
		$parts = array_filter(
			array(
				self::weekday( $time->get( 'weekday' ) ),
				self::time_range( $time->get( 'start_time' ), $time->get( 'end_time' ) ),
			)
		);

		return implode( ' ', $parts );
	}

	/**
	 * A Trainingsort's address as one line.
	 *
	 * @param AbstractModel $location A Location.
	 */
	public static function address( AbstractModel $location ): string {
		$street = (string) $location->get( 'street', '' );
		$city   = trim( (string) $location->get( 'postal_code', '' ) . ' ' . (string) $location->get( 'city', '' ) );

		return implode( ', ', array_filter( array( $street, $city ) ) );
	}
}

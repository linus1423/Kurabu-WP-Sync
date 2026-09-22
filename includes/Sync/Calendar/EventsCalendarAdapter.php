<?php
/**
 * Adapter for The Events Calendar.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Calendar;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes events through the API of The Events Calendar.
 *
 * Its own functions are used rather than raw posts and meta, so the plugin's
 * date indexes and recurrence handling stay intact.
 */
final class EventsCalendarAdapter implements CalendarAdapter {

	public const KEY = 'the_events_calendar';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'The Events Calendar', 'kurabu-wp-sync' );
	}

	public function description(): string {
		return __( 'Legt die Events über die Funktionen von The Events Calendar an, inklusive Veranstaltungsort.', 'kurabu-wp-sync' );
	}

	public function is_available(): bool {
		return function_exists( 'tribe_create_event' ) && function_exists( 'tribe_update_event' );
	}

	public function object_type(): string {
		return 'tribe_events';
	}

	/**
	 * Creates or updates the event.
	 *
	 * @param array<string, mixed> $event       Mapped KURABU values.
	 * @param int                  $existing_id Event id, or 0.
	 *
	 * @return int|WP_Error
	 */
	public function upsert( array $event, int $existing_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'kurabu_calendar_missing', __( 'The Events Calendar ist nicht aktiv.', 'kurabu-wp-sync' ) );
		}

		$start = (string) ( $event['start'] ?? '' );

		if ( '' === $start ) {
			return new WP_Error( 'kurabu_event_without_start', __( 'Der Event hat kein Startdatum.', 'kurabu-wp-sync' ) );
		}

		$end     = (string) ( $event['end'] ?? '' );
		$all_day = ! empty( $event['all_day'] );
		$title   = (string) ( $event['title'] ?? '' );

		list( $start_date, $start_time ) = $this->split( $start );

		$args = array(
			'post_title'     => '' !== $title ? $title : __( '(ohne Titel)', 'kurabu-wp-sync' ),
			'post_content'   => (string) ( $event['description'] ?? '' ),
			'post_status'    => 'publish',
			'EventStartDate' => $start_date,
			'EventStartTime' => $start_time,
			'EventAllDay'    => $all_day,
		);

		if ( '' !== $end ) {
			list( $args['EventEndDate'], $args['EventEndTime'] ) = $this->split( $end );
		} else {
			$args['EventEndDate'] = $start_date;
			$args['EventEndTime'] = $start_time;
		}

		$location = (string) ( $event['location_name'] ?? '' );

		if ( '' !== $location ) {
			$args['Venue'] = array( 'Venue' => $location );
		}

		/**
		 * Filters the arguments handed to The Events Calendar.
		 *
		 * @param array<string, mixed> $args  Event arguments.
		 * @param array<string, mixed> $event Mapped KURABU values.
		 */
		$args = (array) apply_filters( 'kurabu_wp_sync_tribe_event_args', $args, $event );

		if ( $existing_id > 0 && null !== get_post( $existing_id ) ) {
			$result = tribe_update_event( $existing_id, $args );
		} else {
			$result = tribe_create_event( $args );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$id = (int) $result;

		return $id > 0
			? $id
			: new WP_Error( 'kurabu_event_not_saved', __( 'Der Event konnte nicht gespeichert werden.', 'kurabu-wp-sync' ) );
	}

	/**
	 * Splits a mapped datetime into its date and time part.
	 *
	 * The mapper normalises every datetime to `Y-m-d H:i:s` in site local
	 * time, which is exactly what The Events Calendar stores, so the string is
	 * split rather than re-parsed through a timezone.
	 *
	 * @param string $datetime Normalised datetime.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function split( string $datetime ): array {
		$parts = explode( ' ', trim( $datetime ), 2 );

		return array( $parts[0], $parts[1] ?? '00:00:00' );
	}
}

<?php
/**
 * Contract for writing an event into the WordPress calendar.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Calendar;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one KURABU event into whatever calendar the site runs.
 *
 * The specification asks for the events to land in the club's existing
 * WordPress calendar, so the calendar is a target the site chooses rather than
 * something this plugin brings along. Each supported calendar is one adapter,
 * selected on the "Kalenderintegration" screen.
 */
interface CalendarAdapter {

	/**
	 * The value this adapter is selected by.
	 */
	public function key(): string;

	/**
	 * Label for the backend.
	 */
	public function label(): string;

	/**
	 * A sentence describing what this adapter writes into.
	 */
	public function description(): string;

	/**
	 * Whether this calendar is present and usable on this site.
	 */
	public function is_available(): bool;

	/**
	 * The WordPress object type, as recorded in the object map.
	 */
	public function object_type(): string;

	/**
	 * Creates or updates the calendar entry of one event.
	 *
	 * @param array<string, mixed> $event       Mapped KURABU values.
	 * @param int                  $existing_id Calendar entry id, or 0.
	 *
	 * @return int|WP_Error The entry id, or the error.
	 */
	public function upsert( array $event, int $existing_id );
}

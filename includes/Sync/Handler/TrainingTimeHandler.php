<?php
/**
 * Trainingszeiten handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TrainingTimeRepository;
use Kurabu\WPSync\Sync\Mapping\CalendarEvent;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Trainingszeiten into the local cache.
 *
 * The times come out of the same calendar events the trainings come from: the
 * weekday sits in the `BYDAY` part of the event's rrule, so a training on
 * Monday and Wednesday is one event and two training times. Each time gets the
 * event's id with its weekday appended, which stays the same on the next run
 * and is what keeps the rows from doubling.
 */
final class TrainingTimeHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::TRAINING_TIMES;
	}

	protected function repository(): TrainingTimeRepository {
		return plugin()->training_times();
	}

	/**
	 * Keeps the calendar events that are trainings.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	protected function accepts( array $record ): bool {
		return CalendarEvent::is_training( $record ) && CalendarEvent::is_public( $record );
	}

	/**
	 * Turns one event into one row per weekday it happens on.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return array<int, array<string|int, mixed>>
	 */
	protected function expand( array $record ): array {
		$event_id = isset( $record['id'] ) && is_scalar( $record['id'] ) ? trim( (string) $record['id'] ) : '';

		if ( '' === $event_id ) {
			return array();
		}

		$rows = array();

		foreach ( CalendarEvent::weekdays( $record ) as $weekday ) {
			$rows[] = array(
				'id'          => $event_id . ':' . $weekday,
				'training_id' => $event_id,
				'weekday'     => $weekday,
				'start_time'  => $record['start_time'] ?? null,
				'end_time'    => $record['end_time'] ?? null,
				'valid_from'  => $record['start_date'] ?? null,
				'valid_until' => CalendarEvent::until( $record ),
				'notes'       => $record['location_info'] ?? null,
			);
		}

		return $rows;
	}
}

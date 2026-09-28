<?php
/**
 * Trainings handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TrainingRepository;
use Kurabu\WPSync\Sync\Mapping\CalendarEvent;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Trainings into the local cache.
 *
 * KURABU has no trainings endpoint: a training is a recurring calendar event
 * that belongs to a team, so this handler reads the calendar and keeps the
 * events that are trainings. The one-off events go to the calendar instead, see
 * EventHandler.
 */
final class TrainingHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::TRAININGS;
	}

	protected function repository(): TrainingRepository {
		return plugin()->trainings();
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
	 * Fills in the values KURABU spreads over several fields.
	 *
	 * @param array<string, mixed>     $data   Column values.
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare( array $data, array $record ): array {
		if ( '' === (string) ( $data['trainer'] ?? '' ) ) {
			$data['trainer'] = CalendarEvent::leads( $record );
		}

		// An event without its own room or facility falls back to the location.
		if ( '' === (string) ( $data['location_kurabu_id'] ?? '' ) ) {
			foreach ( array( 'facility_id', 'room_id' ) as $key ) {
				$value = isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? trim( (string) $record[ $key ] ) : '';

				if ( '' !== $value ) {
					$data['location_kurabu_id'] = $value;
					break;
				}
			}
		}

		return $data;
	}
}

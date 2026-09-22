<?php
/**
 * The data kinds the plugin synchronises.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * The resource keys shared by the engine, the state table and the log.
 *
 * The order of self::all() is the order a run works through: the master data
 * first, because trainings reference departments, teams and locations by their
 * KURABU id, then news and events.
 */
final class Resource {

	public const DEPARTMENTS    = 'departments';
	public const TEAMS          = 'teams';
	public const LOCATIONS      = 'locations';
	public const TRAININGS      = 'trainings';
	public const TRAINING_TIMES = 'training_times';
	public const NEWS           = 'news';
	public const EVENTS         = 'events';

	/**
	 * Every resource key, in sync order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::DEPARTMENTS,
			self::TEAMS,
			self::LOCATIONS,
			self::TRAININGS,
			self::TRAINING_TIMES,
			self::NEWS,
			self::EVENTS,
		);
	}

	/**
	 * Whether a key is a known resource.
	 *
	 * @param string $resource Resource key.
	 */
	public static function is_valid( string $resource ): bool {
		return in_array( $resource, self::all(), true );
	}

	/**
	 * Keeps only known resource keys, in sync order.
	 *
	 * @param string[] $resources Requested keys.
	 *
	 * @return string[]
	 */
	public static function filter( array $resources ): array {
		$requested = array_map( 'strval', $resources );

		return array_values(
			array_filter(
				self::all(),
				static function ( string $resource ) use ( $requested ): bool {
					return in_array( $resource, $requested, true );
				}
			)
		);
	}

	/**
	 * Translated labels for the backend.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::DEPARTMENTS    => __( 'Abteilungen / Sportarten', 'kurabu-wp-sync' ),
			self::TEAMS          => __( 'Teams / Trainingsgruppen', 'kurabu-wp-sync' ),
			self::LOCATIONS      => __( 'Trainingsorte', 'kurabu-wp-sync' ),
			self::TRAININGS      => __( 'Trainings', 'kurabu-wp-sync' ),
			self::TRAINING_TIMES => __( 'Trainingszeiten', 'kurabu-wp-sync' ),
			self::NEWS           => __( 'Beiträge / News', 'kurabu-wp-sync' ),
			self::EVENTS         => __( 'Events', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * The label of one resource, falling back to its key.
	 *
	 * @param string $resource Resource key.
	 */
	public static function label( string $resource ): string {
		return self::labels()[ $resource ] ?? $resource;
	}
}

<?php
/**
 * The registered resource handlers.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Sync\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Maps resource keys onto handlers.
 */
final class HandlerRegistry {

	/**
	 * Every handler, keyed by resource, in sync order.
	 *
	 * @return array<string, Handler>
	 */
	public static function handlers(): array {
		$handlers = array(
			new DepartmentHandler(),
			new TeamHandler(),
			new LocationHandler(),
			new TrainingHandler(),
			new TrainingTimeHandler(),
			new NewsHandler(),
			new EventHandler(),
		);

		/**
		 * Filters the resource handlers.
		 *
		 * @param Handler[] $handlers Registered handlers.
		 */
		$handlers = (array) apply_filters( 'kurabu_wp_sync_handlers', $handlers );
		$keyed    = array();

		foreach ( $handlers as $handler ) {
			if ( $handler instanceof Handler ) {
				$keyed[ $handler->resource() ] = $handler;
			}
		}

		$ordered = array();

		foreach ( Resource::all() as $resource ) {
			if ( isset( $keyed[ $resource ] ) ) {
				$ordered[ $resource ] = $keyed[ $resource ];
			}
		}

		// Handlers for resources outside the known list keep their place.
		return $ordered + $keyed;
	}

	/**
	 * The handler of one resource, or null.
	 *
	 * @param string $resource Resource key.
	 */
	public static function get( string $resource ): ?Handler {
		return self::handlers()[ $resource ] ?? null;
	}
}

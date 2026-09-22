<?php
/**
 * Per-resource synchronisation state.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per resource, when it was last synced successfully.
 *
 * This is what makes incremental syncing possible: the sync engine asks for
 * `last_success_at` and only requests records changed since then. Because a
 * failed run never updates `last_success_at`, a later run retries the same
 * window and the cached data from the last good run stays untouched.
 */
final class SyncState {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_ERROR   = 'error';
	public const STATUS_RUNNING = 'running';

	/**
	 * Returns the stored state of a resource, or null when never synced.
	 *
	 * @param string $resource Resource key, e.g. "departments".
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $resource ): ?array {
		global $wpdb;

		$table = Schema::table( Schema::SYNC_STATE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE resource = %s", $resource ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the state of every resource, keyed by resource.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;

		$table = Schema::table( Schema::SYNC_STATE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY resource ASC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$states = array();

		foreach ( $rows as $row ) {
			$states[ (string) $row['resource'] ] = $row;
		}

		return $states;
	}

	/**
	 * The timestamp of the last successful sync of a resource.
	 *
	 * @param string $resource Resource key.
	 *
	 * @return string|null MySQL datetime, or null when never successful.
	 */
	public static function last_success( string $resource ): ?string {
		$state = self::get( $resource );

		if ( null === $state || empty( $state['last_success_at'] ) ) {
			return null;
		}

		return (string) $state['last_success_at'];
	}

	/**
	 * Records that a run for this resource has started.
	 *
	 * @param string $resource Resource key.
	 */
	public static function mark_attempt( string $resource ): void {
		self::write(
			$resource,
			array(
				'last_attempt_at' => current_time( 'mysql' ),
				'last_status'     => self::STATUS_RUNNING,
			)
		);
	}

	/**
	 * Records a successful run.
	 *
	 * @param string $resource Resource key.
	 * @param int    $items    Number of synced items.
	 * @param string $cursor   Optional cursor for the next incremental run.
	 */
	public static function mark_success( string $resource, int $items = 0, string $cursor = '' ): void {
		self::write(
			$resource,
			array(
				'last_success_at' => current_time( 'mysql' ),
				'last_status'     => self::STATUS_SUCCESS,
				'items_synced'    => $items,
				'last_cursor'     => $cursor,
				'message'         => '',
			)
		);
	}

	/**
	 * Records a failed run, leaving `last_success_at` untouched.
	 *
	 * @param string $resource Resource key.
	 * @param string $message  Error message.
	 */
	public static function mark_error( string $resource, string $message ): void {
		self::write(
			$resource,
			array(
				'last_status' => self::STATUS_ERROR,
				'message'     => $message,
			)
		);
	}

	/**
	 * Inserts or updates the state row of a resource.
	 *
	 * @param string               $resource Resource key.
	 * @param array<string, mixed> $data     Columns to write.
	 */
	private static function write( string $resource, array $data ): void {
		global $wpdb;

		$table              = Schema::table( Schema::SYNC_STATE );
		$data['updated_at'] = current_time( 'mysql' );

		if ( null === self::get( $resource ) ) {
			$data['resource'] = $resource;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $data );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'resource' => $resource ) );
	}
}

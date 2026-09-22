<?php
/**
 * Writes the Synchronisations- und Fehlerprotokoll.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Support;

use Kurabu\WPSync\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only log backed by the sync log table.
 *
 * The sync engine logs through this class; the "Fehlerprotokoll" admin screen
 * reads through it.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Id shared by all entries of one sync run.
	 */
	private string $run_id;

	/**
	 * @param string $run_id Optional run id; generated when empty.
	 */
	public function __construct( string $run_id = '' ) {
		$this->run_id = '' !== $run_id ? $run_id : wp_generate_uuid4();
	}

	/**
	 * The id of the current run.
	 */
	public function run_id(): string {
		return $this->run_id;
	}

	/**
	 * Logs an entry.
	 *
	 * @param string               $level    One of the level constants.
	 * @param string               $message  Human readable message.
	 * @param string               $resource Resource the entry belongs to.
	 * @param array<string, mixed> $context  Extra data, stored as JSON.
	 */
	public function log( string $level, string $message, string $resource = '', array $context = array() ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::table( Schema::SYNC_LOG ),
			array(
				'run_id'     => $this->run_id,
				'resource'   => $resource,
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? (string) wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Logs an informational entry.
	 *
	 * @param string               $message  Message.
	 * @param string               $resource Resource.
	 * @param array<string, mixed> $context  Context.
	 */
	public function info( string $message, string $resource = '', array $context = array() ): void {
		$this->log( self::INFO, $message, $resource, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string               $message  Message.
	 * @param string               $resource Resource.
	 * @param array<string, mixed> $context  Context.
	 */
	public function warning( string $message, string $resource = '', array $context = array() ): void {
		$this->log( self::WARNING, $message, $resource, $context );
	}

	/**
	 * Logs an error.
	 *
	 * @param string               $message  Message.
	 * @param string               $resource Resource.
	 * @param array<string, mixed> $context  Context.
	 */
	public function error( string $message, string $resource = '', array $context = array() ): void {
		$this->log( self::ERROR, $message, $resource, $context );
	}

	/**
	 * Reads recent log entries, newest first.
	 *
	 * @param int    $limit Maximum number of entries.
	 * @param string $level Optional level filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 100, string $level = '' ): array {
		global $wpdb;

		$table = Schema::table( Schema::SYNC_LOG );
		$limit = max( 1, min( 1000, $limit ) );

		if ( '' !== $level ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
					$level,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes entries older than the given number of days.
	 *
	 * @param int $days Age threshold in days.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function purge_older_than( int $days ): int {
		global $wpdb;

		$table = Schema::table( Schema::SYNC_LOG );

		// Entries are written with current_time( 'mysql' ), so the cut-off has
		// to be in site local time as well.
		$cutoff = (string) wp_date( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}

<?php
/**
 * Mapping between stable KURABU ids and WordPress objects.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which WordPress object a KURABU record was synced into.
 *
 * News become WordPress posts and events go into the club calendar, so those
 * two resources are not cached in their own tables but written into existing
 * WordPress objects. This map is what makes a second sync run update the
 * object it created earlier instead of creating a duplicate.
 */
final class ObjectMap {

	public const TYPE_POST  = 'post';
	public const TYPE_EVENT = 'event';

	/**
	 * Returns the WordPress object id for a KURABU record, or 0.
	 *
	 * @param string $object_type One of the TYPE_* constants.
	 * @param string $kurabu_id   Stable KURABU id.
	 */
	public static function get_wp_id( string $object_type, string $kurabu_id ): int {
		$row = self::get( $object_type, $kurabu_id );

		return null === $row ? 0 : (int) $row['wp_object_id'];
	}

	/**
	 * Returns the full mapping row, or null.
	 *
	 * @param string $object_type One of the TYPE_* constants.
	 * @param string $kurabu_id   Stable KURABU id.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $object_type, string $kurabu_id ): ?array {
		global $wpdb;

		$table = Schema::table( Schema::OBJECT_MAP );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND kurabu_id = %s",
				$object_type,
				$kurabu_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the KURABU id a WordPress object came from, or an empty string.
	 *
	 * @param string $wp_object_type WordPress object type, e.g. "post".
	 * @param int    $wp_object_id   WordPress object id.
	 */
	public static function get_kurabu_id( string $wp_object_type, int $wp_object_id ): string {
		global $wpdb;

		$table = Schema::table( Schema::OBJECT_MAP );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT kurabu_id FROM {$table} WHERE wp_object_type = %s AND wp_object_id = %d",
				$wp_object_type,
				$wp_object_id
			)
		);
	}

	/**
	 * Stores or refreshes a mapping.
	 *
	 * @param string $object_type    One of the TYPE_* constants.
	 * @param string $kurabu_id      Stable KURABU id.
	 * @param int    $wp_object_id   WordPress object id.
	 * @param string $wp_object_type WordPress object type.
	 * @param string $checksum       Checksum of the synced payload.
	 */
	public static function link( string $object_type, string $kurabu_id, int $wp_object_id, string $wp_object_type = 'post', string $checksum = '' ): void {
		global $wpdb;

		$table = Schema::table( Schema::OBJECT_MAP );
		$now   = current_time( 'mysql' );

		$data = array(
			'wp_object_id'   => $wp_object_id,
			'wp_object_type' => $wp_object_type,
			'checksum'       => $checksum,
			'synced_at'      => $now,
			'updated_at'     => $now,
		);

		if ( null === self::get( $object_type, $kurabu_id ) ) {
			$data['object_type'] = $object_type;
			$data['kurabu_id']   = $kurabu_id;
			$data['created_at']  = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $data );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$data,
			array(
				'object_type' => $object_type,
				'kurabu_id'   => $kurabu_id,
			)
		);
	}

	/**
	 * Removes a mapping.
	 *
	 * @param string $object_type One of the TYPE_* constants.
	 * @param string $kurabu_id   Stable KURABU id.
	 */
	public static function unlink( string $object_type, string $kurabu_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Schema::table( Schema::OBJECT_MAP ),
			array(
				'object_type' => $object_type,
				'kurabu_id'   => $kurabu_id,
			)
		);
	}

	/**
	 * Counts the mappings of one object type.
	 *
	 * @param string $object_type One of the TYPE_* constants.
	 */
	public static function count( string $object_type ): int {
		global $wpdb;

		$table = Schema::table( Schema::OBJECT_MAP );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s", $object_type )
		);
	}
}

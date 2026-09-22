<?php
/**
 * Local database schema for the cached KURABU data.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the table names and the dbDelta definitions of the local cache.
 *
 * Every table keeps the stable KURABU id as a unique key, a raw `payload`
 * column with the untouched API record and a `checksum` used to detect
 * changes without comparing every column. Keeping the raw payload means the
 * template engine can expose KURABU fields that the schema does not model
 * explicitly.
 */
final class Schema {

	public const DEPARTMENTS    = 'kurabu_departments';
	public const TEAMS          = 'kurabu_teams';
	public const LOCATIONS      = 'kurabu_locations';
	public const TRAININGS      = 'kurabu_trainings';
	public const TRAINING_TIMES = 'kurabu_training_times';
	public const OBJECT_MAP     = 'kurabu_object_map';
	public const SYNC_STATE     = 'kurabu_sync_state';
	public const SYNC_LOG       = 'kurabu_sync_log';

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'kurabu_wp_sync_db_version';

	/**
	 * Returns the prefixed table name for one of the class constants.
	 *
	 * @param string $table Unprefixed table name.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Returns every table this plugin owns, prefixed.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array_map(
			array( self::class, 'table' ),
			array(
				self::DEPARTMENTS,
				self::TEAMS,
				self::LOCATIONS,
				self::TRAININGS,
				self::TRAINING_TIMES,
				self::OBJECT_MAP,
				self::SYNC_STATE,
				self::SYNC_LOG,
			)
		);
	}

	/**
	 * Creates or upgrades the tables when the stored version is outdated.
	 *
	 * @param bool $force Run dbDelta even when the version matches.
	 */
	public static function maybe_install( bool $force = false ): void {
		if ( ! $force && self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Runs dbDelta for every table definition.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drops every table. Only used on uninstall.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be prepared.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * The dbDelta statements, one per table.
	 *
	 * @return string[]
	 */
	private static function definitions(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$departments = self::table( self::DEPARTMENTS );
		$teams       = self::table( self::TEAMS );
		$locations   = self::table( self::LOCATIONS );
		$trainings   = self::table( self::TRAININGS );
		$times       = self::table( self::TRAINING_TIMES );
		$map         = self::table( self::OBJECT_MAP );
		$state       = self::table( self::SYNC_STATE );
		$log         = self::table( self::SYNC_LOG );

		return array(
			"CREATE TABLE {$departments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kurabu_id varchar(64) NOT NULL,
				slug varchar(191) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				description longtext NULL,
				image_url text NULL,
				contact_name varchar(191) NOT NULL DEFAULT '',
				contact_email varchar(191) NOT NULL DEFAULT '',
				contact_phone varchar(64) NOT NULL DEFAULT '',
				link text NULL,
				menu_order int(11) NOT NULL DEFAULT 0,
				payload longtext NULL,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY kurabu_id (kurabu_id),
				KEY slug (slug)
			) {$charset};",

			"CREATE TABLE {$teams} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kurabu_id varchar(64) NOT NULL,
				department_kurabu_id varchar(64) NOT NULL DEFAULT '',
				slug varchar(191) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				description longtext NULL,
				menu_order int(11) NOT NULL DEFAULT 0,
				payload longtext NULL,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY kurabu_id (kurabu_id),
				KEY department_kurabu_id (department_kurabu_id),
				KEY slug (slug)
			) {$charset};",

			"CREATE TABLE {$locations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kurabu_id varchar(64) NOT NULL,
				slug varchar(191) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				room varchar(191) NOT NULL DEFAULT '',
				street varchar(191) NOT NULL DEFAULT '',
				postal_code varchar(16) NOT NULL DEFAULT '',
				city varchar(191) NOT NULL DEFAULT '',
				latitude decimal(10,7) NULL,
				longitude decimal(10,7) NULL,
				notes longtext NULL,
				payload longtext NULL,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY kurabu_id (kurabu_id),
				KEY slug (slug)
			) {$charset};",

			"CREATE TABLE {$trainings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kurabu_id varchar(64) NOT NULL,
				department_kurabu_id varchar(64) NOT NULL DEFAULT '',
				team_kurabu_id varchar(64) NOT NULL DEFAULT '',
				location_kurabu_id varchar(64) NOT NULL DEFAULT '',
				slug varchar(191) NOT NULL DEFAULT '',
				name varchar(191) NOT NULL DEFAULT '',
				description longtext NULL,
				trainer varchar(191) NOT NULL DEFAULT '',
				image_url text NULL,
				link text NULL,
				menu_order int(11) NOT NULL DEFAULT 0,
				payload longtext NULL,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY kurabu_id (kurabu_id),
				KEY department_kurabu_id (department_kurabu_id),
				KEY team_kurabu_id (team_kurabu_id),
				KEY location_kurabu_id (location_kurabu_id),
				KEY slug (slug)
			) {$charset};",

			"CREATE TABLE {$times} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kurabu_id varchar(64) NOT NULL,
				training_kurabu_id varchar(64) NOT NULL DEFAULT '',
				weekday tinyint(1) NOT NULL DEFAULT 0,
				start_time time NULL,
				end_time time NULL,
				valid_from date NULL,
				valid_until date NULL,
				notes longtext NULL,
				payload longtext NULL,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY kurabu_id (kurabu_id),
				KEY training_kurabu_id (training_kurabu_id),
				KEY weekday (weekday)
			) {$charset};",

			"CREATE TABLE {$map} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(32) NOT NULL,
				kurabu_id varchar(64) NOT NULL,
				wp_object_type varchar(32) NOT NULL DEFAULT 'post',
				wp_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				checksum char(32) NOT NULL DEFAULT '',
				synced_at datetime NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY object_kurabu_id (object_type,kurabu_id),
				KEY wp_object (wp_object_type,wp_object_id)
			) {$charset};",

			"CREATE TABLE {$state} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				resource varchar(64) NOT NULL,
				last_attempt_at datetime NULL,
				last_success_at datetime NULL,
				last_cursor varchar(191) NOT NULL DEFAULT '',
				last_status varchar(16) NOT NULL DEFAULT '',
				items_synced int(11) NOT NULL DEFAULT 0,
				message text NULL,
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY resource (resource)
			) {$charset};",

			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(36) NOT NULL DEFAULT '',
				resource varchar(64) NOT NULL DEFAULT '',
				level varchar(16) NOT NULL DEFAULT 'info',
				message text NULL,
				context longtext NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY level_created (level,created_at),
				KEY created_at (created_at)
			) {$charset};",
		);
	}
}

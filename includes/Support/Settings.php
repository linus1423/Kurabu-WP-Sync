<?php
/**
 * Plugin settings and the allowed synchronisation intervals.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option.
 *
 * The sync engine reads the API credentials and the interval from here; the
 * admin screens write them. Nothing else should touch the raw option.
 */
final class Settings {

	public const OPTION = 'kurabu_wp_sync_settings';

	/**
	 * Constant that may override the stored API token, so installations can
	 * keep the credential in wp-config.php instead of the database.
	 */
	public const TOKEN_CONSTANT = 'KURABU_WP_SYNC_API_TOKEN';

	/**
	 * The synchronisation intervals offered in the backend, in seconds.
	 *
	 * Keys are stored in the settings; the sync engine registers matching WP
	 * cron schedules from this list.
	 *
	 * @var array<string, int>
	 */
	public const INTERVALS = array(
		'5min'  => 300,
		'15min' => 900,
		'30min' => 1800,
		'1h'    => 3600,
		'2h'    => 7200,
		'6h'    => 21600,
		'24h'   => 86400,
	);

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// API-Konfiguration.
			'api_base_url'        => '',
			'api_token'           => '',
			'auth_method'         => 'bearer',
			'auth_parameter'      => '',
			'request_timeout'     => 20,

			// Synchronisation.
			'sync_interval'       => '1h',
			'sync_enabled'        => true,
			// Literal keys, not Sync\Resource constants: the sync engine reads
			// these settings, not the other way round.
			'resources'           => array(
				'departments',
				'teams',
				'locations',
				'trainings',
				'training_times',
				'news',
				'events',
			),
			'log_retention_days'  => 30,

			// How the resource endpoints are queried.
			'incremental'         => true,
			'since_param'         => 'modified_since',
			'page_param'          => 'page',
			'per_page_param'      => 'per_page',
			'per_page'            => 100,

			// Where news are written.
			'news_post_type'      => 'post',
			'news_post_status'    => 'publish',
			'news_category'       => 0,
			'news_author'         => 0,

			// Where events are written.
			'calendar_target'     => '',
			'event_post_type'     => '',
			'event_meta_start'    => '_kurabu_event_start',
			'event_meta_end'      => '_kurabu_event_end',
			'event_meta_location' => '_kurabu_event_location',
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Returned when the key is unknown.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Writes a set of settings, keeping unknown keys out.
	 *
	 * @param array<string, mixed> $values Values to merge in.
	 */
	public static function update( array $values ): void {
		$values = array_intersect_key( $values, self::defaults() );

		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * The API token, preferring the wp-config constant when defined.
	 */
	public static function api_token(): string {
		if ( defined( self::TOKEN_CONSTANT ) ) {
			return (string) constant( self::TOKEN_CONSTANT );
		}

		return (string) self::get( 'api_token', '' );
	}

	/**
	 * The configured API base URL.
	 */
	public static function api_base_url(): string {
		return (string) self::get( 'api_base_url', '' );
	}

	/**
	 * Whether the plugin has everything it needs to talk to KURABU.
	 */
	public static function is_configured(): bool {
		return '' !== self::api_base_url() && '' !== self::api_token();
	}

	/**
	 * The configured interval key, guaranteed to be one of self::INTERVALS.
	 */
	public static function interval_key(): string {
		$key = (string) self::get( 'sync_interval', '1h' );

		return isset( self::INTERVALS[ $key ] ) ? $key : '1h';
	}

	/**
	 * The configured interval in seconds.
	 */
	public static function interval_seconds(): int {
		return self::INTERVALS[ self::interval_key() ];
	}

	/**
	 * Translated labels for the interval options.
	 *
	 * @return array<string, string>
	 */
	public static function interval_labels(): array {
		return array(
			'5min'  => __( 'Alle 5 Minuten', 'kurabu-wp-sync' ),
			'15min' => __( 'Alle 15 Minuten', 'kurabu-wp-sync' ),
			'30min' => __( 'Alle 30 Minuten', 'kurabu-wp-sync' ),
			'1h'    => __( 'Stündlich', 'kurabu-wp-sync' ),
			'2h'    => __( 'Alle 2 Stunden', 'kurabu-wp-sync' ),
			'6h'    => __( 'Alle 6 Stunden', 'kurabu-wp-sync' ),
			'24h'   => __( 'Täglich', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * Removes the stored settings.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );
	}
}

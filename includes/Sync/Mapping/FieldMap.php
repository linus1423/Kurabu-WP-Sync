<?php
/**
 * Stored overrides for endpoints and field names.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Mapping;

use Kurabu\WPSync\Sync\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves, per resource, which endpoint to call and which KURABU field feeds
 * which local field.
 *
 * The defaults come from Definition; anything set on the "Mapping" screen wins
 * over them. This is the seam that lets the sync adapt to the real KURABU API
 * without a code change.
 */
final class FieldMap {

	public const OPTION = 'kurabu_wp_sync_mapping';

	/**
	 * Cached option value.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * The stored overrides.
	 *
	 * @return array<string, array{endpoint?: string, fields?: array<string, string>}>
	 */
	public static function overrides(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	/**
	 * The endpoint path of a resource, relative to the API base URL.
	 *
	 * @param string $resource Resource key.
	 */
	public static function endpoint( string $resource ): string {
		$overrides = self::overrides();
		$stored    = isset( $overrides[ $resource ]['endpoint'] ) ? trim( (string) $overrides[ $resource ]['endpoint'] ) : '';

		if ( '' !== $stored ) {
			return $stored;
		}

		return Definition::endpoints()[ $resource ] ?? $resource;
	}

	/**
	 * The KURABU field names to try for one local field, in order.
	 *
	 * A name pinned in the backend is the only one tried; otherwise the
	 * candidates from Definition are used.
	 *
	 * @param string $resource Resource key.
	 * @param string $field    Local field name.
	 *
	 * @return string[]
	 */
	public static function sources( string $resource, string $field ): array {
		$overrides = self::overrides();
		$pinned    = isset( $overrides[ $resource ]['fields'][ $field ] )
			? trim( (string) $overrides[ $resource ]['fields'][ $field ] )
			: '';

		if ( '' !== $pinned ) {
			return array( $pinned );
		}

		$fields = Definition::fields( $resource );

		return $fields[ $field ]['candidates'] ?? array();
	}

	/**
	 * The name pinned in the backend for one field, or an empty string.
	 *
	 * @param string $resource Resource key.
	 * @param string $field    Local field name.
	 */
	public static function pinned( string $resource, string $field ): string {
		$overrides = self::overrides();

		return isset( $overrides[ $resource ]['fields'][ $field ] )
			? trim( (string) $overrides[ $resource ]['fields'][ $field ] )
			: '';
	}

	/**
	 * Stores the overrides of one resource.
	 *
	 * An empty endpoint or field name removes the override, so the definition
	 * default applies again.
	 *
	 * @param string                $resource Resource key.
	 * @param string                $endpoint Endpoint path.
	 * @param array<string, string> $fields   Local field => KURABU field name.
	 */
	public static function save( string $resource, string $endpoint, array $fields ): void {
		if ( ! Resource::is_valid( $resource ) ) {
			return;
		}

		$stored   = self::overrides();
		$endpoint = trim( $endpoint );
		$known    = Definition::fields( $resource );
		$clean    = array();

		foreach ( $fields as $field => $source ) {
			$source = trim( (string) $source );

			if ( '' === $source || ! isset( $known[ (string) $field ] ) ) {
				continue;
			}

			$clean[ (string) $field ] = $source;
		}

		$entry = array();

		if ( '' !== $endpoint ) {
			$entry['endpoint'] = $endpoint;
		}

		if ( $clean ) {
			$entry['fields'] = $clean;
		}

		if ( $entry ) {
			$stored[ $resource ] = $entry;
		} else {
			unset( $stored[ $resource ] );
		}

		update_option( self::OPTION, $stored );

		self::$cache = $stored;
	}

	/**
	 * Drops the stored overrides of one resource.
	 *
	 * @param string $resource Resource key.
	 */
	public static function reset( string $resource ): void {
		self::save( $resource, '', array() );
	}

	/**
	 * Removes every stored override.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );

		self::$cache = array();
	}

	/**
	 * Forgets the cached option value.
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}

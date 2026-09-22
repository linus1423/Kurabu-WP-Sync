<?php
/**
 * Builds the configured authentication scheme.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the `auth_method` setting onto an Authenticator.
 *
 * KURABU's scheme is a backend setting rather than a constant, so an
 * installation can be corrected without a plugin update.
 */
final class AuthenticatorFactory {

	/**
	 * The selectable schemes with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'bearer' => __( 'Bearer-Token (Authorization: Bearer …)', 'kurabu-wp-sync' ),
			'header' => __( 'API-Key im Header', 'kurabu-wp-sync' ),
			'query'  => __( 'API-Key als Query-Parameter', 'kurabu-wp-sync' ),
			'basic'  => __( 'HTTP Basic Auth', 'kurabu-wp-sync' ),
			'none'   => __( 'Keine Authentifizierung', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * Whether a scheme key is known.
	 *
	 * @param string $method Scheme key.
	 */
	public static function is_valid( string $method ): bool {
		return isset( self::labels()[ $method ] );
	}

	/**
	 * Builds the scheme configured in the settings.
	 */
	public static function from_settings(): Authenticator {
		return self::make(
			(string) Settings::get( 'auth_method', 'bearer' ),
			(string) Settings::get( 'auth_parameter', '' )
		);
	}

	/**
	 * Builds a scheme.
	 *
	 * @param string $method    Scheme key.
	 * @param string $parameter Header or query parameter name, where it applies.
	 */
	public static function make( string $method, string $parameter = '' ): Authenticator {
		switch ( $method ) {
			case 'header':
				return new HeaderAuthenticator( '' !== $parameter ? $parameter : 'X-API-Key' );
			case 'query':
				return new QueryAuthenticator( '' !== $parameter ? $parameter : 'api_key' );
			case 'basic':
				return new BasicAuthenticator();
			case 'none':
				return new NullAuthenticator();
			case 'bearer':
			default:
				return new BearerAuthenticator();
		}
	}
}

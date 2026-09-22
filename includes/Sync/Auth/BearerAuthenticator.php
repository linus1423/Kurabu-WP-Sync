<?php
/**
 * Bearer token authentication.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the token as `Authorization: Bearer <token>`.
 */
final class BearerAuthenticator implements Authenticator {

	public function key(): string {
		return 'bearer';
	}

	/**
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $token ): array {
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	/**
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function query_args( string $token ): array {
		return array();
	}
}

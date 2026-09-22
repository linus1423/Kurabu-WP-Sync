<?php
/**
 * No authentication.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Sends no credential at all, for a public endpoint.
 */
final class NullAuthenticator implements Authenticator {

	public function key(): string {
		return 'none';
	}

	/**
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $token ): array {
		return array();
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

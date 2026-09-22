<?php
/**
 * HTTP basic authentication.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the token as HTTP basic credentials.
 *
 * A token containing a colon is split into user and password, so a
 * `user:password` credential can be pasted into the token field unchanged.
 */
final class BasicAuthenticator implements Authenticator {

	public function key(): string {
		return 'basic';
	}

	/**
	 * @param string $token The API token, optionally `user:password`.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $token ): array {
		$credentials = false !== strpos( $token, ':' ) ? $token : $token . ':';

		return array( 'Authorization' => 'Basic ' . base64_encode( $credentials ) );
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

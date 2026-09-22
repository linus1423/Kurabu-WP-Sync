<?php
/**
 * Query parameter authentication.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Appends the token as a query parameter, `api_key` by default.
 */
final class QueryAuthenticator implements Authenticator {

	/**
	 * Query parameter the token is sent in.
	 */
	private string $parameter;

	/**
	 * @param string $parameter Parameter name.
	 */
	public function __construct( string $parameter = 'api_key' ) {
		$parameter       = trim( $parameter );
		$this->parameter = '' !== $parameter ? $parameter : 'api_key';
	}

	public function key(): string {
		return 'query';
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
		return array( $this->parameter => $token );
	}
}

<?php
/**
 * API key header authentication.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the token in a named header, `X-API-Key` by default.
 */
final class HeaderAuthenticator implements Authenticator {

	/**
	 * Header the token is sent in.
	 */
	private string $header;

	/**
	 * @param string $header Header name.
	 */
	public function __construct( string $header = 'X-API-Key' ) {
		$header       = trim( $header );
		$this->header = '' !== $header ? $header : 'X-API-Key';
	}

	public function key(): string {
		return 'header';
	}

	/**
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $token ): array {
		return array( $this->header => $token );
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

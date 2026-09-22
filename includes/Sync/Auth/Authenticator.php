<?php
/**
 * Authentication contract for KURABU API requests.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the credential on an outgoing request.
 *
 * Which scheme KURABU expects is a backend setting, because the token can sit
 * in a header, in the Authorization header or in a query parameter depending
 * on the API. Each scheme is a small class, so switching is a setting change
 * rather than a code change.
 */
interface Authenticator {

	/**
	 * The setting value this scheme is selected by.
	 */
	public function key(): string;

	/**
	 * Returns the headers to add to a request.
	 *
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $token ): array;

	/**
	 * Returns the query arguments to add to a request URL.
	 *
	 * @param string $token The API token.
	 *
	 * @return array<string, string>
	 */
	public function query_args( string $token ): array;
}

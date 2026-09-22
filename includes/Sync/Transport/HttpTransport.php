<?php
/**
 * WordPress HTTP API transport.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Transport;

use Kurabu\WPSync\Sync\ApiException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests through wp_remote_request(), with a bounded retry.
 *
 * A transport level failure (timeout, DNS, reset) is retried, because the run
 * that follows would otherwise mark the resource as failed over a hiccup. A
 * 4xx or 5xx answer is not retried here; the client decides what it means.
 */
final class HttpTransport implements Transport {

	/**
	 * Request timeout in seconds.
	 */
	private int $timeout;

	/**
	 * How often a transport error is retried.
	 */
	private int $retries;

	/**
	 * @param int $timeout Request timeout in seconds.
	 * @param int $retries Number of retries after a transport error.
	 */
	public function __construct( int $timeout = 20, int $retries = 2 ) {
		$this->timeout = max( 5, $timeout );
		$this->retries = max( 0, $retries );
	}

	/**
	 * Performs a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Request body.
	 *
	 * @throws ApiException When every attempt failed on the transport level.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): Response {
		$args = array(
			'method'     => strtoupper( $method ),
			'timeout'    => $this->timeout,
			'headers'    => $headers,
			'user-agent' => 'KURABU-WP-Sync/' . ( defined( 'KURABU_WP_SYNC_VERSION' ) ? KURABU_WP_SYNC_VERSION : '0' ) . '; ' . home_url(),
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		/**
		 * Filters the request arguments handed to wp_remote_request().
		 *
		 * @param array<string, mixed> $args Request arguments.
		 * @param string               $url  Request URL.
		 */
		$args = (array) apply_filters( 'kurabu_wp_sync_request_args', $args, $url );

		$last_error = null;

		for ( $attempt = 0; $attempt <= $this->retries; $attempt++ ) {
			if ( $attempt > 0 ) {
				// Back off a little before trying again: 1s, then 2s.
				sleep( min( 4, $attempt ) );
			}

			$response = wp_remote_request( $url, $args );

			if ( ! $response instanceof WP_Error ) {
				return new Response(
					(int) wp_remote_retrieve_response_code( $response ),
					$this->normalise_headers( wp_remote_retrieve_headers( $response ) ),
					(string) wp_remote_retrieve_body( $response )
				);
			}

			$last_error = $response;
		}

		throw new ApiException(
			null !== $last_error ? $last_error->get_error_message() : __( 'Die KURABU-API ist nicht erreichbar.', 'kurabu-wp-sync' ),
			0,
			$url
		);
	}

	/**
	 * Turns the header object wp_remote_retrieve_headers() returns into an array.
	 *
	 * @param mixed $headers Requests_Utility_CaseInsensitiveDictionary or array.
	 *
	 * @return array<string, string>
	 */
	private function normalise_headers( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return array();
		}

		$normalised = array();

		foreach ( $headers as $name => $value ) {
			$normalised[ (string) $name ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return $normalised;
	}
}

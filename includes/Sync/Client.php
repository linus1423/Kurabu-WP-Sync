<?php
/**
 * The KURABU API client.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Auth\Authenticator;
use Kurabu\WPSync\Sync\Auth\AuthenticatorFactory;
use Kurabu\WPSync\Sync\Mapping\FieldMap;
use Kurabu\WPSync\Sync\Transport\HttpTransport;
use Kurabu\WPSync\Sync\Transport\Response;
use Kurabu\WPSync\Sync\Transport\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Reads lists of records out of the KURABU API.
 *
 * The client knows about authentication, paging and the envelope a response
 * comes in; it knows nothing about the local database. It hands raw records to
 * the handlers, which map and store them.
 *
 * Endpoint paths, the paging parameter names and the "changed since" parameter
 * are configuration, not constants: the KURABU documentation is not available
 * yet, so all of it is adjustable in the backend.
 */
final class Client {

	/**
	 * Hard stop for the paging loop, so a misread response cannot spin.
	 */
	private const MAX_PAGES = 200;

	/**
	 * Keys a list of records may hide behind.
	 */
	private const ENVELOPE_KEYS = array( 'data', 'items', 'results', 'records', 'entries', 'content', 'elements' );

	/**
	 * How the request is authenticated.
	 */
	private Transport $transport;

	/**
	 * How the credential is attached.
	 */
	private Authenticator $auth;

	/**
	 * @param Transport|null     $transport Transport, defaults to HTTP.
	 * @param Authenticator|null $auth      Auth scheme, defaults to the setting.
	 */
	public function __construct( ?Transport $transport = null, ?Authenticator $auth = null ) {
		$this->transport = $transport ?? new HttpTransport( (int) Settings::get( 'request_timeout', 20 ) );
		$this->auth      = $auth ?? AuthenticatorFactory::from_settings();
	}

	/**
	 * Whether base URL and token are present.
	 */
	public function is_configured(): bool {
		return Settings::is_configured();
	}

	/**
	 * Fetches every record of a resource, following the paging.
	 *
	 * @param string      $resource Resource key.
	 * @param string|null $since    Only records changed since this MySQL
	 *                              datetime, when the API supports it.
	 *
	 * @return array<int, array<string|int, mixed>> Raw KURABU records.
	 *
	 * @throws ApiException When the API cannot be reached or answers with a fault.
	 */
	public function fetch( string $resource, ?string $since = null ): array {
		$url      = $this->endpoint_url( $resource, $since, 1 );
		$per_page = $this->per_page();
		$records  = array();
		$seen     = array();

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$response = $this->get( $url );
			$decoded  = $response->json();

			if ( null === $decoded ) {
				throw new ApiException(
					sprintf(
						/* translators: %s: resource label. */
						__( 'Die Antwort für %s ist kein gültiges JSON.', 'kurabu-wp-sync' ),
						Resource::label( $resource )
					),
					$response->status(),
					$url,
					$response->body_excerpt()
				);
			}

			$items = $this->extract_items( $decoded );

			if ( ! $items ) {
				break;
			}

			// An API that ignores the paging parameters would answer the same
			// page forever; the fingerprint of a page stops that.
			$fingerprint = md5( (string) wp_json_encode( $items ) );

			if ( isset( $seen[ $fingerprint ] ) ) {
				break;
			}

			$seen[ $fingerprint ] = true;
			$records              = array_merge( $records, $items );

			$next = $this->next_url( $decoded );

			if ( '' !== $next ) {
				$url = $next;
				continue;
			}

			if ( count( $items ) < $per_page ) {
				break;
			}

			$url = $this->endpoint_url( $resource, $since, $page + 1 );
		}

		return $records;
	}

	/**
	 * Calls one resource endpoint and reports what came back.
	 *
	 * Used by the "Verbindung testen" button: it answers whether the
	 * credentials work and whether the endpoint returns a list at all.
	 *
	 * @param string $resource Resource key.
	 *
	 * @return array{status: int, count: int, keys: string[], url: string}
	 *
	 * @throws ApiException When the API cannot be reached or answers with a fault.
	 */
	public function probe( string $resource ): array {
		$url      = $this->endpoint_url( $resource, null, 1 );
		$response = $this->get( $url );
		$decoded  = $response->json();

		if ( null === $decoded ) {
			throw new ApiException(
				__( 'Die Antwort ist kein gültiges JSON.', 'kurabu-wp-sync' ),
				$response->status(),
				$url,
				$response->body_excerpt()
			);
		}

		$items = $this->extract_items( $decoded );
		$first = $items[0] ?? array();

		return array(
			'status' => $response->status(),
			'count'  => count( $items ),
			'keys'   => is_array( $first ) ? array_map( 'strval', array_keys( $first ) ) : array(),
			'url'    => $url,
		);
	}

	/**
	 * Performs a GET and turns a non-2xx answer into an exception.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @throws ApiException When the answer is not a 2xx.
	 */
	private function get( string $url ): Response {
		$token   = Settings::api_token();
		$headers = array_merge(
			array( 'Accept' => 'application/json' ),
			$this->auth->headers( $token )
		);

		$response = $this->transport->request( 'GET', $url, $headers );

		if ( $response->is_success() ) {
			return $response;
		}

		throw new ApiException(
			$this->status_message( $response->status() ),
			$response->status(),
			$url,
			$response->body_excerpt()
		);
	}

	/**
	 * A readable message for an HTTP status code.
	 *
	 * @param int $status HTTP status code.
	 */
	private function status_message( int $status ): string {
		switch ( true ) {
			case 401 === $status:
			case 403 === $status:
				return __( 'Die KURABU-API hat die Anmeldung abgelehnt. Bitte Token und Auth-Methode prüfen.', 'kurabu-wp-sync' );
			case 404 === $status:
				return __( 'Der Endpunkt existiert nicht. Bitte den Pfad unter "Mapping" prüfen.', 'kurabu-wp-sync' );
			case 429 === $status:
				return __( 'Die KURABU-API hat wegen zu vieler Anfragen abgewiesen.', 'kurabu-wp-sync' );
			case $status >= 500:
				return sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Die KURABU-API meldet einen Serverfehler (HTTP %d).', 'kurabu-wp-sync' ),
					$status
				);
			default:
				return sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Die KURABU-API antwortet mit HTTP %d.', 'kurabu-wp-sync' ),
					$status
				);
		}
	}

	/**
	 * Builds the URL of one resource page.
	 *
	 * @param string      $resource Resource key.
	 * @param string|null $since    MySQL datetime for the incremental filter.
	 * @param int         $page     Page number, starting at 1.
	 */
	public function endpoint_url( string $resource, ?string $since, int $page ): string {
		$args = $this->auth->query_args( Settings::api_token() );

		$per_page_param = trim( (string) Settings::get( 'per_page_param', 'per_page' ) );
		$page_param     = trim( (string) Settings::get( 'page_param', 'page' ) );
		$since_param    = trim( (string) Settings::get( 'since_param', 'modified_since' ) );

		if ( '' !== $per_page_param ) {
			$args[ $per_page_param ] = (string) $this->per_page();
		}

		if ( '' !== $page_param && $page > 1 ) {
			$args[ $page_param ] = (string) $page;
		}

		if ( null !== $since && '' !== $since_param ) {
			$args[ $since_param ] = $this->to_iso8601( $since );
		}

		/**
		 * Filters the query arguments of a resource request.
		 *
		 * @param array<string, string> $args     Query arguments.
		 * @param string                $resource Resource key.
		 * @param string|null           $since    Incremental cut-off.
		 */
		$args = (array) apply_filters( 'kurabu_wp_sync_query_args', $args, $resource, $since );

		$url = $this->base_url() . '/' . ltrim( FieldMap::endpoint( $resource ), '/' );

		// add_query_arg() encodes the values itself.
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * The API base URL without a trailing slash.
	 */
	private function base_url(): string {
		return rtrim( Settings::api_base_url(), '/' );
	}

	/**
	 * How many records are requested per page.
	 */
	private function per_page(): int {
		return max( 1, min( 500, (int) Settings::get( 'per_page', 100 ) ) );
	}

	/**
	 * Converts a MySQL datetime in site local time to ISO 8601.
	 *
	 * @param string $mysql_datetime Datetime as stored by SyncState.
	 */
	private function to_iso8601( string $mysql_datetime ): string {
		$timestamp = strtotime( $mysql_datetime . ' ' . wp_timezone()->getName() );

		if ( false === $timestamp ) {
			$timestamp = time();
		}

		return gmdate( 'c', $timestamp );
	}

	/**
	 * Digs the list of records out of a decoded response.
	 *
	 * A bare JSON array is the list itself; otherwise the usual envelope keys
	 * are tried, one level deep as well (`data.items`).
	 *
	 * @param array<int|string, mixed> $decoded Decoded response body.
	 *
	 * @return array<int, array<string|int, mixed>>
	 */
	private function extract_items( array $decoded ): array {
		if ( $this->is_record_list( $decoded ) ) {
			return array_values( array_filter( $decoded, 'is_array' ) );
		}

		foreach ( self::ENVELOPE_KEYS as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				continue;
			}

			$candidate = $decoded[ $key ];

			if ( $this->is_record_list( $candidate ) ) {
				return array_values( array_filter( $candidate, 'is_array' ) );
			}

			foreach ( self::ENVELOPE_KEYS as $nested ) {
				if ( isset( $candidate[ $nested ] ) && is_array( $candidate[ $nested ] ) && $this->is_record_list( $candidate[ $nested ] ) ) {
					return array_values( array_filter( $candidate[ $nested ], 'is_array' ) );
				}
			}
		}

		// A single record answered instead of a list.
		if ( isset( $decoded['id'] ) || isset( $decoded['uuid'] ) ) {
			return array( $decoded );
		}

		return array();
	}

	/**
	 * Whether a value looks like a list of records.
	 *
	 * @param array<int|string, mixed> $value Candidate value.
	 */
	private function is_record_list( array $value ): bool {
		if ( ! $value ) {
			return false;
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}

		return is_array( reset( $value ) );
	}

	/**
	 * Reads the URL of the next page out of a decoded response.
	 *
	 * @param array<int|string, mixed> $decoded Decoded response body.
	 */
	private function next_url( array $decoded ): string {
		$paths = array(
			array( 'links', 'next' ),
			array( '_links', 'next', 'href' ),
			array( 'meta', 'next' ),
			array( 'meta', 'next_page_url' ),
			array( 'next' ),
			array( 'next_page_url' ),
		);

		foreach ( $paths as $path ) {
			$value = $decoded;

			foreach ( $path as $segment ) {
				if ( ! is_array( $value ) || ! isset( $value[ $segment ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $segment ];
			}

			if ( is_string( $value ) && '' !== trim( $value ) && 0 === strpos( $value, $this->base_url() ) ) {
				return $value;
			}
		}

		return '';
	}
}

<?php
/**
 * One answer from the KURABU API.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Value object around a single HTTP response.
 *
 * Keeping this separate from the client means the client never touches
 * WordPress' HTTP functions directly, so the transport can be swapped once the
 * real KURABU endpoints are known.
 */
final class Response {

	/**
	 * HTTP status code.
	 */
	private int $status;

	/**
	 * Response headers, keys lowercased.
	 *
	 * @var array<string, string>
	 */
	private array $headers;

	/**
	 * Raw response body.
	 */
	private string $body;

	/**
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Response headers.
	 * @param string                $body    Raw body.
	 */
	public function __construct( int $status, array $headers, string $body ) {
		$this->status  = $status;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}

	/**
	 * The HTTP status code.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Whether the status code is in the 2xx range.
	 */
	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * A single response header.
	 *
	 * @param string $name    Header name, case insensitive.
	 * @param string $default Returned when the header is absent.
	 */
	public function header( string $name, string $default = '' ): string {
		return $this->headers[ strtolower( $name ) ] ?? $default;
	}

	/**
	 * The raw response body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * The first characters of the body, for log context.
	 *
	 * @param int $length Maximum length.
	 */
	public function body_excerpt( int $length = 500 ): string {
		return trim( mb_substr( $this->body, 0, $length ) );
	}

	/**
	 * The decoded JSON body, or null when the body is not valid JSON.
	 *
	 * @return array<int|string, mixed>|null
	 */
	public function json(): ?array {
		if ( '' === trim( $this->body ) ) {
			return array();
		}

		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}

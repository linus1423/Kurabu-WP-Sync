<?php
/**
 * Error raised when the KURABU API cannot be reached or answers with a fault.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Signals a failed API call.
 *
 * The sync engine catches this per resource: the failing resource keeps its
 * last successful data (SyncState::mark_error() leaves `last_success_at`
 * untouched) while the remaining resources still sync.
 */
final class ApiException extends RuntimeException {

	/**
	 * HTTP status code, or 0 when the request never got an answer.
	 */
	private int $status;

	/**
	 * The request URL, without the credentials.
	 */
	private string $url;

	/**
	 * Start of the response body, for the log context.
	 */
	private string $body_excerpt;

	/**
	 * @param string $message      Human readable message.
	 * @param int    $status       HTTP status code.
	 * @param string $url          Request URL.
	 * @param string $body_excerpt Start of the response body.
	 */
	public function __construct( string $message, int $status = 0, string $url = '', string $body_excerpt = '' ) {
		parent::__construct( $message, $status );

		$this->status       = $status;
		$this->url          = $url;
		$this->body_excerpt = $body_excerpt;
	}

	/**
	 * The HTTP status code, or 0 when there was no answer.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * The request URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Data for the log context.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		$context = array( 'url' => $this->url );

		if ( 0 !== $this->status ) {
			$context['status'] = $this->status;
		}

		if ( '' !== $this->body_excerpt ) {
			$context['response'] = $this->body_excerpt;
		}

		return $context;
	}
}

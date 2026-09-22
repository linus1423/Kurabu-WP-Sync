<?php
/**
 * Outcome of syncing one resource.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * What one resource did during a run, for the log and the backend.
 */
final class ResourceResult {

	/**
	 * Resource key.
	 */
	private string $resource;

	/**
	 * Whether the resource synced without an error.
	 */
	private bool $success;

	/**
	 * Records the API delivered.
	 */
	private int $fetched;

	/**
	 * Records written to the local cache or to WordPress.
	 */
	private int $written;

	/**
	 * Records dropped because KURABU no longer lists them.
	 */
	private int $removed;

	/**
	 * Records the API delivered but that could not be used.
	 */
	private int $skipped;

	/**
	 * Whether this was an incremental run.
	 */
	private bool $incremental;

	/**
	 * Error or status message.
	 */
	private string $message;

	/**
	 * @param string $resource    Resource key.
	 * @param bool   $success     Whether the run succeeded.
	 * @param int    $fetched     Records delivered.
	 * @param int    $written     Records written.
	 * @param int    $removed     Records removed.
	 * @param int    $skipped     Records skipped.
	 * @param bool   $incremental Whether only changes were requested.
	 * @param string $message     Message.
	 */
	private function __construct(
		string $resource,
		bool $success,
		int $fetched = 0,
		int $written = 0,
		int $removed = 0,
		int $skipped = 0,
		bool $incremental = false,
		string $message = ''
	) {
		$this->resource    = $resource;
		$this->success     = $success;
		$this->fetched     = $fetched;
		$this->written     = $written;
		$this->removed     = $removed;
		$this->skipped     = $skipped;
		$this->incremental = $incremental;
		$this->message     = $message;
	}

	/**
	 * A successful resource run.
	 *
	 * @param string $resource    Resource key.
	 * @param int    $fetched     Records delivered.
	 * @param int    $written     Records written.
	 * @param int    $removed     Records removed.
	 * @param int    $skipped     Records skipped.
	 * @param bool   $incremental Whether only changes were requested.
	 */
	public static function success(
		string $resource,
		int $fetched,
		int $written,
		int $removed = 0,
		int $skipped = 0,
		bool $incremental = false
	): self {
		return new self( $resource, true, $fetched, $written, $removed, $skipped, $incremental );
	}

	/**
	 * A failed resource run.
	 *
	 * @param string $resource Resource key.
	 * @param string $message  Error message.
	 */
	public static function failure( string $resource, string $message ): self {
		return new self( $resource, false, 0, 0, 0, 0, false, $message );
	}

	/**
	 * A resource that was not attempted.
	 *
	 * @param string $resource Resource key.
	 * @param string $message  Why it was skipped.
	 */
	public static function skipped_resource( string $resource, string $message ): self {
		return new self( $resource, true, 0, 0, 0, 0, false, $message );
	}

	public function resource(): string {
		return $this->resource;
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function fetched(): int {
		return $this->fetched;
	}

	public function written(): int {
		return $this->written;
	}

	public function removed(): int {
		return $this->removed;
	}

	public function skipped(): int {
		return $this->skipped;
	}

	public function is_incremental(): bool {
		return $this->incremental;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * A one line summary for the log and the backend.
	 */
	public function summary(): string {
		if ( ! $this->success ) {
			return $this->message;
		}

		if ( '' !== $this->message && 0 === $this->fetched ) {
			return $this->message;
		}

		$summary = sprintf(
			/* translators: 1: number of records delivered, 2: number written. */
			__( '%1$d Datensätze geladen, %2$d gespeichert', 'kurabu-wp-sync' ),
			$this->fetched,
			$this->written
		);

		if ( $this->removed > 0 ) {
			$summary .= sprintf(
				/* translators: %d: number of removed records. */
				__( ', %d entfernt', 'kurabu-wp-sync' ),
				$this->removed
			);
		}

		if ( $this->skipped > 0 ) {
			$summary .= sprintf(
				/* translators: %d: number of skipped records. */
				__( ', %d übersprungen', 'kurabu-wp-sync' ),
				$this->skipped
			);
		}

		if ( $this->incremental ) {
			$summary .= __( ' (nur Änderungen)', 'kurabu-wp-sync' );
		}

		return $summary;
	}
}

<?php
/**
 * Outcome of one complete sync run.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the per-resource results of a run.
 *
 * The engine hands this back to whoever started the run: the manual sync
 * screen prints it, the cron run logs it.
 */
final class RunReport {

	public const MODE_CRON   = 'cron';
	public const MODE_MANUAL = 'manual';

	/**
	 * Option the last report is remembered in, for the status screen.
	 */
	public const OPTION = 'kurabu_wp_sync_last_run';

	/**
	 * Id shared by every log entry of this run.
	 */
	private string $run_id;

	/**
	 * How the run was started.
	 */
	private string $mode;

	/**
	 * Unix timestamp the run started at.
	 */
	private int $started_at;

	/**
	 * Results, keyed by resource.
	 *
	 * @var array<string, ResourceResult>
	 */
	private array $results = array();

	/**
	 * Why the run did not do anything, when that is the case.
	 */
	private string $note = '';

	/**
	 * @param string $run_id Run id.
	 * @param string $mode   MODE_CRON or MODE_MANUAL.
	 */
	public function __construct( string $run_id, string $mode = self::MODE_CRON ) {
		$this->run_id     = $run_id;
		$this->mode       = self::MODE_MANUAL === $mode ? self::MODE_MANUAL : self::MODE_CRON;
		$this->started_at = time();
	}

	/**
	 * Adds the result of one resource.
	 *
	 * @param ResourceResult $result Result.
	 */
	public function add( ResourceResult $result ): void {
		$this->results[ $result->resource() ] = $result;
	}

	/**
	 * Records why the run did not do any work.
	 *
	 * @param string $note Message for the backend and the log.
	 */
	public function set_note( string $note ): void {
		$this->note = $note;
	}

	/**
	 * The note, or an empty string.
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * The results, keyed by resource.
	 *
	 * @return array<string, ResourceResult>
	 */
	public function results(): array {
		return $this->results;
	}

	public function run_id(): string {
		return $this->run_id;
	}

	public function mode(): string {
		return $this->mode;
	}

	/**
	 * How long the run took, in seconds.
	 */
	public function duration(): int {
		return max( 0, time() - $this->started_at );
	}

	/**
	 * Whether at least one resource failed.
	 */
	public function has_errors(): bool {
		foreach ( $this->results as $result ) {
			if ( ! $result->is_success() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Total number of written records.
	 */
	public function written(): int {
		$written = 0;

		foreach ( $this->results as $result ) {
			$written += $result->written();
		}

		return $written;
	}

	/**
	 * A one line summary of the whole run.
	 */
	public function summary(): string {
		if ( ! $this->results ) {
			return '' !== $this->note
				? $this->note
				: __( 'Es wurde keine Datenart synchronisiert.', 'kurabu-wp-sync' );
		}

		$failed = 0;

		foreach ( $this->results as $result ) {
			if ( ! $result->is_success() ) {
				$failed++;
			}
		}

		if ( 0 === $failed ) {
			return sprintf(
				/* translators: 1: number of resources, 2: number of records. */
				__( '%1$d Datenarten synchronisiert, %2$d Datensätze gespeichert.', 'kurabu-wp-sync' ),
				count( $this->results ),
				$this->written()
			);
		}

		return sprintf(
			/* translators: 1: number of failed resources, 2: number of resources. */
			__( '%1$d von %2$d Datenarten konnten nicht synchronisiert werden. Der letzte erfolgreiche Datenbestand bleibt erhalten.', 'kurabu-wp-sync' ),
			$failed,
			count( $this->results )
		);
	}

	/**
	 * Remembers this report for the status screen.
	 */
	public function remember(): void {
		update_option( self::OPTION, $this->to_array(), false );
	}

	/**
	 * The remembered report of the previous run, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * The report as a plain array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$resources = array();

		foreach ( $this->results as $resource => $result ) {
			$resources[ $resource ] = array(
				'success' => $result->is_success(),
				'summary' => $result->summary(),
			);
		}

		return array(
			'run_id'     => $this->run_id,
			'mode'       => $this->mode,
			'started_at' => $this->started_at,
			'duration'   => $this->duration(),
			'summary'    => $this->summary(),
			'errors'     => $this->has_errors() || ( ! $this->results && '' !== $this->note ),
			'note'       => $this->note,
			'resources'  => $resources,
		);
	}
}

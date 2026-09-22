<?php
/**
 * The synchronisation engine.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

use Kurabu\WPSync\Database\SyncState;
use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Handler\HandlerRegistry;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Drives one run: which resources, incremental or complete, and what to do
 * when a resource fails.
 *
 * A failure stays local to its resource. SyncState::mark_error() leaves
 * `last_success_at` untouched, so the next run asks for the same window again
 * and the cache keeps the data of the last successful run — which is what the
 * specification asks for when the API is unavailable.
 */
final class Engine {

	/**
	 * Option remembering when each resource was last synced completely.
	 */
	public const FULL_SYNC_OPTION = 'kurabu_wp_sync_last_full';

	/**
	 * Shared instance.
	 */
	private static ?Engine $instance = null;

	/**
	 * The API client.
	 */
	private ?Client $client;

	/**
	 * @param Client|null $client Client, created on first use when omitted.
	 */
	public function __construct( ?Client $client = null ) {
		$this->client = $client;
	}

	/**
	 * Hooks the engine into the plugin's lifecycle.
	 *
	 * Called from the plugin bootstrap before the container boots, so the
	 * engine is listening when `kurabu_wp_sync_booted` fires.
	 */
	public static function bootstrap(): void {
		add_action(
			'kurabu_wp_sync_booted',
			static function (): void {
				self::instance()->register();
			}
		);
	}

	/**
	 * The shared instance.
	 */
	public static function instance(): Engine {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the cron schedules and the cron handler.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( Scheduler::class, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- the interval is a documented setting.

		add_action( Plugin::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'kurabu_wp_sync_activated', array( Scheduler::class, 'reschedule' ) );
		add_action( 'kurabu_wp_sync_settings_saved', array( Scheduler::class, 'reschedule' ) );
	}

	/**
	 * The cron entry point.
	 */
	public function run_scheduled(): void {
		$this->run( array(), RunReport::MODE_CRON );
	}

	/**
	 * Runs a synchronisation.
	 *
	 * @param string[] $resources Resources to sync; empty means the ones
	 *                            enabled in the settings.
	 * @param string   $mode      RunReport::MODE_CRON or MODE_MANUAL.
	 * @param bool     $force_full Ignore the incremental cut-off.
	 */
	public function run( array $resources = array(), string $mode = RunReport::MODE_CRON, bool $force_full = false ): RunReport {
		$logger = new Logger();
		$report = new RunReport( $logger->run_id(), $mode );

		$resources = $resources ? Resource::filter( $resources ) : self::enabled_resources();

		if ( ! $resources ) {
			$report->set_note( __( 'Es ist keine Datenart zur Synchronisation ausgewählt.', 'kurabu-wp-sync' ) );

			return $report;
		}

		if ( ! $this->client()->is_configured() ) {
			$message = __( 'API-Basis-URL oder Token fehlen; es wurde nicht synchronisiert.', 'kurabu-wp-sync' );

			$logger->error( $message );
			$report->set_note( $message );
			$report->remember();

			return $report;
		}

		if ( ! Lock::acquire() ) {
			$message = __( 'Es läuft bereits eine Synchronisation.', 'kurabu-wp-sync' );

			$logger->warning( $message );
			$report->set_note( $message );

			return $report;
		}

		$logger->info(
			sprintf(
				/* translators: %s: comma separated resource keys. */
				__( 'Synchronisation gestartet: %s', 'kurabu-wp-sync' ),
				implode( ', ', $resources )
			),
			'',
			array( 'mode' => $mode )
		);

		try {
			foreach ( $resources as $resource ) {
				$report->add( $this->sync_resource( $resource, $logger, $force_full ) );
			}
		} finally {
			Lock::release();
		}

		$logger->info( $report->summary(), '', array( 'duration' => $report->duration() ) );

		$report->remember();
		$this->purge_log();

		/**
		 * Fires after a run finished.
		 *
		 * @param RunReport $report The run report.
		 */
		do_action( 'kurabu_wp_sync_run_finished', $report );

		return $report;
	}

	/**
	 * Syncs one resource, turning any failure into a result.
	 *
	 * @param string $resource   Resource key.
	 * @param Logger $logger     Logger of the current run.
	 * @param bool   $force_full Ignore the incremental cut-off.
	 */
	private function sync_resource( string $resource, Logger $logger, bool $force_full ): ResourceResult {
		$handler = HandlerRegistry::get( $resource );

		if ( null === $handler ) {
			return ResourceResult::skipped_resource(
				$resource,
				__( 'Für diese Datenart ist kein Handler registriert.', 'kurabu-wp-sync' )
			);
		}

		$since = $force_full ? null : $this->since( $resource );

		SyncState::mark_attempt( $resource );

		try {
			$result = $handler->sync( $this->client(), $logger, $since );
		} catch ( ApiException $exception ) {
			SyncState::mark_error( $resource, $exception->getMessage() );
			$logger->error( $exception->getMessage(), $resource, $exception->context() );

			return ResourceResult::failure( $resource, $exception->getMessage() );
		} catch ( Throwable $throwable ) {
			// A bug in one handler must not take the whole run down.
			$message = sprintf(
				/* translators: %s: error message. */
				__( 'Unerwarteter Fehler: %s', 'kurabu-wp-sync' ),
				$throwable->getMessage()
			);

			SyncState::mark_error( $resource, $message );
			$logger->error( $message, $resource, array( 'exception' => get_class( $throwable ) ) );

			return ResourceResult::failure( $resource, $message );
		}

		SyncState::mark_success( $resource, $result->written() );

		if ( ! $result->is_incremental() ) {
			$this->remember_full_sync( $resource );
		}

		$logger->info( $result->summary(), $resource );

		return $result;
	}

	/**
	 * The incremental cut-off of a resource, or null for a complete run.
	 *
	 * A complete run is forced when the resource was never synced, when the
	 * incremental mode is switched off, and at least once a day: only a
	 * complete run sees the full list and can therefore notice records that
	 * disappeared from KURABU.
	 *
	 * @param string $resource Resource key.
	 */
	private function since( string $resource ): ?string {
		if ( ! Settings::get( 'incremental', true ) ) {
			return null;
		}

		if ( '' === trim( (string) Settings::get( 'since_param', '' ) ) ) {
			return null;
		}

		$last_success = SyncState::last_success( $resource );

		if ( null === $last_success ) {
			return null;
		}

		/**
		 * Filters how long incremental runs may go on before a complete run.
		 *
		 * @param int    $seconds  Seconds between two complete runs.
		 * @param string $resource Resource key.
		 */
		$full_every = (int) apply_filters( 'kurabu_wp_sync_full_sync_interval', DAY_IN_SECONDS, $resource );
		$last_full  = $this->last_full_sync( $resource );

		if ( 0 === $last_full || ( time() - $last_full ) >= $full_every ) {
			return null;
		}

		return $last_success;
	}

	/**
	 * When a resource was last synced completely, as a unix timestamp.
	 *
	 * @param string $resource Resource key.
	 */
	public function last_full_sync( string $resource ): int {
		$stored = get_option( self::FULL_SYNC_OPTION, array() );

		return is_array( $stored ) ? (int) ( $stored[ $resource ] ?? 0 ) : 0;
	}

	/**
	 * Records that a resource was synced completely.
	 *
	 * @param string $resource Resource key.
	 */
	private function remember_full_sync( string $resource ): void {
		$stored = get_option( self::FULL_SYNC_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $resource ] = time();

		update_option( self::FULL_SYNC_OPTION, $stored, false );
	}

	/**
	 * Drops log entries older than the configured retention.
	 */
	private function purge_log(): void {
		$days = (int) Settings::get( 'log_retention_days', 30 );

		if ( $days > 0 ) {
			Logger::purge_older_than( $days );
		}
	}

	/**
	 * The resources enabled in the settings, in sync order.
	 *
	 * @return string[]
	 */
	public static function enabled_resources(): array {
		$stored = Settings::get( 'resources', Resource::all() );

		if ( ! is_array( $stored ) ) {
			return Resource::all();
		}

		return Resource::filter( $stored );
	}

	/**
	 * The API client, created on first use.
	 */
	public function client(): Client {
		if ( null === $this->client ) {
			$this->client = new Client();
		}

		return $this->client;
	}
}

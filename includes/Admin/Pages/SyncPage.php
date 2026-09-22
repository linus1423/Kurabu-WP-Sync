<?php
/**
 * Synchronisation screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\ApiException;
use Kurabu\WPSync\Sync\Engine;
use Kurabu\WPSync\Sync\Lock;
use Kurabu\WPSync\Sync\Resource;
use Kurabu\WPSync\Sync\RunReport;
use Kurabu\WPSync\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the data kinds, starts a manual run and tests the connection.
 *
 * The screen only talks to the engine and the client; it contains no sync
 * logic of its own.
 */
final class SyncPage extends AbstractPage {

	private const NONCE = 'kurabu_wp_sync_sync';

	/**
	 * Report of a run started from this screen.
	 */
	private ?RunReport $report = null;

	/**
	 * Result of a connection test.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $probe = null;

	public function slug(): string {
		return 'kurabu-wp-sync-sync';
	}

	public function menu_title(): string {
		return __( 'Synchronisation', 'kurabu-wp-sync' );
	}

	/**
	 * Handles the three submit buttons of this screen.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$is_run   = isset( $_POST['kurabu_sync_run'] );
		$is_queue = isset( $_POST['kurabu_sync_queue'] );
		$is_test  = isset( $_POST['kurabu_sync_test'] );

		if ( ! $is_run && ! $is_queue && ! $is_test ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( $is_test ) {
			$this->run_test();

			return;
		}

		$resources = $this->submitted_resources();

		Settings::update( array( 'resources' => $resources ) );

		if ( ! $resources ) {
			add_settings_error(
				'kurabu_wp_sync',
				'no_resources',
				__( 'Es war keine Datenart ausgewählt.', 'kurabu-wp-sync' ),
				'warning'
			);

			return;
		}

		if ( $is_queue ) {
			$queued = Scheduler::schedule_single_run();

			add_settings_error(
				'kurabu_wp_sync',
				'queued',
				$queued
					? __( 'Die Synchronisation wurde für den nächsten Cron-Durchlauf vorgemerkt.', 'kurabu-wp-sync' )
					: __( 'Es ist bereits ein Lauf vorgemerkt.', 'kurabu-wp-sync' ),
				$queued ? 'success' : 'warning'
			);

			return;
		}

		$force_full = isset( $_POST['force_full'] );

		$this->report = Engine::instance()->run( $resources, RunReport::MODE_MANUAL, $force_full );

		add_settings_error(
			'kurabu_wp_sync',
			'run',
			$this->report->summary(),
			$this->report->has_errors() ? 'error' : 'success'
		);
	}

	protected function render_body(): void {
		settings_errors( 'kurabu_wp_sync' );

		$this->render_state();

		if ( null !== $this->report ) {
			$this->render_report( $this->report );
		}

		if ( null !== $this->probe ) {
			$this->render_probe( $this->probe );
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );

		$this->render_resource_choice();
		$this->render_run_buttons();
		$this->render_test();

		echo '</form>';
	}

	/**
	 * Shows interval, next run and whether the API is configured.
	 */
	private function render_state(): void {
		$next   = Scheduler::next_run();
		$labels = Settings::interval_labels();

		$rows = array(
			__( 'Automatische Synchronisation', 'kurabu-wp-sync' ) => Settings::get( 'sync_enabled', true )
				? sprintf(
					/* translators: %s: interval label. */
					__( 'aktiv, %s', 'kurabu-wp-sync' ),
					$labels[ Settings::interval_key() ] ?? Settings::interval_key()
				)
				: __( 'ausgeschaltet', 'kurabu-wp-sync' ),
			__( 'Nächster geplanter Lauf', 'kurabu-wp-sync' )      => $next > 0
				? $this->format_timestamp( $next )
				: __( 'nicht geplant', 'kurabu-wp-sync' ),
			__( 'API konfiguriert', 'kurabu-wp-sync' )             => Settings::is_configured()
				? __( 'ja', 'kurabu-wp-sync' )
				: __( 'nein — Basis-URL oder Token fehlen', 'kurabu-wp-sync' ),
		);

		if ( Lock::is_held() ) {
			$rows[ __( 'Status', 'kurabu-wp-sync' ) ] = __( 'Es läuft gerade eine Synchronisation.', 'kurabu-wp-sync' );
		}

		echo '<table class="widefat striped" style="max-width:44em;margin-bottom:1.5em"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width:16em"><strong>%s</strong></td><td>%s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';

		if ( ! Settings::is_configured() ) {
			printf(
				'<div class="notice notice-warning inline"><p><a href="%s">%s</a></p></div>',
				esc_url( (string) menu_page_url( 'kurabu-wp-sync', false ) ),
				esc_html__( 'Jetzt API-Basis-URL und Token hinterlegen', 'kurabu-wp-sync' )
			);
		}
	}

	/**
	 * Renders the checkbox list of data kinds.
	 */
	private function render_resource_choice(): void {
		$enabled = Engine::enabled_resources();

		echo '<h2>' . esc_html__( 'Datenarten', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Die Auswahl gilt für den manuellen Lauf und für die automatische Synchronisation.', 'kurabu-wp-sync' ) . '</p>';
		echo '<ul style="margin:1em 0">';

		foreach ( Resource::labels() as $resource => $label ) {
			printf(
				'<li><label><input type="checkbox" name="resources[]" value="%s" %s> %s</label></li>',
				esc_attr( $resource ),
				checked( in_array( $resource, $enabled, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</ul>';
	}

	/**
	 * Renders the two run buttons and the full-run switch.
	 */
	private function render_run_buttons(): void {
		printf(
			'<p><label><input type="checkbox" name="force_full" value="1"> %s</label></p>',
			esc_html__( 'Vollständigen Lauf erzwingen (ohne "geändert seit"-Filter)', 'kurabu-wp-sync' )
		);

		echo '<p>';
		submit_button( __( 'Jetzt synchronisieren', 'kurabu-wp-sync' ), 'primary', 'kurabu_sync_run', false );
		echo ' ';
		submit_button( __( 'Im Hintergrund starten', 'kurabu-wp-sync' ), 'secondary', 'kurabu_sync_queue', false );
		echo '</p>';

		echo '<p class="description">'
			. esc_html__( 'Ein vollständiger Lauf kann länger dauern als ein Seitenaufruf erlaubt. Bei vielen Datensätzen ist der Hintergrundlauf der sichere Weg.', 'kurabu-wp-sync' )
			. '</p>';
	}

	/**
	 * Renders the connection test.
	 */
	private function render_test(): void {
		echo '<hr>';
		echo '<h2>' . esc_html__( 'Verbindung testen', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Ruft einen Endpunkt einmal auf, ohne etwas zu speichern, und zeigt die gelieferten Feldnamen. Damit lässt sich das Mapping prüfen.', 'kurabu-wp-sync' )
			. '</p><p>';

		echo '<select name="test_resource">';

		foreach ( Resource::labels() as $resource => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $resource ), esc_html( $label ) );
		}

		echo '</select> ';
		submit_button( __( 'Endpunkt abfragen', 'kurabu-wp-sync' ), 'secondary', 'kurabu_sync_test', false );
		echo '</p>';
	}

	/**
	 * Renders the per-resource result of a manual run.
	 *
	 * @param RunReport $report The report.
	 */
	private function render_report( RunReport $report ): void {
		$results = $report->results();

		if ( ! $results ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Ergebnis des Laufs', 'kurabu-wp-sync' ) . '</h2>';
		echo '<table class="widefat striped" style="margin-bottom:1.5em"><thead><tr>';
		echo '<th>' . esc_html__( 'Datenart', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Ergebnis', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $results as $resource => $result ) {
			printf(
				'<tr><td>%s</td><td>%s%s</td></tr>',
				esc_html( Resource::label( (string) $resource ) ),
				$result->is_success() ? '' : '<strong>' . esc_html__( 'Fehler: ', 'kurabu-wp-sync' ) . '</strong>',
				esc_html( $result->summary() )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Runs the connection test against the selected resource.
	 */
	private function run_test(): void {
		$resource = isset( $_POST['test_resource'] ) ? sanitize_key( wp_unslash( $_POST['test_resource'] ) ) : '';

		if ( ! Resource::is_valid( $resource ) ) {
			return;
		}

		$client = Engine::instance()->client();

		if ( ! $client->is_configured() ) {
			add_settings_error(
				'kurabu_wp_sync',
				'not_configured',
				__( 'Basis-URL oder Token fehlen.', 'kurabu-wp-sync' ),
				'error'
			);

			return;
		}

		try {
			$this->probe             = $client->probe( $resource );
			$this->probe['resource'] = $resource;
		} catch ( ApiException $exception ) {
			add_settings_error( 'kurabu_wp_sync', 'probe_failed', $exception->getMessage(), 'error' );

			$this->probe = array(
				'resource' => $resource,
				'url'      => $exception->url(),
				'status'   => $exception->status(),
				'count'    => 0,
				'keys'     => array(),
			);
		}
	}

	/**
	 * Renders what the connection test found.
	 *
	 * @param array<string, mixed> $probe Probe result.
	 */
	private function render_probe( array $probe ): void {
		echo '<h2>' . esc_html__( 'Antwort des Endpunkts', 'kurabu-wp-sync' ) . '</h2>';
		echo '<table class="widefat striped" style="margin-bottom:1.5em"><tbody>';

		$rows = array(
			__( 'Datenart', 'kurabu-wp-sync' )        => Resource::label( (string) ( $probe['resource'] ?? '' ) ),
			__( 'Aufgerufene URL', 'kurabu-wp-sync' ) => (string) ( $probe['url'] ?? '' ),
			__( 'HTTP-Status', 'kurabu-wp-sync' )     => (string) ( $probe['status'] ?? '' ),
			__( 'Datensätze', 'kurabu-wp-sync' )      => (string) ( $probe['count'] ?? 0 ),
		);

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width:16em"><strong>%s</strong></td><td><code>%s</code></td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		$keys     = isset( $probe['keys'] ) && is_array( $probe['keys'] ) ? $probe['keys'] : array();
		$rendered = array();

		foreach ( $keys as $key ) {
			$rendered[] = '<code>' . esc_html( (string) $key ) . '</code>';
		}

		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Felder im ersten Datensatz', 'kurabu-wp-sync' ),
			$rendered ? wp_kses_post( implode( ', ', $rendered ) ) : '&mdash;'
		);

		echo '</tbody></table>';
	}

	/**
	 * The data kinds ticked in the form.
	 *
	 * @return string[]
	 */
	private function submitted_resources(): array {
		$raw = isset( $_POST['resources'] ) ? wp_unslash( $_POST['resources'] ) : array();

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return Resource::filter( array_map( 'sanitize_key', $raw ) );
	}
}

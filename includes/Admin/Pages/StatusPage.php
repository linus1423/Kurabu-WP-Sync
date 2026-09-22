<?php
/**
 * Synchronisationsstatus screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Database\ObjectMap;
use Kurabu\WPSync\Database\SyncState;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Engine;
use Kurabu\WPSync\Sync\Lock;
use Kurabu\WPSync\Sync\Resource;
use Kurabu\WPSync\Sync\RunReport;
use Kurabu\WPSync\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the schedule, the last run and the per-resource state.
 */
final class StatusPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-status';
	}

	public function menu_title(): string {
		return __( 'Synchronisationsstatus', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$this->render_schedule();
		$this->render_last_run();
		$this->render_resource_states();
		$this->render_counts();
	}

	/**
	 * Interval, next run and whether the API is reachable at all.
	 */
	private function render_schedule(): void {
		$labels = Settings::interval_labels();
		$next   = Scheduler::next_run();

		$rows = array(
			__( 'Intervall', 'kurabu-wp-sync' )               => Settings::get( 'sync_enabled', true )
				? ( $labels[ Settings::interval_key() ] ?? Settings::interval_key() )
				: __( 'automatische Synchronisation ausgeschaltet', 'kurabu-wp-sync' ),
			__( 'Nächster geplanter Lauf', 'kurabu-wp-sync' ) => $next > 0
				? $this->format_timestamp( $next )
				: __( 'nicht geplant', 'kurabu-wp-sync' ),
			__( 'API konfiguriert', 'kurabu-wp-sync' )        => Settings::is_configured()
				? __( 'ja', 'kurabu-wp-sync' )
				: __( 'nein', 'kurabu-wp-sync' ),
			__( 'Inkrementelle Läufe', 'kurabu-wp-sync' )     => Settings::get( 'incremental', true )
				? __( 'ja, mindestens täglich ein vollständiger Lauf', 'kurabu-wp-sync' )
				: __( 'nein, jeder Lauf ist vollständig', 'kurabu-wp-sync' ),
		);

		if ( Lock::is_held() ) {
			$rows[ __( 'Gerade aktiv', 'kurabu-wp-sync' ) ] = __( 'Es läuft eine Synchronisation.', 'kurabu-wp-sync' );
		}

		$this->render_definition_table( $rows );
	}

	/**
	 * What the last run did.
	 */
	private function render_last_run(): void {
		$last = RunReport::last();

		if ( null === $last ) {
			return;
		}

		$modes = array(
			RunReport::MODE_CRON   => __( 'automatisch', 'kurabu-wp-sync' ),
			RunReport::MODE_MANUAL => __( 'manuell', 'kurabu-wp-sync' ),
		);

		echo '<h2>' . esc_html__( 'Letzter Lauf', 'kurabu-wp-sync' ) . '</h2>';

		$this->render_definition_table(
			array(
				__( 'Zeitpunkt', 'kurabu-wp-sync' ) => $this->format_timestamp( (int) ( $last['started_at'] ?? 0 ) ),
				__( 'Auslöser', 'kurabu-wp-sync' )  => $modes[ (string) ( $last['mode'] ?? '' ) ] ?? '—',
				__( 'Dauer', 'kurabu-wp-sync' )     => sprintf(
					/* translators: %d: seconds. */
					__( '%d Sekunden', 'kurabu-wp-sync' ),
					(int) ( $last['duration'] ?? 0 )
				),
				__( 'Ergebnis', 'kurabu-wp-sync' )  => (string) ( $last['summary'] ?? '' ),
			)
		);
	}

	/**
	 * The per-resource state written by the engine.
	 */
	private function render_resource_states(): void {
		$states = SyncState::all();

		echo '<h2>' . esc_html__( 'Datenarten', 'kurabu-wp-sync' ) . '</h2>';

		if ( ! $states ) {
			$this->render_placeholder(
				__( 'Es wurde noch nicht synchronisiert. Nach dem ersten Lauf steht hier je Datenart der letzte Stand.', 'kurabu-wp-sync' )
			);

			return;
		}

		$engine = Engine::instance();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datenart', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Letzter Versuch', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Letzter Erfolg', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Letzter vollständiger Lauf', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Datensätze', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Meldung', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $states as $resource => $state ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( Resource::label( (string) $resource ) ),
				esc_html( $this->status_label( (string) ( $state['last_status'] ?? '' ) ) ),
				esc_html( $this->format_datetime( $state['last_attempt_at'] ?? null ) ),
				esc_html( $this->format_datetime( $state['last_success_at'] ?? null ) ),
				esc_html( $this->format_timestamp( $engine->last_full_sync( (string) $resource ) ) ),
				esc_html( (string) ( $state['items_synced'] ?? 0 ) ),
				esc_html( (string) ( $state['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">'
			. esc_html__( 'Ein fehlgeschlagener Lauf lässt "Letzter Erfolg" unberührt: der nächste Lauf holt denselben Zeitraum erneut und der zuletzt erfolgreiche Datenbestand bleibt erhalten.', 'kurabu-wp-sync' )
			. '</p>';
	}

	/**
	 * How much is currently in the local cache.
	 */
	private function render_counts(): void {
		$plugin = \Kurabu\WPSync\plugin();

		echo '<h2>' . esc_html__( 'Lokaler Datenbestand', 'kurabu-wp-sync' ) . '</h2>';

		$this->render_definition_table(
			array(
				Resource::label( Resource::DEPARTMENTS )    => (string) $plugin->departments()->count(),
				Resource::label( Resource::TEAMS )          => (string) $plugin->teams()->count(),
				Resource::label( Resource::LOCATIONS )      => (string) $plugin->locations()->count(),
				Resource::label( Resource::TRAININGS )      => (string) $plugin->trainings()->count(),
				Resource::label( Resource::TRAINING_TIMES ) => (string) $plugin->training_times()->count(),
				Resource::label( Resource::NEWS )           => (string) ObjectMap::count( ObjectMap::TYPE_POST ),
				Resource::label( Resource::EVENTS )         => (string) ObjectMap::count( ObjectMap::TYPE_EVENT ),
			)
		);
	}

	/**
	 * A German label for a stored status value.
	 *
	 * @param string $status Stored status.
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case SyncState::STATUS_SUCCESS:
				return __( 'erfolgreich', 'kurabu-wp-sync' );
			case SyncState::STATUS_ERROR:
				return __( 'Fehler', 'kurabu-wp-sync' );
			case SyncState::STATUS_RUNNING:
				return __( 'läuft', 'kurabu-wp-sync' );
			default:
				return '—';
		}
	}

	/**
	 * Renders a two column label/value table.
	 *
	 * @param array<string, string> $rows Label => value.
	 */
	private function render_definition_table( array $rows ): void {
		echo '<table class="widefat striped" style="max-width:48em;margin-bottom:1.5em"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="width:18em"><strong>%s</strong></td><td>%s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';
	}
}

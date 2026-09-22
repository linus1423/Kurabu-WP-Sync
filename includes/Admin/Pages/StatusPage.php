<?php
/**
 * Synchronisationsstatus screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Database\SyncState;
use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the per-resource sync state.
 */
final class StatusPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-status';
	}

	public function menu_title(): string {
		return __( 'Synchronisationsstatus', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$labels = Settings::interval_labels();
		$next   = wp_next_scheduled( Plugin::CRON_HOOK );

		echo '<table class="widefat striped" style="max-width:40em;margin-bottom:1.5em"><tbody>';
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Intervall', 'kurabu-wp-sync' ),
			esc_html( $labels[ Settings::interval_key() ] ?? Settings::interval_key() )
		);
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Nächster geplanter Lauf', 'kurabu-wp-sync' ),
			esc_html( $next ? $this->format_timestamp( (int) $next ) : __( 'nicht geplant', 'kurabu-wp-sync' ) )
		);
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'API konfiguriert', 'kurabu-wp-sync' ),
			esc_html( Settings::is_configured() ? __( 'ja', 'kurabu-wp-sync' ) : __( 'nein', 'kurabu-wp-sync' ) )
		);
		echo '</tbody></table>';

		$states = SyncState::all();

		if ( ! $states ) {
			$this->render_placeholder(
				__( 'Es wurde noch nicht synchronisiert. Sobald die Sync-Engine läuft, erscheint hier je Datenart der letzte Lauf.', 'kurabu-wp-sync' )
			);

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Datenart', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Letzter Versuch', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Letzter Erfolg', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Datensätze', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Meldung', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $states as $resource => $state ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $resource ),
				esc_html( (string) ( $state['last_status'] ?? '' ) ),
				esc_html( $this->format_datetime( $state['last_attempt_at'] ?? null ) ),
				esc_html( $this->format_datetime( $state['last_success_at'] ?? null ) ),
				esc_html( (string) ( $state['items_synced'] ?? 0 ) ),
				esc_html( (string) ( $state['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
	}
}

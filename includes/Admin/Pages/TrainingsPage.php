<?php
/**
 * Trainings screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the cached Trainings and their Trainingszeiten.
 */
final class TrainingsPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-trainings';
	}

	public function menu_title(): string {
		return __( 'Trainings', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$trainings = plugin()->trainings()->query();

		if ( ! $trainings ) {
			$this->render_placeholder(
				__( 'Noch keine Trainings im lokalen Cache. Sie erscheinen hier nach dem ersten Synchronisationslauf.', 'kurabu-wp-sync' )
			);

			return;
		}

		$times = plugin()->training_times();

		echo '<p>' . esc_html__( 'Die KURABU-ID wird im Shortcode als id verwendet, zum Beispiel [kurabu_training id="12345"].', 'kurabu-wp-sync' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Training', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'KURABU-ID', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Abteilung', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Gruppe', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Zeiten', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Zuletzt synchronisiert', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $trainings as $training ) {
			printf(
				'<tr><td><strong>%s</strong></td><td><code>%s</code></td><td><code>%s</code></td><td><code>%s</code></td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $training->get( 'name', '' ) ),
				esc_html( $training->kurabu_id() ),
				esc_html( (string) $training->get( 'department_kurabu_id', '' ) ),
				esc_html( (string) $training->get( 'team_kurabu_id', '' ) ),
				(int) $times->count( array( 'training_kurabu_id' => $training->kurabu_id() ) ),
				esc_html( $this->format_datetime( $training->get( 'synced_at' ) ) )
			);
		}

		echo '</tbody></table>';
	}
}

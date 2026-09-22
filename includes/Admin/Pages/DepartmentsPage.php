<?php
/**
 * Abteilungen screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the cached Abteilungen.
 */
final class DepartmentsPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-departments';
	}

	public function menu_title(): string {
		return __( 'Abteilungen', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$departments = plugin()->departments()->query();

		if ( ! $departments ) {
			$this->render_placeholder(
				__( 'Noch keine Abteilungen im lokalen Cache. Sie erscheinen hier nach dem ersten Synchronisationslauf.', 'kurabu-wp-sync' )
			);

			return;
		}

		$teams     = plugin()->teams();
		$trainings = plugin()->trainings();

		echo '<p>' . esc_html__( 'Die Kennung wird im Shortcode als id verwendet, zum Beispiel [kurabu_department id="turnen"].', 'kurabu-wp-sync' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Abteilung', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Kennung', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'KURABU-ID', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Gruppen', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Trainings', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Zuletzt synchronisiert', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $departments as $department ) {
			$kurabu_id = $department->kurabu_id();

			printf(
				'<tr><td><strong>%s</strong></td><td><code>%s</code></td><td><code>%s</code></td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $department->get( 'name', '' ) ),
				esc_html( (string) $department->get( 'slug', '' ) ),
				esc_html( $kurabu_id ),
				(int) $teams->count( array( 'department_kurabu_id' => $kurabu_id ) ),
				(int) $trainings->count( array( 'department_kurabu_id' => $kurabu_id ) ),
				esc_html( $this->format_datetime( $department->get( 'synced_at' ) ) )
			);
		}

		echo '</tbody></table>';
	}
}

<?php
/**
 * Fehlerprotokoll screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view of the sync log.
 */
final class LogPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-log';
	}

	public function menu_title(): string {
		return __( 'Fehlerprotokoll', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$level   = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$allowed = array( Logger::DEBUG, Logger::INFO, Logger::WARNING, Logger::ERROR );

		if ( ! in_array( $level, $allowed, true ) ) {
			$level = '';
		}

		$this->render_filter( $level, $allowed );

		$entries = Logger::recent( 200, $level );

		if ( ! $entries ) {
			$this->render_placeholder( __( 'Keine Protokolleinträge vorhanden.', 'kurabu-wp-sync' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Zeitpunkt', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Ebene', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Datenart', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Meldung', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( $this->format_datetime( $entry['created_at'] ?? null ) ),
				esc_html( (string) ( $entry['level'] ?? '' ) ),
				esc_html( (string) ( $entry['resource'] ?? '' ) ),
				esc_html( (string) ( $entry['message'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the level filter links.
	 *
	 * @param string   $current Active level.
	 * @param string[] $levels  Selectable levels.
	 */
	private function render_filter( string $current, array $levels ): void {
		$links = array();
		$base  = menu_page_url( $this->slug(), false );

		$links[] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $base ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'Alle', 'kurabu-wp-sync' )
		);

		foreach ( $levels as $level ) {
			$links[] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( 'level', $level, $base ) ),
				$current === $level ? ' class="current"' : '',
				esc_html( $level )
			);
		}

		echo '<ul class="subsubsub"><li>' . wp_kses_post( implode( ' | </li><li>', $links ) ) . '</li></ul>';
		echo '<div style="clear:both"></div>';
	}
}

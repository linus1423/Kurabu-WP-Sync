<?php
/**
 * Kalenderintegration screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Kalenderintegration screen.
 *
 * Placeholder screen: the menu entry and the capability check exist, the
 * contents arrive with the layer that owns this screen.
 */
final class CalendarPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-calendar';
	}

	public function menu_title(): string {
		return __( 'Kalenderintegration', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$this->render_placeholder(
			__( 'Hier wird der WordPress-Kalender ausgewählt, in den KURABU-Events übertragen werden.', 'kurabu-wp-sync' )
		);
	}
}

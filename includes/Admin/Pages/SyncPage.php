<?php
/**
 * Synchronisation screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Synchronisation screen.
 *
 * Placeholder screen: the menu entry and the capability check exist, the
 * contents arrive with the layer that owns this screen.
 */
final class SyncPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-sync';
	}

	public function menu_title(): string {
		return __( 'Synchronisation', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$this->render_placeholder(
			__( 'Hier entstehen die manuelle Synchronisation und die Auswahl der zu synchronisierenden Datenarten.', 'kurabu-wp-sync' )
		);
	}
}

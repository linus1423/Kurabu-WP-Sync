<?php
/**
 * Vorlagen screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Vorlagen screen.
 *
 * Placeholder screen: the menu entry and the capability check exist, the
 * contents arrive with the layer that owns this screen.
 */
final class TemplatesPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-templates';
	}

	public function menu_title(): string {
		return __( 'Vorlagen', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$this->render_placeholder(
			__( 'Hier entsteht der Vorlagen-Baukasten für die Darstellung von Trainings und Abteilungen.', 'kurabu-wp-sync' )
		);
	}
}

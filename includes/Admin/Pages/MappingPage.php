<?php
/**
 * Mapping screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Mapping screen.
 *
 * Placeholder screen: the menu entry and the capability check exist, the
 * contents arrive with the layer that owns this screen.
 */
final class MappingPage extends AbstractPage {

	public function slug(): string {
		return 'kurabu-wp-sync-mapping';
	}

	public function menu_title(): string {
		return __( 'Mapping', 'kurabu-wp-sync' );
	}

	protected function render_body(): void {
		$this->render_placeholder(
			__( 'Hier wird festgelegt, wie KURABU-Felder auf WordPress-Beiträge und -Felder abgebildet werden.', 'kurabu-wp-sync' )
		);
	}
}

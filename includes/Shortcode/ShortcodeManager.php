<?php
/**
 * Registers the KURABU shortcodes.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Template\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The entry point of the shortcode layer.
 *
 * It hangs itself into `kurabu_wp_sync_booted` so the container is not wired
 * to the shortcodes directly (ARCHITECTURE.md).
 */
final class ShortcodeManager {

	/**
	 * Hooks the layer into the plugin's boot action.
	 */
	public static function bootstrap(): void {
		add_action( 'kurabu_wp_sync_booted', array( self::class, 'register' ) );
	}

	/**
	 * Registers every shortcode and the stylesheet.
	 */
	public static function register(): void {
		foreach ( self::shortcodes() as $shortcode ) {
			$shortcode->register();
		}

		Assets::register();
	}

	/**
	 * The registered shortcodes.
	 *
	 * @return AbstractShortcode[]
	 */
	public static function shortcodes(): array {
		$shortcodes = array(
			new DepartmentShortcode(),
			new TrainingShortcode(),
			new TrainingsShortcode(),
		);

		/**
		 * Filters the registered shortcodes.
		 *
		 * @param AbstractShortcode[] $shortcodes Registered shortcodes.
		 */
		return (array) apply_filters( 'kurabu_wp_sync_shortcodes', $shortcodes );
	}
}

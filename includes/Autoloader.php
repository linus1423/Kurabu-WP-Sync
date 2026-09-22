<?php
/**
 * PSR-4 style autoloader for the plugin namespace.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the Kurabu\WPSync namespace onto the includes/ directory.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Registers the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads a class file for the given fully qualified class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = KURABU_WP_SYNC_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

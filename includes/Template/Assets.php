<?php
/**
 * Stylesheet of the rendered templates.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the frontend stylesheet and enqueues it only where it is used.
 *
 * The styles stay deliberately plain: they lay the elements out and leave
 * colours and fonts to the theme, so a template looks like the rest of the
 * site. Themes that bring their own styling can dequeue the handle.
 */
final class Assets {

	public const HANDLE = 'kurabu-wp-sync';

	/**
	 * Whether the stylesheet was already requested in this request.
	 */
	private static bool $enqueued = false;

	/**
	 * Registers the stylesheet.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_style' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_style' ) );
	}

	/**
	 * Registers the handle without enqueueing it.
	 */
	public static function register_style(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE,
			KURABU_WP_SYNC_URL . 'assets/css/kurabu.css',
			array(),
			KURABU_WP_SYNC_VERSION
		);
	}

	/**
	 * Enqueues the stylesheet; called while a shortcode renders.
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}

		self::register_style();

		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );

			self::$enqueued = true;
		}
	}
}

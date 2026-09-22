<?php
/**
 * Base class of the KURABU shortcodes.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Template\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour of the shortcodes: attributes, caching and messages.
 *
 * Shortcodes decide which records appear on a page. They read them through
 * the repositories, never from the KURABU API, so a page renders normally
 * even while KURABU is unreachable.
 */
abstract class AbstractShortcode {

	/**
	 * The shared context builder, so related records are read once per request.
	 */
	private static ?ContextBuilder $context = null;

	/**
	 * The shortcode tag, without brackets.
	 */
	abstract public function tag(): string;

	/**
	 * The supported attributes and their defaults.
	 *
	 * @return array<string, string>
	 */
	abstract protected function defaults(): array;

	/**
	 * Produces the output for the normalised attributes.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 */
	abstract protected function output( array $atts ): string;

	/**
	 * Registers the shortcode.
	 */
	public function register(): void {
		add_shortcode( $this->tag(), array( $this, 'render' ) );
	}

	/**
	 * The shortcode callback.
	 *
	 * @param array<string, string>|string $atts Raw attributes.
	 *
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( $this->defaults(), is_array( $atts ) ? $atts : array(), $this->tag() );
		$atts = array_map(
			static function ( $value ): string {
				return is_scalar( $value ) ? trim( (string) $value ) : '';
			},
			$atts
		);

		$cached = RenderCache::get( $this->tag(), $atts );

		if ( null !== $cached ) {
			if ( '' !== $cached ) {
				Assets::enqueue();
			}

			return $cached;
		}

		$html = $this->output( $atts );

		if ( '' !== $html ) {
			Assets::enqueue();
		}

		RenderCache::set( $this->tag(), $atts, $html );

		return $html;
	}

	/**
	 * The shared context builder.
	 */
	protected function context(): ContextBuilder {
		if ( null === self::$context ) {
			self::$context = new ContextBuilder();
		}

		return self::$context;
	}

	/**
	 * A message that only the people who can fix it get to see.
	 *
	 * Visitors get nothing, so an empty Abteilung or a typo in a shortcode
	 * leaves no broken box on the public page.
	 *
	 * @param string $message What is wrong.
	 */
	protected function notice( string $message ): string {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return '';
		}

		return '<div class="kurabu-notice"><strong>' . esc_html__( 'KURABU', 'kurabu-wp-sync' ) . ':</strong> '
			. esc_html( $message ) . '</div>';
	}

	/**
	 * Reads a boolean attribute; accepts true/yes/1/on.
	 *
	 * @param mixed $value   Attribute value.
	 * @param bool  $default Returned when the attribute is empty.
	 */
	protected function is_true( $value, bool $default = false ): bool {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return $default;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'ja', 'on' ), true );
	}
}

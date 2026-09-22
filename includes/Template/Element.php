<?php
/**
 * A single element of a template.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the Vorlagen-Baukasten.
 *
 * An element is either a KURABU field (`field`) or a layout element such as a
 * heading, a divider or a button. Everything the administrator can set in the
 * backend lives in `$options`, so new options do not need a new class.
 */
final class Element {

	public const TYPE_FIELD     = 'field';
	public const TYPE_HEADING   = 'heading';
	public const TYPE_TEXT      = 'text';
	public const TYPE_DIVIDER   = 'divider';
	public const TYPE_BUTTON    = 'button';
	public const TYPE_SPACER    = 'spacer';
	public const TYPE_TRAININGS = 'trainings';
	public const TYPE_NEWS      = 'news';
	public const TYPE_EVENTS    = 'events';

	/**
	 * Allowed values of the `width` option.
	 */
	public const WIDTHS = array( 'full', 'half', 'third' );

	/**
	 * Allowed values of the `spacing` option.
	 */
	public const SPACINGS = array( 'none', 'small', 'medium', 'large' );

	/**
	 * Allowed values of the `tag` option of a heading.
	 */
	public const HEADING_TAGS = array( 'h2', 'h3', 'h4', 'h5' );

	/**
	 * Element type, one of the TYPE_* constants.
	 */
	private string $type;

	/**
	 * Element options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * @param string               $type    One of the TYPE_* constants.
	 * @param array<string, mixed> $options Element options.
	 */
	public function __construct( string $type, array $options = array() ) {
		$this->type    = $type;
		$this->options = $options;
	}

	/**
	 * Builds a field element.
	 *
	 * @param string               $field   Field key, see FieldRegistry.
	 * @param array<string, mixed> $options Element options.
	 */
	public static function field( string $field, array $options = array() ): self {
		return new self( self::TYPE_FIELD, array( 'field' => $field ) + $options );
	}

	/**
	 * Rebuilds an element from its stored array.
	 *
	 * @param array<string, mixed> $data Stored element.
	 */
	public static function from_array( array $data ): self {
		$type = isset( $data['type'] ) ? (string) $data['type'] : self::TYPE_FIELD;

		unset( $data['type'] );

		return new self( $type, $data );
	}

	/**
	 * The storable representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array( 'type' => $this->type ) + $this->options;
	}

	/**
	 * The element type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The KURABU field this element shows, empty for layout elements.
	 */
	public function field_key(): string {
		return self::TYPE_FIELD === $this->type ? (string) $this->option( 'field', '' ) : '';
	}

	/**
	 * Identifies the element inside a template.
	 *
	 * Field elements are unique per field, layout elements are not, so those
	 * fall back to their type.
	 */
	public function key(): string {
		return self::TYPE_FIELD === $this->type ? 'field:' . $this->field_key() : $this->type;
	}

	/**
	 * Returns one option.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Returned when the option is unset or empty.
	 *
	 * @return mixed
	 */
	public function option( string $key, $default = null ) {
		if ( ! isset( $this->options[ $key ] ) || '' === $this->options[ $key ] ) {
			return $default;
		}

		return $this->options[ $key ];
	}

	/**
	 * All options.
	 *
	 * @return array<string, mixed>
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Whether the administrator left this element switched on.
	 *
	 * Elements are visible unless explicitly hidden, so a template that gains
	 * a new element through an update shows it instead of silently dropping it.
	 */
	public function is_visible(): bool {
		return ! isset( $this->options['visible'] ) || (bool) $this->options['visible'];
	}

	/**
	 * Returns a copy with the given options merged in.
	 *
	 * @param array<string, mixed> $options Options to overwrite.
	 */
	public function with_options( array $options ): self {
		return new self( $this->type, array_merge( $this->options, $options ) );
	}

	/**
	 * The configured column width, guaranteed to be one of self::WIDTHS.
	 */
	public function width(): string {
		$width = (string) $this->option( 'width', 'full' );

		return in_array( $width, self::WIDTHS, true ) ? $width : 'full';
	}

	/**
	 * The configured spacing, guaranteed to be one of self::SPACINGS.
	 */
	public function spacing(): string {
		$spacing = (string) $this->option( 'spacing', 'small' );

		return in_array( $spacing, self::SPACINGS, true ) ? $spacing : 'small';
	}
}

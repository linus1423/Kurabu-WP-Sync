<?php
/**
 * A named template for one record type.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * A stored template: a type, a name and an ordered list of elements.
 *
 * Templates are value objects. The backend edits a copy and hands it back to
 * the TemplateStore; nothing renders from a half-saved state.
 */
final class Template {

	/**
	 * The key used in the shortcode's `template` parameter.
	 */
	private string $key;

	/**
	 * Record type, one of TemplateStore::TYPE_*.
	 */
	private string $type;

	/**
	 * The name shown in the backend.
	 */
	private string $name;

	/**
	 * The elements, in display order.
	 *
	 * @var Element[]
	 */
	private array $elements;

	/**
	 * @param string    $key      Shortcode key.
	 * @param string    $type     Record type.
	 * @param string    $name     Display name.
	 * @param Element[] $elements Elements in display order.
	 */
	public function __construct( string $key, string $type, string $name, array $elements = array() ) {
		$this->key      = $key;
		$this->type     = $type;
		$this->name     = $name;
		$this->elements = array_values(
			array_filter(
				$elements,
				static function ( $element ): bool {
					return $element instanceof Element;
				}
			)
		);
	}

	/**
	 * Rebuilds a template from its stored array.
	 *
	 * @param string               $key  Shortcode key.
	 * @param string               $type Record type.
	 * @param array<string, mixed> $data Stored template.
	 */
	public static function from_array( string $key, string $type, array $data ): self {
		$elements = array();

		foreach ( (array) ( $data['elements'] ?? array() ) as $element ) {
			if ( is_array( $element ) ) {
				$elements[] = Element::from_array( $element );
			}
		}

		return new self( $key, $type, (string) ( $data['name'] ?? $key ), $elements );
	}

	/**
	 * The storable representation; the key and type live in the option's keys.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'     => $this->name,
			'elements' => array_map(
				static function ( Element $element ): array {
					return $element->to_array();
				},
				$this->elements
			),
		);
	}

	/**
	 * The key used in the shortcode's `template` parameter.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * The record type this template renders.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The name shown in the backend.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The elements, in display order.
	 *
	 * @return Element[]
	 */
	public function elements(): array {
		return $this->elements;
	}

	/**
	 * Whether the template already contains an element with that key.
	 *
	 * @param string $key Element key, see Element::key().
	 */
	public function has_element( string $key ): bool {
		foreach ( $this->elements as $element ) {
			if ( $element->key() === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a copy under a different key and name.
	 *
	 * @param string $key  New shortcode key.
	 * @param string $name New display name.
	 */
	public function duplicate( string $key, string $name ): self {
		return new self( $key, $this->type, $name, $this->elements );
	}

	/**
	 * Returns a copy with a different name.
	 *
	 * @param string $name New display name.
	 */
	public function with_name( string $name ): self {
		return new self( $this->key, $this->type, $name, $this->elements );
	}

	/**
	 * Returns a copy with a different element list.
	 *
	 * @param Element[] $elements Elements in display order.
	 */
	public function with_elements( array $elements ): self {
		return new self( $this->key, $this->type, $this->name, $elements );
	}
}

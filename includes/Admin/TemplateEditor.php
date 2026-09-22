<?php
/**
 * The Vorlagen-Baukasten form.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin;

use Kurabu\WPSync\Template\Element;
use Kurabu\WPSync\Template\FieldRegistry;
use Kurabu\WPSync\Template\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the Baukasten form and renders it.
 *
 * The form works without JavaScript: order changes and removals are ordinary
 * submit buttons, so the screen behaves the same in every browser and in the
 * block editor's preview.
 */
final class TemplateEditor {

	/**
	 * Rebuilds the element list from a submitted form.
	 *
	 * The order follows the form's own keys, so the move buttons only have to
	 * swap two rows.
	 *
	 * @param array<string, mixed> $posted The raw `elements` array from $_POST.
	 *
	 * @return Element[]
	 */
	public static function elements_from_post( array $posted ): array {
		ksort( $posted, SORT_NUMERIC );

		$elements = array();

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';

			if ( '' === $type ) {
				continue;
			}

			$options = array(
				'visible'   => ! empty( $row['visible'] ),
				'width'     => self::one_of( $row['width'] ?? '', Element::WIDTHS, 'full' ),
				'spacing'   => self::one_of( $row['spacing'] ?? '', Element::SPACINGS, 'small' ),
				'css_class' => sanitize_text_field( (string) ( $row['css_class'] ?? '' ) ),
				'icon'      => sanitize_text_field( (string) ( $row['icon'] ?? '' ) ),
				'label'     => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			);

			if ( Element::TYPE_FIELD === $type ) {
				$options['field'] = sanitize_key( (string) ( $row['field'] ?? '' ) );

				if ( '' === $options['field'] ) {
					continue;
				}
			}

			if ( isset( $row['text'] ) ) {
				$options['text'] = Element::TYPE_TEXT === $type
					? wp_kses_post( (string) $row['text'] )
					: sanitize_text_field( (string) $row['text'] );
			}

			if ( isset( $row['url'] ) ) {
				$options['url'] = esc_url_raw( (string) $row['url'] );
			}

			if ( isset( $row['tag'] ) ) {
				$options['tag'] = self::one_of( $row['tag'], Element::HEADING_TAGS, '' );
			}

			if ( isset( $row['style'] ) ) {
				$options['style'] = self::one_of( $row['style'], array( 'button', 'link' ), '' );
			}

			if ( isset( $row['separator'] ) ) {
				$separator = sanitize_text_field( (string) $row['separator'] );

				if ( '' !== $separator ) {
					$options['separator'] = $separator;
				}
			}

			$elements[] = new Element( $type, array_filter( $options, array( self::class, 'keep' ) ) );
		}

		return $elements;
	}

	/**
	 * Builds a new element for the "add" dropdown.
	 *
	 * @param string $type  Record type.
	 * @param string $value Selected value: `field:<key>` or a layout type.
	 */
	public static function element_from_choice( string $type, string $value ): ?Element {
		if ( 0 === strpos( $value, 'field:' ) ) {
			$field = sanitize_key( substr( $value, strlen( 'field:' ) ) );

			if ( ! isset( FieldRegistry::fields( $type )[ $field ] ) ) {
				return null;
			}

			$icon = FieldRegistry::default_icon( $type, $field );

			return Element::field( $field, '' !== $icon ? array( 'icon' => $icon ) : array() );
		}

		$layout = sanitize_key( $value );

		if ( ! isset( FieldRegistry::layout_elements( $type )[ $layout ] ) ) {
			return null;
		}

		return new Element( $layout );
	}

	/**
	 * Renders the element rows of the editor form.
	 *
	 * @param Template $template The template being edited.
	 */
	public static function render_rows( Template $template ): void {
		$elements = $template->elements();

		if ( ! $elements ) {
			echo '<p>' . esc_html__( 'Diese Vorlage enthält noch kein Element. Unten lässt sich das erste hinzufügen.', 'kurabu-wp-sync' ) . '</p>';

			return;
		}

		$last = count( $elements ) - 1;

		echo '<table class="widefat striped kurabu-elements"><thead><tr>';
		echo '<th style="width:3em">' . esc_html__( 'Reihenfolge', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Element', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Darstellung', 'kurabu-wp-sync' ) . '</th>';
		echo '<th style="width:6em">' . esc_html__( 'Aktion', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $elements as $index => $element ) {
			self::render_row( $template, $element, (int) $index, (int) $index === $last );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one element row.
	 *
	 * @param Template $template The template being edited.
	 * @param Element  $element  The element.
	 * @param int      $index    Its position.
	 * @param bool     $is_last  Whether it is the last element.
	 */
	private static function render_row( Template $template, Element $element, int $index, bool $is_last ): void {
		$name = 'elements[' . $index . ']';
		$type = $element->type();

		echo '<tr>';

		printf(
			'<td><button type="submit" class="button button-small" name="row_action" value="up:%1$d" %3$s aria-label="%4$s">↑</button> '
			. '<button type="submit" class="button button-small" name="row_action" value="down:%1$d" %5$s aria-label="%6$s">↓</button>'
			. '<input type="hidden" name="%2$s[type]" value="%7$s">',
			(int) $index,
			esc_attr( $name ),
			0 === $index ? 'disabled' : '',
			esc_attr__( 'Nach oben', 'kurabu-wp-sync' ),
			$is_last ? 'disabled' : '',
			esc_attr__( 'Nach unten', 'kurabu-wp-sync' ),
			esc_attr( $type )
		);

		if ( Element::TYPE_FIELD === $type ) {
			printf( '<input type="hidden" name="%s[field]" value="%s">', esc_attr( $name ), esc_attr( $element->field_key() ) );
		}

		echo '</td>';

		echo '<td><strong>' . esc_html( FieldRegistry::label( $element, $template->type() ) ) . '</strong>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '[visible]" value="1" '
			. checked( $element->is_visible(), true, false ) . '> '
			. esc_html__( 'anzeigen', 'kurabu-wp-sync' ) . '</label></p>';

		if ( Element::TYPE_FIELD === $type ) {
			echo '<p><code>' . esc_html( $element->field_key() ) . '</code></p>';
		}

		echo '</td>';

		echo '<td>';
		self::render_options( $element, $name );
		echo '</td>';

		printf(
			'<td><button type="submit" class="button button-small button-link-delete" name="row_action" value="remove:%d">%s</button></td>',
			(int) $index,
			esc_html__( 'Entfernen', 'kurabu-wp-sync' )
		);

		echo '</tr>';
	}

	/**
	 * Renders the option inputs of one element.
	 *
	 * @param Element $element The element.
	 * @param string  $name    Field name prefix.
	 */
	private static function render_options( Element $element, string $name ): void {
		$type = $element->type();

		if ( in_array( $type, array( Element::TYPE_HEADING, Element::TYPE_TEXT, Element::TYPE_BUTTON, Element::TYPE_TRAININGS, Element::TYPE_NEWS, Element::TYPE_EVENTS ), true ) ) {
			self::text_input(
				$name . '[text]',
				Element::TYPE_BUTTON === $type ? __( 'Beschriftung', 'kurabu-wp-sync' ) : __( 'Text', 'kurabu-wp-sync' ),
				(string) $element->option( 'text', '' )
			);
		}

		if ( Element::TYPE_BUTTON === $type ) {
			self::text_input( $name . '[url]', __( 'Ziel (URL)', 'kurabu-wp-sync' ), (string) $element->option( 'url', '' ), 'url' );
		}

		if ( Element::TYPE_FIELD === $type ) {
			self::text_input( $name . '[label]', __( 'Beschriftung davor', 'kurabu-wp-sync' ), (string) $element->option( 'label', '' ) );
			self::text_input( $name . '[separator]', __( 'Trennzeichen bei Listen', 'kurabu-wp-sync' ), (string) $element->option( 'separator', '' ) );
		}

		if ( in_array( $type, array( Element::TYPE_FIELD, Element::TYPE_HEADING, Element::TYPE_TEXT, Element::TYPE_BUTTON ), true ) ) {
			self::text_input( $name . '[icon]', __( 'Icon', 'kurabu-wp-sync' ), (string) $element->option( 'icon', '' ) );
		}

		if ( in_array( $type, array( Element::TYPE_HEADING, Element::TYPE_FIELD, Element::TYPE_TRAININGS, Element::TYPE_NEWS, Element::TYPE_EVENTS ), true ) ) {
			self::select(
				$name . '[tag]',
				__( 'Als Überschrift', 'kurabu-wp-sync' ),
				array( '' => __( 'nein', 'kurabu-wp-sync' ) ) + array_combine( Element::HEADING_TAGS, Element::HEADING_TAGS ),
				(string) $element->option( 'tag', '' )
			);
		}

		if ( in_array( $type, array( Element::TYPE_BUTTON, Element::TYPE_FIELD ), true ) ) {
			self::select(
				$name . '[style]',
				__( 'Stil', 'kurabu-wp-sync' ),
				array(
					''       => __( 'Standard', 'kurabu-wp-sync' ),
					'link'   => __( 'Link', 'kurabu-wp-sync' ),
					'button' => __( 'Button', 'kurabu-wp-sync' ),
				),
				(string) $element->option( 'style', '' )
			);
		}

		self::select(
			$name . '[width]',
			__( 'Breite', 'kurabu-wp-sync' ),
			array(
				'full'  => __( 'volle Breite', 'kurabu-wp-sync' ),
				'half'  => __( 'halbe Spalte', 'kurabu-wp-sync' ),
				'third' => __( 'Drittel-Spalte', 'kurabu-wp-sync' ),
			),
			$element->width()
		);

		self::select(
			$name . '[spacing]',
			__( 'Abstand danach', 'kurabu-wp-sync' ),
			array(
				'none'   => __( 'kein', 'kurabu-wp-sync' ),
				'small'  => __( 'klein', 'kurabu-wp-sync' ),
				'medium' => __( 'mittel', 'kurabu-wp-sync' ),
				'large'  => __( 'groß', 'kurabu-wp-sync' ),
			),
			$element->spacing()
		);

		self::text_input( $name . '[css_class]', __( 'CSS-Klasse', 'kurabu-wp-sync' ), (string) $element->option( 'css_class', '' ) );
	}

	/**
	 * Renders a labelled text input.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @param string $type  Input type.
	 */
	private static function text_input( string $name, string $label, string $value, string $type = 'text' ): void {
		printf(
			'<p><label class="kurabu-field"><span>%s</span><input type="%s" class="regular-text" name="%s" value="%s"></label></p>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Renders a labelled select.
	 *
	 * @param string                $name    Field name.
	 * @param string                $label   Label.
	 * @param array<string, string> $options Value => label.
	 * @param string                $current Current value.
	 */
	private static function select( string $name, string $label, array $options, string $current ): void {
		printf(
			'<p><label class="kurabu-field"><span>%s</span><select name="%s">',
			esc_html( $label ),
			esc_attr( $name )
		);

		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $current, false ),
				esc_html( $option_label )
			);
		}

		echo '</select></label></p>';
	}

	/**
	 * Returns the value when it is allowed, otherwise the fallback.
	 *
	 * @param mixed    $value    Submitted value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback value.
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Whether an option is worth storing; keeps `false` out but `0` in.
	 *
	 * @param mixed $value Option value.
	 */
	private static function keep( $value ): bool {
		if ( is_bool( $value ) ) {
			return true;
		}

		return '' !== $value && null !== $value;
	}
}

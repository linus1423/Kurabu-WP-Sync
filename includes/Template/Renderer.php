<?php
/**
 * Turns a template plus prepared values into HTML.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The template engine.
 *
 * It receives a template and a context that the shortcode layer has already
 * filled, and decides only how that looks. It never queries anything: which
 * records end up in the context is the shortcodes' decision (ARCHITECTURE.md).
 *
 * The context has two parts:
 *
 * - `values`: field key => value, the value being a string, a list of strings
 *   or an array such as `array( 'url' => …, 'text' => … )`.
 * - `blocks`: already rendered HTML for the container elements, e.g. the
 *   trainings of an Abteilung, each rendered with the training template.
 */
final class Renderer {

	/**
	 * Renders a template.
	 *
	 * @param Template             $template The template.
	 * @param array<string, mixed> $context  Prepared values and blocks.
	 */
	public static function render( Template $template, array $context ): string {
		$rendered = array();

		foreach ( $template->elements() as $element ) {
			if ( ! $element->is_visible() ) {
				continue;
			}

			$html = self::render_element( $element, $template, $context );

			if ( '' === $html ) {
				continue;
			}

			$rendered[] = array(
				'width' => $element->width(),
				'html'  => $html,
			);
		}

		if ( ! $rendered ) {
			return '';
		}

		$classes = array_merge(
			array(
				'kurabu-template',
				'kurabu-template--' . $template->type(),
				'kurabu-template--' . $template->type() . '-' . $template->key(),
			),
			(array) ( $context['classes'] ?? array() )
		);

		$html = '<div class="' . esc_attr( self::class_list( $classes ) ) . '">'
			. self::group_rows( $rendered )
			. '</div>';

		/**
		 * Filters the rendered output of one template.
		 *
		 * @param string               $html     Rendered HTML.
		 * @param Template             $template The template.
		 * @param array<string, mixed> $context  The context it was rendered with.
		 */
		return (string) apply_filters( 'kurabu_wp_sync_rendered_template', $html, $template, $context );
	}

	/**
	 * Renders one element, or an empty string when it has nothing to show.
	 *
	 * @param Element              $element  The element.
	 * @param Template             $template The template it belongs to.
	 * @param array<string, mixed> $context  Prepared values and blocks.
	 */
	private static function render_element( Element $element, Template $template, array $context ): string {
		switch ( $element->type() ) {
			case Element::TYPE_FIELD:
				return self::render_field( $element, $template, $context );

			case Element::TYPE_HEADING:
				$text = (string) $element->option( 'text', '' );

				if ( '' === $text ) {
					return '';
				}

				$tag = (string) $element->option( 'tag', 'h3' );
				$tag = in_array( $tag, Element::HEADING_TAGS, true ) ? $tag : 'h3';

				return self::wrap(
					$element,
					$template,
					self::icon( $element ) . esc_html( $text ),
					$tag
				);

			case Element::TYPE_TEXT:
				$text = (string) $element->option( 'text', '' );

				return '' === $text
					? ''
					: self::wrap( $element, $template, self::icon( $element ) . wp_kses_post( $text ) );

			case Element::TYPE_DIVIDER:
				return self::wrap( $element, $template, '<hr class="kurabu-divider">' );

			case Element::TYPE_SPACER:
				return self::wrap( $element, $template, '' );

			case Element::TYPE_BUTTON:
				return self::render_button( $element, $template );

			case Element::TYPE_TRAININGS:
			case Element::TYPE_NEWS:
			case Element::TYPE_EVENTS:
				return self::render_block( $element, $template, $context );
		}

		return '';
	}

	/**
	 * Renders a KURABU field.
	 *
	 * @param Element              $element  The element.
	 * @param Template             $template The template it belongs to.
	 * @param array<string, mixed> $context  Prepared values and blocks.
	 */
	private static function render_field( Element $element, Template $template, array $context ): string {
		$field  = $element->field_key();
		$values = (array) ( $context['values'] ?? array() );

		if ( '' === $field || ! isset( $values[ $field ] ) ) {
			return '';
		}

		$value = $values[ $field ];

		if ( '' === $value || array() === $value || null === $value ) {
			return '';
		}

		$kind = FieldRegistry::kind( $template->type(), $field );
		$body = self::render_value( $kind, $value, $element );

		if ( '' === $body ) {
			return '';
		}

		$label = (string) $element->option( 'label', '' );

		if ( '' !== $label ) {
			$body = '<span class="kurabu-el__label">' . esc_html( $label ) . '</span> ' . $body;
		}

		$tag = (string) $element->option( 'tag', '' );

		if ( in_array( $tag, Element::HEADING_TAGS, true ) ) {
			return self::wrap( $element, $template, self::icon( $element ) . $body, $tag );
		}

		return self::wrap( $element, $template, self::icon( $element ) . $body );
	}

	/**
	 * Turns one value into markup, according to its kind.
	 *
	 * @param string  $kind    One of the FieldRegistry::KIND_* constants.
	 * @param mixed   $value   The prepared value.
	 * @param Element $element The element, for its options.
	 */
	private static function render_value( string $kind, $value, Element $element ): string {
		switch ( $kind ) {
			case FieldRegistry::KIND_HTML:
				return wp_kses_post( self::scalar( $value ) );

			case FieldRegistry::KIND_LIST:
				return self::render_list( $value, $element );

			case FieldRegistry::KIND_IMAGE:
				return self::render_image( $value, $element );

			case FieldRegistry::KIND_LINK:
				return self::render_link( $value, $element );

			case FieldRegistry::KIND_EMAIL:
				$email = self::scalar( $value );

				return '' === $email
					? ''
					: '<a href="' . esc_attr( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';

			case FieldRegistry::KIND_PHONE:
				$phone = self::scalar( $value );

				if ( '' === $phone ) {
					return '';
				}

				$href = preg_replace( '/[^0-9+]/', '', $phone );

				return '<a href="' . esc_attr( 'tel:' . (string) $href ) . '">' . esc_html( $phone ) . '</a>';
		}

		return esc_html( self::scalar( $value ) );
	}

	/**
	 * Renders a list value, either inline or as a bullet list.
	 *
	 * @param mixed   $value   List of strings or of link arrays.
	 * @param Element $element The element, for its options.
	 */
	private static function render_list( $value, Element $element ): string {
		$items = is_array( $value ) ? $value : array( $value );
		$parts = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['url'] ) ) {
				$text = self::scalar( $item['text'] ?? $item['url'] );

				$parts[] = '<a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( $text ) . '</a>';
				continue;
			}

			$text = self::scalar( $item );

			if ( '' !== $text ) {
				$parts[] = esc_html( $text );
			}
		}

		if ( ! $parts ) {
			return '';
		}

		$separator = $element->option( 'separator', null );

		if ( null !== $separator ) {
			return '<span class="kurabu-list kurabu-list--inline">'
				. implode( esc_html( (string) $separator ), $parts )
				. '</span>';
		}

		return '<ul class="kurabu-list"><li>' . implode( '</li><li>', $parts ) . '</li></ul>';
	}

	/**
	 * Renders an image value.
	 *
	 * @param mixed   $value   URL, or an array with `url` and `alt`.
	 * @param Element $element The element, for its options.
	 */
	private static function render_image( $value, Element $element ): string {
		$url = is_array( $value ) ? (string) ( $value['url'] ?? '' ) : self::scalar( $value );

		if ( '' === $url ) {
			return '';
		}

		$alt = is_array( $value ) ? (string) ( $value['alt'] ?? '' ) : '';
		$alt = '' !== $alt ? $alt : (string) $element->option( 'alt', '' );

		return '<img class="kurabu-image" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
	}

	/**
	 * Renders a link value, as a plain link or as a button.
	 *
	 * @param mixed   $value   URL, or an array with `url` and `text`.
	 * @param Element $element The element, for its options.
	 */
	private static function render_link( $value, Element $element ): string {
		$url = is_array( $value ) ? (string) ( $value['url'] ?? '' ) : self::scalar( $value );

		if ( '' === $url ) {
			return '';
		}

		$text = (string) $element->option( 'text', '' );

		if ( '' === $text && is_array( $value ) ) {
			$text = (string) ( $value['text'] ?? '' );
		}

		$text = '' !== $text ? $text : $url;

		$class = 'button' === (string) $element->option( 'style', '' ) ? 'kurabu-button' : 'kurabu-link';

		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Renders a standalone link or button element.
	 *
	 * @param Element  $element  The element.
	 * @param Template $template The template it belongs to.
	 */
	private static function render_button( Element $element, Template $template ): string {
		$url  = (string) $element->option( 'url', '' );
		$text = (string) $element->option( 'text', '' );

		if ( '' === $url || '' === $text ) {
			return '';
		}

		$class = 'link' === (string) $element->option( 'style', 'button' ) ? 'kurabu-link' : 'kurabu-button';

		return self::wrap(
			$element,
			$template,
			'<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">'
				. self::icon( $element ) . esc_html( $text ) . '</a>'
		);
	}

	/**
	 * Renders a container element from the already rendered blocks.
	 *
	 * @param Element              $element  The element.
	 * @param Template             $template The template it belongs to.
	 * @param array<string, mixed> $context  Prepared values and blocks.
	 */
	private static function render_block( Element $element, Template $template, array $context ): string {
		$blocks = (array) ( $context['blocks'] ?? array() );
		$block  = $blocks[ $element->type() ] ?? null;

		if ( null === $block || '' === $block || array() === $block ) {
			return '';
		}

		$items = is_array( $block ) ? $block : array( $block );
		$body  = '';

		foreach ( $items as $item ) {
			$item = (string) $item;

			if ( '' === $item ) {
				continue;
			}

			$body .= '<div class="kurabu-' . esc_attr( $element->type() ) . '__item">' . $item . '</div>';
		}

		if ( '' === $body ) {
			return '';
		}

		$heading = (string) $element->option( 'text', '' );
		$prefix  = '';

		if ( '' !== $heading ) {
			$tag    = (string) $element->option( 'tag', 'h3' );
			$tag    = in_array( $tag, Element::HEADING_TAGS, true ) ? $tag : 'h3';
			$prefix = '<' . $tag . ' class="kurabu-block__heading">' . esc_html( $heading ) . '</' . $tag . '>';
		}

		return self::wrap(
			$element,
			$template,
			$prefix . '<div class="kurabu-' . esc_attr( $element->type() ) . '">' . $body . '</div>'
		);
	}

	/**
	 * Wraps an element body into its container.
	 *
	 * @param Element  $element  The element.
	 * @param Template $template The template it belongs to.
	 * @param string   $body     The already escaped body.
	 * @param string   $tag      Container tag.
	 */
	private static function wrap( Element $element, Template $template, string $body, string $tag = 'div' ): string {
		$classes = array(
			'kurabu-el',
			'kurabu-el--' . $element->type(),
			'kurabu-space--' . $element->spacing(),
		);

		if ( Element::TYPE_FIELD === $element->type() ) {
			$classes[] = 'kurabu-el--' . $element->field_key();
		}

		if ( Element::TYPE_SPACER === $element->type() ) {
			$classes[] = 'kurabu-spacer';
		}

		if ( 'full' !== $element->width() ) {
			$classes[] = 'kurabu-col kurabu-col--' . $element->width();
		}

		$classes[] = (string) $element->option( 'css_class', '' );

		/**
		 * Filters the CSS classes of one rendered element.
		 *
		 * @param string[] $classes  CSS classes.
		 * @param Element  $element  The element.
		 * @param Template $template The template it belongs to.
		 */
		$classes = (array) apply_filters( 'kurabu_wp_sync_element_classes', $classes, $element, $template );

		return '<' . $tag . ' class="' . esc_attr( self::class_list( $classes ) ) . '">' . $body . '</' . $tag . '>';
	}

	/**
	 * Renders the element's icon, if it has one.
	 *
	 * @param Element $element The element.
	 */
	private static function icon( Element $element ): string {
		$icon = (string) $element->option( 'icon', '' );

		return '' === $icon
			? ''
			: '<span class="kurabu-el__icon" aria-hidden="true">' . esc_html( $icon ) . '</span> ';
	}

	/**
	 * Wraps neighbouring half and third width elements into rows.
	 *
	 * @param array<int, array{width: string, html: string}> $rendered Rendered elements in order.
	 */
	private static function group_rows( array $rendered ): string {
		$html = '';
		$row  = array();

		foreach ( $rendered as $item ) {
			if ( 'full' === $item['width'] ) {
				$html .= self::flush_row( $row ) . $item['html'];
				continue;
			}

			$row[] = $item['html'];
		}

		return $html . self::flush_row( $row );
	}

	/**
	 * Closes an open row of column elements.
	 *
	 * @param string[] $row Collected column elements; emptied by reference.
	 */
	private static function flush_row( array &$row ): string {
		if ( ! $row ) {
			return '';
		}

		$html = '<div class="kurabu-row">' . implode( '', $row ) . '</div>';
		$row  = array();

		return $html;
	}

	/**
	 * Sanitises and joins a list of CSS classes.
	 *
	 * @param string[] $classes CSS classes, possibly with several per string.
	 */
	private static function class_list( array $classes ): string {
		$clean = array();

		foreach ( $classes as $class ) {
			foreach ( preg_split( '/\s+/', (string) $class ) ?: array() as $single ) {
				$single = sanitize_html_class( $single );

				if ( '' !== $single ) {
					$clean[ $single ] = true;
				}
			}
		}

		return implode( ' ', array_keys( $clean ) );
	}

	/**
	 * Casts a prepared value to a string.
	 *
	 * @param mixed $value Prepared value.
	 */
	private static function scalar( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}

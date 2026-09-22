<?php
/**
 * Renders Trainings with a template.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Model\Training;
use Kurabu\WPSync\Template\Renderer;
use Kurabu\WPSync\Template\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The one place where a Training becomes HTML.
 *
 * Both the training shortcodes and the Abteilung shortcode go through here,
 * so a training looks the same wherever it appears, exactly as the
 * specification asks.
 */
final class TrainingRenderer {

	/**
	 * Renders one Training.
	 *
	 * @param Training       $training The training.
	 * @param Template       $template The training template.
	 * @param ContextBuilder $context  The shared context builder.
	 */
	public static function one( Training $training, Template $template, ContextBuilder $context ): string {
		return Renderer::render( $template, $context->training_context( $training ) );
	}

	/**
	 * Renders a list of Trainings, one rendered template per entry.
	 *
	 * @param Training[]     $trainings The trainings.
	 * @param Template       $template  The training template.
	 * @param ContextBuilder $context   The shared context builder.
	 *
	 * @return string[]
	 */
	public static function many( array $trainings, Template $template, ContextBuilder $context ): array {
		$rendered = array();

		foreach ( $trainings as $training ) {
			$html = self::one( $training, $template, $context );

			if ( '' !== $html ) {
				$rendered[] = $html;
			}
		}

		return $rendered;
	}

	/**
	 * Wraps rendered trainings into a list.
	 *
	 * @param string[] $rendered Rendered trainings.
	 */
	public static function wrap_list( array $rendered ): string {
		if ( ! $rendered ) {
			return '';
		}

		return '<div class="kurabu-trainings kurabu-trainings--list"><div class="kurabu-trainings__item">'
			. implode( '</div><div class="kurabu-trainings__item">', $rendered )
			. '</div></div>';
	}
}

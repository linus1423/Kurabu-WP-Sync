<?php
/**
 * The [kurabu_trainings] shortcode.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a list of Trainings.
 *
 * ```
 * [kurabu_trainings department="turnen"]
 * [kurabu_trainings location="huk-halle" template="compact" limit="5"]
 * ```
 */
final class TrainingsShortcode extends AbstractTrainingShortcode {

	public function tag(): string {
		return 'kurabu_trainings';
	}

	/**
	 * Produces the output.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 */
	protected function output( array $atts ): string {
		$template = $this->template( $atts );

		if ( null === $template ) {
			return $this->missing_template_notice( $atts['template'] );
		}

		list( $trainings, $problem ) = $this->select( $atts );

		if ( '' !== $problem ) {
			return $this->notice( $problem );
		}

		if ( ! $trainings ) {
			return $this->notice( __( 'Zu dieser Auswahl gibt es im lokalen Datenbestand kein Training.', 'kurabu-wp-sync' ) );
		}

		return TrainingRenderer::wrap_list(
			TrainingRenderer::many( $trainings, $template, $this->context() )
		);
	}
}

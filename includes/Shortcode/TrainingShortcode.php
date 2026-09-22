<?php
/**
 * The [kurabu_training] shortcode.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Renders one Training, or the Trainings of a group.
 *
 * ```
 * [kurabu_training id="12345" template="compact"]
 * [kurabu_training team="jugend"]
 * ```
 */
final class TrainingShortcode extends AbstractTrainingShortcode {

	public function tag(): string {
		return 'kurabu_training';
	}

	/**
	 * Produces the output.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 */
	protected function output( array $atts ): string {
		if ( '' === $atts['id'] && '' === $atts['team'] && '' === $atts['department'] && '' === $atts['location'] ) {
			return $this->notice(
				__( 'Der Shortcode [kurabu_training] braucht eine Auswahl, zum Beispiel id="12345" oder team="jugend". Für alle Trainings gibt es [kurabu_trainings].', 'kurabu-wp-sync' )
			);
		}

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

		$rendered = TrainingRenderer::many( $trainings, $template, $this->context() );

		if ( 1 === count( $rendered ) ) {
			return $rendered[0];
		}

		return TrainingRenderer::wrap_list( $rendered );
	}
}

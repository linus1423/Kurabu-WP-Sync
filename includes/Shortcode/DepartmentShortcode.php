<?php
/**
 * The [kurabu_department] shortcode.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Model\Department;
use Kurabu\WPSync\Template\Element;
use Kurabu\WPSync\Template\Renderer;
use Kurabu\WPSync\Template\TemplateStore;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a whole Abteilung.
 *
 * ```
 * [kurabu_department id="turnen" template="standard" trainings="true" news="true" events="true"]
 * ```
 *
 * The trainings inside use the configured Training template, so a change to
 * that template also changes how the trainings appear here.
 */
final class DepartmentShortcode extends AbstractShortcode {

	public function tag(): string {
		return 'kurabu_department';
	}

	/**
	 * The supported attributes and their defaults.
	 *
	 * @return array<string, string>
	 */
	protected function defaults(): array {
		return array(
			'id'                => '',
			'template'          => '',
			'trainings'         => 'true',
			'training_template' => '',
			'news'              => 'false',
			'events'            => 'false',
			'orderby'           => 'menu_order',
			'order'             => 'ASC',
			'limit'             => '0',
		);
	}

	/**
	 * Produces the output.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 */
	protected function output( array $atts ): string {
		if ( '' === $atts['id'] ) {
			return $this->notice(
				__( 'Der Shortcode [kurabu_department] braucht eine Abteilung, zum Beispiel id="turnen".', 'kurabu-wp-sync' )
			);
		}

		$department = plugin()->departments()->find_by_reference( $atts['id'] );

		if ( ! $department instanceof Department ) {
			return $this->notice(
				sprintf(
					/* translators: %s: the id used in the shortcode. */
					__( 'Keine Abteilung mit der Kennung „%s" im lokalen Datenbestand.', 'kurabu-wp-sync' ),
					$atts['id']
				)
			);
		}

		$template = TemplateStore::resolve( TemplateStore::TYPE_DEPARTMENT, $atts['template'] );

		if ( null === $template ) {
			return $this->notice(
				sprintf(
					/* translators: %s: the template name used in the shortcode. */
					__( 'Es ist keine Abteilungs-Vorlage vorhanden (angefragt: „%s"). Unter KURABU → Vorlagen lässt sich eine anlegen.', 'kurabu-wp-sync' ),
					'' !== $atts['template'] ? $atts['template'] : 'standard'
				)
			);
		}

		$context           = $this->context()->department_context( $department );
		$context['blocks'] = $this->blocks( $department, $atts );

		return Renderer::render( $template, $context );
	}

	/**
	 * Builds the container blocks of the Abteilung template.
	 *
	 * @param Department            $department The Abteilung.
	 * @param array<string, string> $atts       Normalised attributes.
	 *
	 * @return array<string, string[]>
	 */
	private function blocks( Department $department, array $atts ): array {
		$blocks = array(
			Element::TYPE_TRAININGS => array(),
			Element::TYPE_NEWS      => array(),
			Element::TYPE_EVENTS    => array(),
		);

		if ( $this->is_true( $atts['trainings'], true ) ) {
			$blocks[ Element::TYPE_TRAININGS ] = $this->trainings( $department, $atts );
		}

		if ( $this->is_true( $atts['news'] ) ) {
			/**
			 * Filters the news shown inside an Abteilung.
			 *
			 * KURABU news become WordPress posts, so they are not part of the
			 * local cache. Whoever writes them supplies the rendered entries
			 * here; without that filter the element stays empty and is left
			 * out of the output.
			 *
			 * @param string[]   $news       Rendered news entries.
			 * @param Department $department The Abteilung.
			 */
			$blocks[ Element::TYPE_NEWS ] = (array) apply_filters( 'kurabu_wp_sync_department_news', array(), $department );
		}

		if ( $this->is_true( $atts['events'] ) ) {
			/**
			 * Filters the events shown inside an Abteilung.
			 *
			 * Events live in the club calendar rather than in the local cache,
			 * so the calendar integration supplies the rendered entries here.
			 *
			 * @param string[]   $events     Rendered event entries.
			 * @param Department $department The Abteilung.
			 */
			$blocks[ Element::TYPE_EVENTS ] = (array) apply_filters( 'kurabu_wp_sync_department_events', array(), $department );
		}

		return $blocks;
	}

	/**
	 * Renders the Abteilung's trainings with the Training template.
	 *
	 * @param Department            $department The Abteilung.
	 * @param array<string, string> $atts       Normalised attributes.
	 *
	 * @return string[]
	 */
	private function trainings( Department $department, array $atts ): array {
		$template = TemplateStore::resolve( TemplateStore::TYPE_TRAINING, $atts['training_template'] );

		if ( null === $template ) {
			return array();
		}

		$trainings = plugin()->trainings()->for_department( $department->kurabu_id() );

		if ( 'name' === $atts['orderby'] ) {
			usort(
				$trainings,
				static function ( $a, $b ): int {
					return strnatcasecmp( (string) $a->get( 'name', '' ), (string) $b->get( 'name', '' ) );
				}
			);
		}

		if ( 'DESC' === strtoupper( $atts['order'] ) ) {
			$trainings = array_reverse( $trainings );
		}

		$limit = max( 0, (int) $atts['limit'] );

		if ( $limit > 0 ) {
			$trainings = array_slice( $trainings, 0, $limit );
		}

		return TrainingRenderer::many( $trainings, $template, $this->context() );
	}
}

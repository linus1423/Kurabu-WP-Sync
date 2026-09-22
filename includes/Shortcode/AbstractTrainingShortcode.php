<?php
/**
 * Shared selection logic of the training shortcodes.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Model\Training;
use Kurabu\WPSync\Template\TemplateStore;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Selects Trainings from the local cache.
 *
 * `[kurabu_training]` and `[kurabu_trainings]` differ only in their defaults:
 * both accept id, team, department and location and both identify records by
 * the stable KURABU id, with the slug as a convenience.
 */
abstract class AbstractTrainingShortcode extends AbstractShortcode {

	/**
	 * The attributes both training shortcodes share.
	 *
	 * @return array<string, string>
	 */
	protected function defaults(): array {
		return array(
			'id'         => '',
			'team'       => '',
			'department' => '',
			'location'   => '',
			'template'   => '',
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
			'limit'      => '0',
		);
	}

	/**
	 * Reads the Trainings the attributes select.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 *
	 * @return array{0: Training[], 1: string} Trainings and, when empty, why.
	 */
	protected function select( array $atts ): array {
		$trainings = plugin()->trainings();

		if ( '' !== $atts['id'] ) {
			$training = $trainings->find_by_reference( $atts['id'] );

			if ( null === $training ) {
				return array(
					array(),
					sprintf(
						/* translators: %s: the id used in the shortcode. */
						__( 'Kein Training mit der Kennung „%s" im lokalen Datenbestand.', 'kurabu-wp-sync' ),
						$atts['id']
					),
				);
			}

			/** @var Training[] $found */
			$found = array( $training );

			return array( $found, '' );
		}

		foreach ( array( 'team', 'department', 'location' ) as $relation ) {
			if ( '' === $atts[ $relation ] ) {
				continue;
			}

			$repository = 'team' === $relation ? plugin()->teams() : ( 'department' === $relation ? plugin()->departments() : plugin()->locations() );
			$record     = $repository->find_by_reference( $atts[ $relation ] );

			if ( null === $record ) {
				return array(
					array(),
					sprintf(
						/* translators: 1: shortcode attribute, 2: the value used in the shortcode. */
						__( 'Für %1$s="%2$s" gibt es keinen Datensatz im lokalen Datenbestand.', 'kurabu-wp-sync' ),
						$relation,
						$atts[ $relation ]
					),
				);
			}

			$found = 'team' === $relation
				? $trainings->for_team( $record->kurabu_id() )
				: ( 'department' === $relation
					? $trainings->for_department( $record->kurabu_id() )
					: $trainings->for_location( $record->kurabu_id() ) );

			return array( $this->arrange( $found, $atts ), '' );
		}

		/** @var Training[] $all */
		$all = $trainings->query();

		return array( $this->arrange( $all, $atts ), '' );
	}

	/**
	 * Sorts and limits a result set.
	 *
	 * @param Training[]            $trainings Selected trainings.
	 * @param array<string, string> $atts      Normalised attributes.
	 *
	 * @return Training[]
	 */
	protected function arrange( array $trainings, array $atts ): array {
		if ( 'name' === $atts['orderby'] ) {
			usort(
				$trainings,
				static function ( Training $a, Training $b ): int {
					return strnatcasecmp( (string) $a->get( 'name', '' ), (string) $b->get( 'name', '' ) );
				}
			);
		}

		if ( 'DESC' === strtoupper( $atts['order'] ) ) {
			$trainings = array_reverse( $trainings );
		}

		$limit = max( 0, (int) $atts['limit'] );

		return $limit > 0 ? array_slice( $trainings, 0, $limit ) : $trainings;
	}

	/**
	 * The training template the attributes ask for.
	 *
	 * @param array<string, string> $atts Normalised attributes.
	 */
	protected function template( array $atts ): ?\Kurabu\WPSync\Template\Template {
		return TemplateStore::resolve( TemplateStore::TYPE_TRAINING, $atts['template'] );
	}

	/**
	 * The message shown when the requested template does not exist.
	 *
	 * @param string $key Requested template key.
	 */
	protected function missing_template_notice( string $key ): string {
		return $this->notice(
			sprintf(
				/* translators: %s: the template name used in the shortcode. */
				__( 'Es ist keine Training-Vorlage vorhanden (angefragt: „%s"). Unter KURABU → Vorlagen lässt sich eine anlegen.', 'kurabu-wp-sync' ),
				'' !== $key ? $key : 'standard'
			)
		);
	}
}

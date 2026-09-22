<?php
/**
 * Turns cached records into the values a template renders.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Model\AbstractModel;
use Kurabu\WPSync\Model\Department;
use Kurabu\WPSync\Model\Training;
use Kurabu\WPSync\Template\TemplateStore;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a record and its related records and prepares the template values.
 *
 * This is the shortcode layer's job, not the template engine's: the engine
 * only decides how a value looks, this class decides which records are read
 * (ARCHITECTURE.md). Everything comes from the repositories, so rendering a
 * page never touches the KURABU API.
 */
final class ContextBuilder {

	/**
	 * Related records already read in this request, per repository key.
	 *
	 * @var array<string, array<string, AbstractModel|null>>
	 */
	private array $related = array();

	/**
	 * The values and classes for one Training.
	 *
	 * @param Training $training The training.
	 *
	 * @return array<string, mixed>
	 */
	public function training_context( Training $training ): array {
		$times    = plugin()->training_times()->for_training( $training->kurabu_id() );
		$first    = $times[0] ?? null;
		$location = $this->lookup( 'locations', (string) $training->get( 'location_kurabu_id', '' ) );
		$team     = $this->lookup( 'teams', (string) $training->get( 'team_kurabu_id', '' ) );
		$section  = $this->lookup( 'departments', (string) $training->get( 'department_kurabu_id', '' ) );

		$values = array(
			'name'        => (string) $training->get( 'name', '' ),
			'team'        => null === $team ? '' : (string) $team->get( 'name', '' ),
			'department'  => null === $section ? '' : (string) $section->get( 'name', '' ),
			'weekday'     => null === $first ? '' : Format::weekday( $first->get( 'weekday' ) ),
			'start_time'  => null === $first ? '' : Format::time( $first->get( 'start_time' ) ),
			'end_time'    => null === $first ? '' : Format::time( $first->get( 'end_time' ) ),
			'time'        => null === $first ? '' : Format::time_range( $first->get( 'start_time' ), $first->get( 'end_time' ) ),
			'schedule'    => array(),
			'location'    => null === $location ? '' : (string) $location->get( 'name', '' ),
			'room'        => null === $location ? '' : (string) $location->get( 'room', '' ),
			'address'     => null === $location ? '' : Format::address( $location ),
			'trainer'     => (string) $training->get( 'trainer', '' ),
			'description' => (string) $training->get( 'description', '' ),
			'image'       => (string) $training->get( 'image_url', '' ),
			'link'        => (string) $training->get( 'link', '' ),
		);

		foreach ( $times as $time ) {
			$values['schedule'][] = Format::slot( $time );
		}

		$values['schedule'] = array_values( array_filter( $values['schedule'] ) );

		return array(
			'values'  => $this->filter_values( $values, TemplateStore::TYPE_TRAINING, $training ),
			'classes' => array( 'kurabu-training' ),
		);
	}

	/**
	 * The values and classes for one Abteilung.
	 *
	 * @param Department $department The Abteilung.
	 *
	 * @return array<string, mixed>
	 */
	public function department_context( Department $department ): array {
		$kurabu_id = $department->kurabu_id();

		$teams     = plugin()->teams()->for_department( $kurabu_id );
		$trainings = plugin()->trainings()->for_department( $kurabu_id );

		$locations = array();

		foreach ( $trainings as $training ) {
			$location = $this->lookup( 'locations', (string) $training->get( 'location_kurabu_id', '' ) );

			if ( null !== $location ) {
				$locations[ $location->kurabu_id() ] = (string) $location->get( 'name', '' );
			}
		}

		$values = array(
			'name'          => (string) $department->get( 'name', '' ),
			'description'   => (string) $department->get( 'description', '' ),
			'image'         => (string) $department->get( 'image_url', '' ),
			'teams'         => array_values(
				array_filter(
					array_map(
						static function ( AbstractModel $team ): string {
							return (string) $team->get( 'name', '' );
						},
						$teams
					)
				)
			),
			'locations'     => array_values( array_filter( $locations ) ),
			'contact_name'  => (string) $department->get( 'contact_name', '' ),
			'contact_email' => (string) $department->get( 'contact_email', '' ),
			'contact_phone' => (string) $department->get( 'contact_phone', '' ),
			'link'          => (string) $department->get( 'link', '' ),
		);

		return array(
			'values'  => $this->filter_values( $values, TemplateStore::TYPE_DEPARTMENT, $department ),
			'classes' => array( 'kurabu-department' ),
		);
	}

	/**
	 * Lets other code add or correct prepared values.
	 *
	 * A field added through `kurabu_wp_sync_template_fields` becomes usable by
	 * supplying its value here.
	 *
	 * @param array<string, mixed> $values Prepared values.
	 * @param string               $type   Record type.
	 * @param AbstractModel        $record The record the values came from.
	 *
	 * @return array<string, mixed>
	 */
	private function filter_values( array $values, string $type, AbstractModel $record ): array {
		/**
		 * Filters the values handed to the template engine.
		 *
		 * @param array<string, mixed> $values Prepared values.
		 * @param string               $type   Record type.
		 * @param AbstractModel        $record The record.
		 */
		return (array) apply_filters( 'kurabu_wp_sync_template_values', $values, $type, $record );
	}

	/**
	 * Reads a related record once per request.
	 *
	 * @param string $repository One of departments, teams, locations.
	 * @param string $kurabu_id  Stable KURABU id, may be empty.
	 */
	private function lookup( string $repository, string $kurabu_id ): ?AbstractModel {
		if ( '' === $kurabu_id ) {
			return null;
		}

		if ( ! isset( $this->related[ $repository ][ $kurabu_id ] ) ) {
			switch ( $repository ) {
				case 'teams':
					$record = plugin()->teams()->find_by_kurabu_id( $kurabu_id );
					break;

				case 'locations':
					$record = plugin()->locations()->find_by_kurabu_id( $kurabu_id );
					break;

				default:
					$record = plugin()->departments()->find_by_kurabu_id( $kurabu_id );
			}

			$this->related[ $repository ][ $kurabu_id ] = $record;
		}

		return $this->related[ $repository ][ $kurabu_id ];
	}
}

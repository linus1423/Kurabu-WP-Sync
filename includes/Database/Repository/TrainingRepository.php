<?php
/**
 * Repository for Trainings.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\Training;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Trainings.
 */
final class TrainingRepository extends AbstractRepository {

	protected function table_name(): string {
		return Schema::TRAININGS;
	}

	protected function model_class(): string {
		return Training::class;
	}

	/**
	 * All Trainings of one Abteilung.
	 *
	 * @param string $department_kurabu_id Stable KURABU id of the Abteilung.
	 *
	 * @return \Kurabu\WPSync\Model\Training[]
	 */
	public function for_department( string $department_kurabu_id ): array {
		/** @var \Kurabu\WPSync\Model\Training[] $trainings */
		$trainings = $this->query(
			array(
				'where' => array( 'department_kurabu_id' => $department_kurabu_id ),
			)
		);

		return $trainings;
	}

	/**
	 * All Trainings of one Trainingsgruppe.
	 *
	 * @param string $team_kurabu_id Stable KURABU id of the Trainingsgruppe.
	 *
	 * @return \Kurabu\WPSync\Model\Training[]
	 */
	public function for_team( string $team_kurabu_id ): array {
		/** @var \Kurabu\WPSync\Model\Training[] $trainings */
		$trainings = $this->query(
			array(
				'where' => array( 'team_kurabu_id' => $team_kurabu_id ),
			)
		);

		return $trainings;
	}

	/**
	 * All Trainings at one Trainingsort.
	 *
	 * @param string $location_kurabu_id Stable KURABU id of the Trainingsort.
	 *
	 * @return \Kurabu\WPSync\Model\Training[]
	 */
	public function for_location( string $location_kurabu_id ): array {
		/** @var \Kurabu\WPSync\Model\Training[] $trainings */
		$trainings = $this->query(
			array(
				'where' => array( 'location_kurabu_id' => $location_kurabu_id ),
			)
		);

		return $trainings;
	}
}

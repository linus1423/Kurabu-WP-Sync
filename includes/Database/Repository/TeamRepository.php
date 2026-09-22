<?php
/**
 * Repository for Teams / Trainingsgruppen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Trainingsgruppen.
 */
final class TeamRepository extends AbstractRepository {

	protected function table_name(): string {
		return Schema::TEAMS;
	}

	protected function model_class(): string {
		return Team::class;
	}

	/**
	 * All Trainingsgruppen of one Abteilung.
	 *
	 * @param string $department_kurabu_id Stable KURABU id of the Abteilung.
	 *
	 * @return \Kurabu\WPSync\Model\Team[]
	 */
	public function for_department( string $department_kurabu_id ): array {
		/** @var \Kurabu\WPSync\Model\Team[] $teams */
		$teams = $this->query(
			array(
				'where' => array( 'department_kurabu_id' => $department_kurabu_id ),
			)
		);

		return $teams;
	}
}

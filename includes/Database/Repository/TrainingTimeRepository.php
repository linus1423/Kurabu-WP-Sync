<?php
/**
 * Repository for Trainingszeiten.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\TrainingTime;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Trainingszeiten.
 */
final class TrainingTimeRepository extends AbstractRepository {

	protected function table_name(): string {
		return Schema::TRAINING_TIMES;
	}

	protected function model_class(): string {
		return TrainingTime::class;
	}

	/**
	 * All Trainingszeiten of one Training, in weekly order.
	 *
	 * @param string $training_kurabu_id Stable KURABU id of the Training.
	 *
	 * @return \Kurabu\WPSync\Model\TrainingTime[]
	 */
	public function for_training( string $training_kurabu_id ): array {
		/** @var \Kurabu\WPSync\Model\TrainingTime[] $times */
		$times = $this->query(
			array(
				'where'   => array( 'training_kurabu_id' => $training_kurabu_id ),
				'orderby' => 'weekday',
				'order'   => 'ASC',
			)
		);

		usort(
			$times,
			static function ( $a, $b ): int {
				return array( (int) $a->get( 'weekday', 0 ), (string) $a->get( 'start_time', '' ) )
					<=> array( (int) $b->get( 'weekday', 0 ), (string) $b->get( 'start_time', '' ) );
			}
		);

		return $times;
	}
}

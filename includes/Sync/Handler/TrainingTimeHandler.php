<?php
/**
 * Trainingszeiten handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TrainingTimeRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Trainingszeiten into the local cache.
 */
final class TrainingTimeHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::TRAINING_TIMES;
	}

	protected function repository(): TrainingTimeRepository {
		return plugin()->training_times();
	}
}

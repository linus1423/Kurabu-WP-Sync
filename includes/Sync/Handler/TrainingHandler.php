<?php
/**
 * Trainings handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TrainingRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Trainings into the local cache.
 */
final class TrainingHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::TRAININGS;
	}

	protected function repository(): TrainingRepository {
		return plugin()->trainings();
	}
}

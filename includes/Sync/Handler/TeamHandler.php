<?php
/**
 * Teams / Trainingsgruppen handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\TeamRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Teams / Trainingsgruppen into the local cache.
 */
final class TeamHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::TEAMS;
	}

	protected function repository(): TeamRepository {
		return plugin()->teams();
	}
}

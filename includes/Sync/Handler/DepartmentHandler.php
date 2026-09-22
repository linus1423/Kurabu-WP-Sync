<?php
/**
 * Abteilungen / Sportarten handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\DepartmentRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Abteilungen / Sportarten into the local cache.
 */
final class DepartmentHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::DEPARTMENTS;
	}

	protected function repository(): DepartmentRepository {
		return plugin()->departments();
	}
}

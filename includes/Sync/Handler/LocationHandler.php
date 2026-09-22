<?php
/**
 * Trainingsorte handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\LocationRepository;
use Kurabu\WPSync\Sync\Resource;

use function Kurabu\WPSync\plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs Trainingsorte into the local cache.
 */
final class LocationHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::LOCATIONS;
	}

	protected function repository(): LocationRepository {
		return plugin()->locations();
	}
}

<?php
/**
 * Repository for Trainingsorte.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\Location;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Trainingsorte.
 */
final class LocationRepository extends AbstractRepository {

	protected function table_name(): string {
		return Schema::LOCATIONS;
	}

	protected function model_class(): string {
		return Location::class;
	}
}

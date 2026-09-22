<?php
/**
 * Repository for Abteilungen / Sportarten.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\Department;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes cached Abteilungen.
 */
final class DepartmentRepository extends AbstractRepository {

	protected function table_name(): string {
		return Schema::DEPARTMENTS;
	}

	protected function model_class(): string {
		return Department::class;
	}
}

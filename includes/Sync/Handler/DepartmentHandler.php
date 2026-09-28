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
 *
 * KURABU calls them categories and splits the contact person into first and
 * last name, which are joined into the one field the cache has.
 */
final class DepartmentHandler extends AbstractRepositoryHandler {

	public function resource(): string {
		return Resource::DEPARTMENTS;
	}

	protected function repository(): DepartmentRepository {
		return plugin()->departments();
	}

	/**
	 * Joins the contact's first and last name.
	 *
	 * @param array<string, mixed>     $data   Column values.
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare( array $data, array $record ): array {
		if ( '' !== (string) ( $data['contact_name'] ?? '' ) ) {
			return $data;
		}

		$contact = $record['contact'] ?? array();

		if ( ! is_array( $contact ) ) {
			return $data;
		}

		$first = isset( $contact['first_name'] ) && is_scalar( $contact['first_name'] ) ? trim( (string) $contact['first_name'] ) : '';
		$last  = isset( $contact['last_name'] ) && is_scalar( $contact['last_name'] ) ? trim( (string) $contact['last_name'] ) : '';

		$data['contact_name'] = sanitize_text_field( trim( $first . ' ' . $last ) );

		return $data;
	}
}

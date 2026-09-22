<?php
/**
 * Base handler for the resources cached in own tables.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\Repository\AbstractRepository;
use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Sync\Client;
use Kurabu\WPSync\Sync\Mapping\Definition;
use Kurabu\WPSync\Sync\Mapping\RecordMapper;
use Kurabu\WPSync\Sync\ResourceResult;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the API records onto the columns of one cache table.
 *
 * Writing goes through the repository's upsert(), which compares checksums, so
 * an unchanged record does not touch the row. Records KURABU no longer lists
 * are pruned, but only after a complete run: an incremental run does not know
 * the full list and must never delete.
 */
abstract class AbstractRepositoryHandler implements Handler {

	/**
	 * Types whose column accepts NULL.
	 */
	private const NULLABLE_TYPES = array(
		Definition::TYPE_DATE,
		Definition::TYPE_TIME,
		Definition::TYPE_DATETIME,
		Definition::TYPE_FLOAT,
		Definition::TYPE_HTML,
		Definition::TYPE_URL,
	);

	/**
	 * The repository this handler writes into.
	 */
	abstract protected function repository(): AbstractRepository;

	/**
	 * Fetches and stores the resource.
	 *
	 * @param Client      $client The API client.
	 * @param Logger      $logger Logger of the current run.
	 * @param string|null $since  Incremental cut-off, or null.
	 */
	public function sync( Client $client, Logger $logger, ?string $since ): ResourceResult {
		$resource = $this->resource();
		$records  = $client->fetch( $resource, $since );

		$repository = $this->repository();
		$fields     = Definition::fields( $resource );
		$written    = 0;
		$skipped    = 0;
		$seen       = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				$skipped++;
				continue;
			}

			$mapped    = RecordMapper::map( $resource, $record );
			$kurabu_id = isset( $mapped['kurabu_id'] ) ? (string) $mapped['kurabu_id'] : '';

			if ( '' === $kurabu_id ) {
				$skipped++;

				$logger->warning(
					__( 'Ein Datensatz ohne KURABU-ID wurde übersprungen.', 'kurabu-wp-sync' ),
					$resource,
					array( 'record_keys' => array_slice( array_map( 'strval', array_keys( $record ) ), 0, 20 ) )
				);

				continue;
			}

			$data = $this->to_columns( $mapped, $fields );

			if ( '' === (string) ( $data['slug'] ?? '' ) && isset( $data['name'] ) ) {
				$data['slug'] = sanitize_title( (string) $data['name'] );
			}

			$data['payload'] = $record;
			$data            = $this->prepare( $data, $record );

			if ( $repository->upsert( $kurabu_id, $data ) > 0 ) {
				$written++;
				$seen[] = $kurabu_id;
			} else {
				$skipped++;

				$logger->warning(
					sprintf(
						/* translators: %s: KURABU id. */
						__( 'Datensatz %s konnte nicht gespeichert werden.', 'kurabu-wp-sync' ),
						$kurabu_id
					),
					$resource
				);
			}
		}

		$removed = 0;

		// Only a complete run knows the full list, so only it may delete.
		if ( null === $since && $seen ) {
			$removed = $repository->prune( $seen );
		}

		return ResourceResult::success( $resource, count( $records ), $written, $removed, $skipped, null !== $since );
	}

	/**
	 * Last chance to adjust the column values before they are written.
	 *
	 * @param array<string, mixed>     $data   Column values.
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare( array $data, array $record ): array {
		unset( $record );

		return $data;
	}

	/**
	 * Turns mapped values into column values.
	 *
	 * A field the API did not deliver becomes the column's empty value rather
	 * than NULL, unless the column accepts NULL. That keeps a value that
	 * disappeared in KURABU from lingering in the cache.
	 *
	 * @param array<string, mixed>                                                    $mapped Mapped values.
	 * @param array<string, array{label: string, type: string, candidates: string[]}> $fields Field definitions.
	 *
	 * @return array<string, mixed>
	 */
	private function to_columns( array $mapped, array $fields ): array {
		$columns = array();

		foreach ( $mapped as $field => $value ) {
			$type = (string) ( $fields[ $field ]['type'] ?? Definition::TYPE_TEXT );

			if ( null !== $value ) {
				$columns[ $field ] = $value;
				continue;
			}

			if ( in_array( $type, self::NULLABLE_TYPES, true ) ) {
				$columns[ $field ] = null;
				continue;
			}

			$columns[ $field ] = in_array( $type, array( Definition::TYPE_INT, Definition::TYPE_WEEKDAY ), true ) ? 0 : '';
		}

		return $columns;
	}
}

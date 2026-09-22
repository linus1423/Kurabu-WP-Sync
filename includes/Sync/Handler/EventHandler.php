<?php
/**
 * Events handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\ObjectMap;
use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Sync\Calendar\CalendarRegistry;
use Kurabu\WPSync\Sync\Client;
use Kurabu\WPSync\Sync\Mapping\RecordMapper;
use Kurabu\WPSync\Sync\Resource;
use Kurabu\WPSync\Sync\ResourceResult;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes KURABU events into the calendar the site selected.
 *
 * Like the news, events live in WordPress objects rather than an own table, so
 * ObjectMap is what keeps a second run from creating duplicates. Without a
 * configured calendar the resource is skipped instead of failing: the rest of
 * the run is unaffected and the backend says what is missing.
 */
final class EventHandler implements Handler {

	public function resource(): string {
		return Resource::EVENTS;
	}

	/**
	 * Fetches the events and writes them into the calendar.
	 *
	 * @param Client      $client The API client.
	 * @param Logger      $logger Logger of the current run.
	 * @param string|null $since  Incremental cut-off, or null.
	 */
	public function sync( Client $client, Logger $logger, ?string $since ): ResourceResult {
		$adapter = CalendarRegistry::active();

		if ( null === $adapter ) {
			$message = __( 'Kein Kalenderziel konfiguriert; Events wurden übersprungen.', 'kurabu-wp-sync' );

			$logger->warning( $message, $this->resource() );

			return ResourceResult::skipped_resource( $this->resource(), $message );
		}

		$records = $client->fetch( $this->resource(), $since );
		$written = 0;
		$skipped = 0;

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				$skipped++;
				continue;
			}

			$mapped    = RecordMapper::map( $this->resource(), $record );
			$kurabu_id = (string) ( $mapped['kurabu_id'] ?? '' );

			if ( '' === $kurabu_id ) {
				$skipped++;

				$logger->warning(
					__( 'Ein Event ohne KURABU-ID wurde übersprungen.', 'kurabu-wp-sync' ),
					$this->resource()
				);

				continue;
			}

			$checksum = md5( (string) wp_json_encode( $record ) );
			$mapping  = ObjectMap::get( ObjectMap::TYPE_EVENT, $kurabu_id );
			$entry_id = null !== $mapping ? (int) $mapping['wp_object_id'] : 0;

			if ( $entry_id > 0 && null === get_post( $entry_id ) ) {
				ObjectMap::unlink( ObjectMap::TYPE_EVENT, $kurabu_id );

				$entry_id = 0;
				$mapping  = null;
			}

			if ( $entry_id > 0 && null !== $mapping && $checksum === (string) $mapping['checksum'] ) {
				ObjectMap::link( ObjectMap::TYPE_EVENT, $kurabu_id, $entry_id, $adapter->object_type(), $checksum );

				continue;
			}

			$result = $adapter->upsert( $mapped, $entry_id );

			if ( $result instanceof WP_Error || 0 === $result ) {
				$skipped++;

				$logger->error(
					sprintf(
						/* translators: %s: KURABU id. */
						__( 'Der Event zu KURABU-ID %s konnte nicht in den Kalender geschrieben werden.', 'kurabu-wp-sync' ),
						$kurabu_id
					),
					$this->resource(),
					array( 'error' => $result instanceof WP_Error ? $result->get_error_message() : '' )
				);

				continue;
			}

			update_post_meta( $result, NewsHandler::META_KURABU_ID, $kurabu_id );
			update_post_meta( $result, '_kurabu_resource', $this->resource() );

			ObjectMap::link( ObjectMap::TYPE_EVENT, $kurabu_id, $result, $adapter->object_type(), $checksum );

			$written++;
		}

		return ResourceResult::success( $this->resource(), count( $records ), $written, 0, $skipped, null !== $since );
	}
}

<?php
/**
 * Beiträge / News handler.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Handler;

use Kurabu\WPSync\Database\ObjectMap;
use Kurabu\WPSync\Support\Logger;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Client;
use Kurabu\WPSync\Sync\Mapping\RecordMapper;
use Kurabu\WPSync\Sync\Resource;
use Kurabu\WPSync\Sync\ResourceResult;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes KURABU news into WordPress posts.
 *
 * News are not cached in an own table: they become real posts, so themes,
 * search and feeds treat them like any other post. ObjectMap remembers which
 * post belongs to which KURABU id, which is what makes the second run update
 * instead of duplicate.
 *
 * Posts are never deleted here. A news item that disappears from KURABU leaves
 * its post in place, because the site may already link to it.
 */
final class NewsHandler implements Handler {

	/**
	 * Meta key carrying the stable KURABU id on the post.
	 */
	public const META_KURABU_ID = '_kurabu_id';

	public function resource(): string {
		return Resource::NEWS;
	}

	/**
	 * Fetches the news and writes them into posts.
	 *
	 * @param Client      $client The API client.
	 * @param Logger      $logger Logger of the current run.
	 * @param string|null $since  Incremental cut-off, or null.
	 */
	public function sync( Client $client, Logger $logger, ?string $since ): ResourceResult {
		$records = $client->fetch( $this->resource(), $since );

		$post_type = $this->post_type();
		$written   = 0;
		$skipped   = 0;

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
					__( 'Eine News ohne KURABU-ID wurde übersprungen.', 'kurabu-wp-sync' ),
					$this->resource()
				);

				continue;
			}

			$checksum = md5( (string) wp_json_encode( $record ) );
			$mapping  = ObjectMap::get( ObjectMap::TYPE_POST, $kurabu_id );
			$post_id  = null !== $mapping ? (int) $mapping['wp_object_id'] : 0;
			$post     = $post_id > 0 ? get_post( $post_id ) : null;

			if ( $post_id > 0 && null === $post ) {
				// The post was deleted for good; forget it and create a new one.
				ObjectMap::unlink( ObjectMap::TYPE_POST, $kurabu_id );

				$post_id = 0;
				$mapping = null;
			}

			if ( null !== $post && 'trash' === $post->post_status ) {
				$skipped++;

				$logger->info(
					sprintf(
						/* translators: %s: post title. */
						__( 'Der Beitrag "%s" liegt im Papierkorb und wurde nicht aktualisiert.', 'kurabu-wp-sync' ),
						$post->post_title
					),
					$this->resource(),
					array( 'kurabu_id' => $kurabu_id )
				);

				continue;
			}

			if ( $post_id > 0 && null !== $mapping && $checksum === (string) $mapping['checksum'] ) {
				// Unchanged in KURABU: only record that this run saw it.
				ObjectMap::link( ObjectMap::TYPE_POST, $kurabu_id, $post_id, $post_type, $checksum );

				continue;
			}

			$result = $this->write_post( $post_id, $post_type, $mapped );

			if ( $result instanceof WP_Error || 0 === $result ) {
				$skipped++;

				$logger->error(
					sprintf(
						/* translators: %s: KURABU id. */
						__( 'Der Beitrag zu KURABU-ID %s konnte nicht gespeichert werden.', 'kurabu-wp-sync' ),
						$kurabu_id
					),
					$this->resource(),
					array( 'error' => $result instanceof WP_Error ? $result->get_error_message() : '' )
				);

				continue;
			}

			$this->write_meta( $result, $kurabu_id, $mapped );

			ObjectMap::link( ObjectMap::TYPE_POST, $kurabu_id, $result, $post_type, $checksum );

			$written++;
		}

		return ResourceResult::success( $this->resource(), count( $records ), $written, 0, $skipped, null !== $since );
	}

	/**
	 * Inserts or updates the post of one news item.
	 *
	 * @param int                  $post_id   Existing post id, or 0.
	 * @param string               $post_type Target post type.
	 * @param array<string, mixed> $mapped    Mapped values.
	 *
	 * @return int|WP_Error The post id, or the error.
	 */
	private function write_post( int $post_id, string $post_type, array $mapped ) {
		$title = (string) ( $mapped['title'] ?? '' );

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => '' !== $title ? $title : __( '(ohne Titel)', 'kurabu-wp-sync' ),
			'post_content' => (string) ( $mapped['content'] ?? '' ),
			'post_excerpt' => (string) ( $mapped['excerpt'] ?? '' ),
			'post_status'  => $this->post_status(),
		);

		$published = (string) ( $mapped['published_at'] ?? '' );

		if ( '' !== $published ) {
			$postarr['post_date'] = $published;
		}

		$author = (int) Settings::get( 'news_author', 0 );

		if ( $author > 0 ) {
			$postarr['post_author'] = $author;
		}

		$category = (int) Settings::get( 'news_category', 0 );

		if ( $category > 0 && 'post' === $post_type ) {
			$postarr['post_category'] = array( $category );
		}

		/**
		 * Filters the post array of a synced news item.
		 *
		 * @param array<string, mixed> $postarr Post data.
		 * @param array<string, mixed> $mapped  Mapped KURABU values.
		 */
		$postarr = (array) apply_filters( 'kurabu_wp_sync_news_postarr', $postarr, $mapped );

		if ( $post_id > 0 ) {
			$postarr['ID'] = $post_id;

			$result = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		return $result instanceof WP_Error ? $result : (int) $result;
	}

	/**
	 * Stores the KURABU references on the post.
	 *
	 * The image is kept as a URL rather than imported into the media library:
	 * the sync runs unattended and must not grow the uploads folder on every
	 * pass. The template layer can use the URL directly.
	 *
	 * @param int                  $post_id   Post id.
	 * @param string               $kurabu_id Stable KURABU id.
	 * @param array<string, mixed> $mapped    Mapped values.
	 */
	private function write_meta( int $post_id, string $kurabu_id, array $mapped ): void {
		update_post_meta( $post_id, self::META_KURABU_ID, $kurabu_id );
		update_post_meta( $post_id, '_kurabu_resource', $this->resource() );

		$optional = array(
			'_kurabu_link'          => (string) ( $mapped['link'] ?? '' ),
			'_kurabu_image_url'     => (string) ( $mapped['image_url'] ?? '' ),
			'_kurabu_department_id' => (string) ( $mapped['department_kurabu_id'] ?? '' ),
		);

		foreach ( $optional as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
				continue;
			}

			delete_post_meta( $post_id, $key );
		}
	}

	/**
	 * The post type news are written into.
	 */
	private function post_type(): string {
		$post_type = (string) Settings::get( 'news_post_type', 'post' );

		return post_type_exists( $post_type ) ? $post_type : 'post';
	}

	/**
	 * The status synced posts get.
	 */
	private function post_status(): string {
		$status  = (string) Settings::get( 'news_post_status', 'publish' );
		$allowed = array( 'publish', 'draft', 'pending', 'private' );

		return in_array( $status, $allowed, true ) ? $status : 'publish';
	}
}

<?php
/**
 * Generic calendar adapter writing into a post type.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Calendar;

use Kurabu\WPSync\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes events into a configurable post type with configurable meta keys.
 *
 * This is the fallback for calendar plugins without an own API: the post type
 * and the meta keys for start, end and location are set on the
 * "Kalenderintegration" screen, so most calendars can be fed without code.
 */
final class PostTypeAdapter implements CalendarAdapter {

	public const KEY = 'post_type';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'Beitragstyp mit eigenen Feldern', 'kurabu-wp-sync' );
	}

	public function description(): string {
		return __( 'Schreibt jeden Event in einen frei wählbaren Beitragstyp und legt Beginn, Ende und Ort in benannten Custom Fields ab.', 'kurabu-wp-sync' );
	}

	public function is_available(): bool {
		return post_type_exists( $this->post_type() );
	}

	public function object_type(): string {
		return $this->post_type();
	}

	/**
	 * Creates or updates the event post.
	 *
	 * @param array<string, mixed> $event       Mapped KURABU values.
	 * @param int                  $existing_id Post id, or 0.
	 *
	 * @return int|WP_Error
	 */
	public function upsert( array $event, int $existing_id ) {
		$title = (string) ( $event['title'] ?? '' );

		$postarr = array(
			'post_type'    => $this->post_type(),
			'post_title'   => '' !== $title ? $title : __( '(ohne Titel)', 'kurabu-wp-sync' ),
			'post_content' => (string) ( $event['description'] ?? '' ),
			'post_status'  => 'publish',
		);

		if ( $existing_id > 0 ) {
			$postarr['ID'] = $existing_id;

			$result = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$post_id = (int) $result;

		if ( 0 === $post_id ) {
			return new WP_Error( 'kurabu_event_not_saved', __( 'Der Event konnte nicht gespeichert werden.', 'kurabu-wp-sync' ) );
		}

		$this->write_meta( $post_id, $event );

		return $post_id;
	}

	/**
	 * Stores start, end and location in the configured meta keys.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $event   Mapped values.
	 */
	private function write_meta( int $post_id, array $event ): void {
		$map = array(
			(string) Settings::get( 'event_meta_start', '_kurabu_event_start' )       => (string) ( $event['start'] ?? '' ),
			(string) Settings::get( 'event_meta_end', '_kurabu_event_end' )           => (string) ( $event['end'] ?? '' ),
			(string) Settings::get( 'event_meta_location', '_kurabu_event_location' ) => (string) ( $event['location_name'] ?? '' ),
		);

		foreach ( $map as $key => $value ) {
			if ( '' === trim( $key ) ) {
				continue;
			}

			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
				continue;
			}

			delete_post_meta( $post_id, $key );
		}

		update_post_meta( $post_id, '_kurabu_event_all_day', ! empty( $event['all_day'] ) ? '1' : '0' );
	}

	/**
	 * The configured post type, defaulting to the built-in post type.
	 */
	private function post_type(): string {
		$post_type = trim( (string) Settings::get( 'event_post_type', '' ) );

		return '' !== $post_type ? $post_type : 'post';
	}
}

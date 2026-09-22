<?php
/**
 * Caches the rendered output of a shortcode.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Shortcode;

use Kurabu\WPSync\Database\SyncState;
use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Template\TemplateStore;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps rendered shortcode output in a transient for a short while.
 *
 * The cache key contains the template revision and the newest successful sync
 * timestamp, so an edited template or a finished sync run invalidates the
 * entries by itself instead of needing to be cleared. Administrators bypass
 * the cache and always see the current state.
 */
final class RenderCache {

	private const PREFIX = 'kurabu_sc_';

	/**
	 * Default lifetime of an entry, in seconds.
	 */
	private const TTL = 600;

	/**
	 * The newest successful sync, read once per request.
	 */
	private static ?string $sync_stamp = null;

	/**
	 * Returns cached output, or null when there is none.
	 *
	 * @param string                $tag  Shortcode tag.
	 * @param array<string, string> $atts Normalised attributes.
	 */
	public static function get( string $tag, array $atts ): ?string {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$cached = get_transient( self::key( $tag, $atts ) );

		return is_string( $cached ) ? $cached : null;
	}

	/**
	 * Stores rendered output.
	 *
	 * @param string                $tag  Shortcode tag.
	 * @param array<string, string> $atts Normalised attributes.
	 * @param string                $html Rendered output.
	 */
	public static function set( string $tag, array $atts, string $html ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		set_transient( self::key( $tag, $atts ), $html, self::ttl() );
	}

	/**
	 * Whether output caching applies to this request.
	 */
	private static function is_enabled(): bool {
		if ( self::ttl() <= 0 ) {
			return false;
		}

		// Whoever may edit the templates sees their change immediately.
		return ! is_user_logged_in() || ! current_user_can( Plugin::CAPABILITY );
	}

	/**
	 * The configured lifetime of an entry.
	 */
	private static function ttl(): int {
		/**
		 * Filters how long rendered shortcode output is cached.
		 *
		 * Returning 0 or less switches the cache off; the shortcodes then read
		 * the local tables on every page view.
		 *
		 * @param int $ttl Lifetime in seconds.
		 */
		return (int) apply_filters( 'kurabu_wp_sync_shortcode_cache_ttl', self::TTL );
	}

	/**
	 * Builds the cache key.
	 *
	 * @param string                $tag  Shortcode tag.
	 * @param array<string, string> $atts Normalised attributes.
	 */
	private static function key( string $tag, array $atts ): string {
		ksort( $atts );

		return self::PREFIX . md5(
			(string) wp_json_encode(
				array(
					'tag'    => $tag,
					'atts'   => $atts,
					'stamp'  => self::stamp(),
					'locale' => determine_locale(),
				)
			)
		);
	}

	/**
	 * Identifies the current data and template state.
	 *
	 * The template revision is read every time, so a template saved during
	 * this request invalidates the entries right away; the sync timestamp
	 * costs a query and is therefore read only once.
	 */
	private static function stamp(): string {
		return TemplateStore::revision() . '|' . self::sync_stamp();
	}

	/**
	 * The newest successful sync of any resource.
	 */
	private static function sync_stamp(): string {
		if ( null !== self::$sync_stamp ) {
			return self::$sync_stamp;
		}

		$last_success = '';

		foreach ( SyncState::all() as $state ) {
			$candidate = (string) ( $state['last_success_at'] ?? '' );

			if ( $candidate > $last_success ) {
				$last_success = $candidate;
			}
		}

		self::$sync_stamp = $last_success;

		return self::$sync_stamp;
	}
}

<?php
/**
 * Guard against overlapping sync runs.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Makes sure only one run works at a time.
 *
 * With a five minute interval a slow run could still be working when the next
 * one starts, and two runs writing the same rows would fight over them. The
 * lock is an option rather than a transient, because add_option() fails on a
 * duplicate key and therefore cannot hand the lock to two runs at once.
 */
final class Lock {

	public const OPTION = 'kurabu_wp_sync_lock';

	/**
	 * How long a lock is honoured before it counts as abandoned.
	 */
	private const TTL = 900;

	/**
	 * Tries to take the lock.
	 *
	 * @return bool False when another run holds it.
	 */
	public static function acquire(): bool {
		if ( add_option( self::OPTION, (string) time(), '', 'no' ) ) {
			return true;
		}

		$held_since = (int) get_option( self::OPTION, 0 );

		// A run that died mid-way would block every later run, so an old lock
		// is taken over.
		if ( $held_since > 0 && ( time() - $held_since ) < self::TTL ) {
			return false;
		}

		update_option( self::OPTION, (string) time(), false );

		return true;
	}

	/**
	 * Releases the lock.
	 */
	public static function release(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a run currently holds the lock.
	 */
	public static function is_held(): bool {
		$held_since = (int) get_option( self::OPTION, 0 );

		return $held_since > 0 && ( time() - $held_since ) < self::TTL;
	}
}

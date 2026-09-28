<?php
/**
 * Reader for one KURABU calendar event.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Mapping;

use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Knows how a calendar event of the KURABU public API is put together.
 *
 * The API has no endpoint for trainings or training times: a weekly training
 * is a recurring calendar event that carries a `team_id`, and its weekday sits
 * in the iCalendar `rrule` rather than in a field of its own. This class is the
 * single place that reads that shape, so the handlers stay free of it.
 *
 * Unlike the field mapping, these values cannot be pinned in the backend: they
 * are not one field each but derived from several (`start_date` plus
 * `start_time`, the `BYDAY` part of an `rrule`).
 *
 * @link https://tv1848coburg.kurabu.com/api/public/v1/docs
 */
final class CalendarEvent {

	/**
	 * How a training is recognised: team plus recurrence.
	 */
	public const RULE_TEAM_RECURRING = 'team_recurring';

	/**
	 * How a training is recognised: any event of a team.
	 */
	public const RULE_TEAM = 'team';

	/**
	 * How a training is recognised: any recurring event.
	 */
	public const RULE_RECURRING = 'recurring';

	/**
	 * The iCalendar weekday abbreviations, as ISO numbers.
	 *
	 * @var array<string, int>
	 */
	private const BYDAY = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);

	/**
	 * Whether a record is a training rather than a one-off event.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function is_training( array $record ): bool {
		$has_team  = '' !== self::text( $record, 'team_id' );
		$recurring = ! empty( $record['is_recurring'] ) || '' !== self::text( $record, 'rrule' );

		switch ( self::rule() ) {
			case self::RULE_TEAM:
				$is_training = $has_team;
				break;
			case self::RULE_RECURRING:
				$is_training = $recurring;
				break;
			case self::RULE_TEAM_RECURRING:
			default:
				$is_training = $has_team && $recurring;
				break;
		}

		/**
		 * Filters whether a calendar event counts as a training.
		 *
		 * @param bool                     $is_training Whether it is a training.
		 * @param array<string|int, mixed> $record      Raw KURABU record.
		 */
		return (bool) apply_filters( 'kurabu_wp_sync_is_training', $is_training, $record );
	}

	/**
	 * Whether a record may appear on a public website.
	 *
	 * An unpublished event, or one visible to members or participants only, is
	 * dropped rather than shown by accident. A site that wants everything
	 * switches "Nur öffentliche Daten" off.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function is_public( array $record ): bool {
		if ( ! Settings::get( 'public_only', true ) ) {
			return true;
		}

		if ( array_key_exists( 'published', $record ) && ! $record['published'] ) {
			return false;
		}

		$visibility = strtoupper( self::text( $record, 'visibility' ) );

		return '' === $visibility || 'PUBLIC' === $visibility;
	}

	/**
	 * The configured rule for telling trainings from events.
	 */
	public static function rule(): string {
		$rule = (string) Settings::get( 'training_rule', self::RULE_TEAM_RECURRING );

		return in_array( $rule, self::rules(), true ) ? $rule : self::RULE_TEAM_RECURRING;
	}

	/**
	 * Every rule key.
	 *
	 * @return string[]
	 */
	public static function rules(): array {
		return array( self::RULE_TEAM_RECURRING, self::RULE_TEAM, self::RULE_RECURRING );
	}

	/**
	 * Translated labels for the rules.
	 *
	 * @return array<string, string>
	 */
	public static function rule_labels(): array {
		return array(
			self::RULE_TEAM_RECURRING => __( 'Wiederkehrende Termine einer Trainingsgruppe', 'kurabu-wp-sync' ),
			self::RULE_TEAM           => __( 'Alle Termine einer Trainingsgruppe', 'kurabu-wp-sync' ),
			self::RULE_RECURRING      => __( 'Alle wiederkehrenden Termine', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * The start as `Y-m-d H:i:s`, or an empty string.
	 *
	 * KURABU splits a start into `start_date` and `start_time`; an all day
	 * event has no time at all.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function start( array $record ): string {
		return self::moment( self::text( $record, 'start_date' ), self::text( $record, 'start_time' ) );
	}

	/**
	 * The end as `Y-m-d H:i:s`, or an empty string.
	 *
	 * A training that ends on the day it starts carries no `end_date`, so the
	 * start date is used with the end time.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function end( array $record ): string {
		$date = self::text( $record, 'end_date' );
		$time = self::text( $record, 'end_time' );

		if ( '' === $date && '' === $time ) {
			return '';
		}

		if ( '' === $date ) {
			$date = self::text( $record, 'start_date' );
		}

		return self::moment( $date, $time );
	}

	/**
	 * The weekdays a training takes place on, as ISO numbers.
	 *
	 * A recurring event lists them in the `BYDAY` part of its rrule; a single
	 * event has the weekday of its start date.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 *
	 * @return int[]
	 */
	public static function weekdays( array $record ): array {
		$weekdays = array();

		foreach ( self::rrule_part( $record, 'BYDAY' ) as $day ) {
			// A BYDAY entry may carry an ordinal, as in "2MO" for the second
			// Monday of the month.
			$day = strtoupper( preg_replace( '/[^A-Za-z]/', '', $day ) ?? '' );

			if ( isset( self::BYDAY[ $day ] ) ) {
				$weekdays[] = self::BYDAY[ $day ];
			}
		}

		if ( ! $weekdays ) {
			$start = self::text( $record, 'start_date' );

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
				$weekdays[] = (int) gmdate( 'N', (int) strtotime( $start . ' 12:00:00 UTC' ) );
			}
		}

		return array_values( array_unique( $weekdays ) );
	}

	/**
	 * The last day a recurring event happens, as `Y-m-d`, or an empty string.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function until( array $record ): string {
		foreach ( self::rrule_part( $record, 'UNTIL' ) as $value ) {
			if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $value ), $matches ) ) {
				return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
			}
		}

		return self::text( $record, 'end_date' );
	}

	/**
	 * The names of the event leads, comma separated.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 */
	public static function leads( array $record ): string {
		$leads = $record['event_leads'] ?? array();
		$names = array();

		if ( ! is_array( $leads ) ) {
			return '';
		}

		foreach ( $leads as $lead ) {
			if ( ! is_array( $lead ) ) {
				continue;
			}

			$name = trim( self::text( $lead, 'first_name' ) . ' ' . self::text( $lead, 'last_name' ) );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return implode( ', ', array_unique( $names ) );
	}

	/**
	 * Joins a KURABU date and time into one datetime.
	 *
	 * @param string $date Date as `Y-m-d`.
	 * @param string $time Time as `H:i:s`, or empty.
	 */
	private static function moment( string $date, string $time ): string {
		if ( '' === $date ) {
			return '';
		}

		if ( '' === $time ) {
			return $date . ' 00:00:00';
		}

		return $date . ' ' . ( 5 === strlen( $time ) ? $time . ':00' : $time );
	}

	/**
	 * The values of one rrule part, e.g. the days behind `BYDAY=`.
	 *
	 * @param array<string|int, mixed> $record Raw KURABU record.
	 * @param string                   $part   Part name, uppercase.
	 *
	 * @return string[]
	 */
	private static function rrule_part( array $record, string $part ): array {
		$rrule = self::text( $record, 'rrule' );

		if ( '' === $rrule ) {
			return array();
		}

		foreach ( explode( ';', $rrule ) as $piece ) {
			$pair = explode( '=', $piece, 2 );

			if ( 2 !== count( $pair ) || strtoupper( trim( $pair[0] ) ) !== $part ) {
				continue;
			}

			return array_map( 'trim', explode( ',', $pair[1] ) );
		}

		return array();
	}

	/**
	 * One scalar field of a record as a trimmed string.
	 *
	 * @param array<string|int, mixed> $record Raw record.
	 * @param string                   $key    Field name.
	 */
	private static function text( array $record, string $key ): string {
		$value = $record[ $key ] ?? null;

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

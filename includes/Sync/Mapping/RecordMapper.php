<?php
/**
 * Turns a raw KURABU record into local field values.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Sync\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the configured KURABU fields out of one record and normalises them.
 *
 * The raw record is always kept as well (the `payload` column), so the
 * template engine can reach KURABU fields this mapping does not cover.
 */
final class RecordMapper {

	/**
	 * Maps one record of a resource.
	 *
	 * @param string                   $resource Resource key.
	 * @param array<string|int, mixed> $record   Raw KURABU record.
	 *
	 * @return array<string, mixed> Local field name => normalised value.
	 */
	public static function map( string $resource, array $record ): array {
		$mapped = array();

		foreach ( Definition::fields( $resource ) as $field => $definition ) {
			$raw = self::pick( $record, FieldMap::sources( $resource, $field ) );

			$mapped[ $field ] = self::normalise( $raw, (string) $definition['type'] );
		}

		/**
		 * Filters the mapped values of one record.
		 *
		 * @param array<string, mixed>     $mapped   Mapped values.
		 * @param array<string|int, mixed> $record   Raw KURABU record.
		 * @param string                   $resource Resource key.
		 */
		return (array) apply_filters( 'kurabu_wp_sync_mapped_record', $mapped, $record, $resource );
	}

	/**
	 * Returns the first non-empty value among the given field names.
	 *
	 * @param array<string|int, mixed> $record Raw record.
	 * @param string[]                 $names  Candidate field names, dots allowed.
	 *
	 * @return mixed Null when none of the names carries a value.
	 */
	public static function pick( array $record, array $names ) {
		foreach ( $names as $name ) {
			$value = self::value_at( $record, (string) $name );

			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}

			return $value;
		}

		return null;
	}

	/**
	 * Reads a possibly nested value, addressed with dots.
	 *
	 * `location.name` reads `$record['location']['name']`. A scalar found where
	 * the path expects an array ends the lookup, so `department.id` on a plain
	 * `"department": "17"` returns null and the next candidate is tried.
	 *
	 * @param array<string|int, mixed> $record Raw record.
	 * @param string                   $path   Field name, dots for nesting.
	 *
	 * @return mixed
	 */
	public static function value_at( array $record, string $path ) {
		$segments = explode( '.', $path );
		$value    = $record;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Normalises a raw value for its field type.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  One of the Definition::TYPE_* constants.
	 *
	 * @return mixed
	 */
	public static function normalise( $value, string $type ) {
		if ( null === $value ) {
			return null;
		}

		switch ( $type ) {
			case Definition::TYPE_ID:
				return self::to_id( $value );
			case Definition::TYPE_INT:
				return is_numeric( $value ) ? (int) $value : 0;
			case Definition::TYPE_FLOAT:
				return is_numeric( $value ) ? (float) $value : null;
			case Definition::TYPE_BOOL:
				return self::to_bool( $value );
			case Definition::TYPE_URL:
				return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			case Definition::TYPE_EMAIL:
				return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
			case Definition::TYPE_DATE:
				return self::to_date( $value );
			case Definition::TYPE_TIME:
				return self::to_time( $value );
			case Definition::TYPE_DATETIME:
				return self::to_datetime( $value );
			case Definition::TYPE_WEEKDAY:
				return self::to_weekday( $value );
			case Definition::TYPE_HTML:
				return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
			case Definition::TYPE_TEXT:
			default:
				return self::to_text( $value );
		}
	}

	/**
	 * Reads an id out of a scalar or an embedded object.
	 *
	 * KURABU may deliver a relation either as `"department": 17` or as
	 * `"department": { "id": 17, … }`; both have to end up as "17".
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_id( $value ): string {
		if ( is_array( $value ) ) {
			foreach ( array( 'id', 'uuid', 'kurabu_id', 'kurabuId' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return trim( (string) $value[ $key ] );
				}
			}

			return '';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Reads a plain string out of a scalar or a named object.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_text( $value ): string {
		if ( is_array( $value ) ) {
			foreach ( array( 'name', 'title', 'value', 'label' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return sanitize_text_field( (string) $value[ $key ] );
				}
			}

			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Interprets the usual spellings of a boolean.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		return is_string( $value )
			&& in_array( strtolower( trim( $value ) ), array( 'true', 'yes', 'ja', 'y', 'j', 'on' ), true );
	}

	/**
	 * Normalises a date to Y-m-d.
	 *
	 * A bare date carries no time of day, so it is reordered rather than run
	 * through a timezone: reading "2026-09-21" as local midnight and printing
	 * it back in UTC would move it to the day before.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_date( $value ): ?string {
		if ( is_scalar( $value ) ) {
			$raw = trim( (string) $value );

			if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $matches ) ) {
				return sprintf( '%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3] );
			}

			if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?$/', $raw, $matches ) ) {
				return sprintf( '%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1] );
			}
		}

		$timestamp = self::to_timestamp( $value );

		return null === $timestamp ? null : (string) wp_date( 'Y-m-d', $timestamp );
	}

	/**
	 * Normalises a time to H:i:s.
	 *
	 * A full datetime is accepted too, so a KURABU record that carries
	 * `start: "2026-09-21T18:00:00+02:00"` still yields a usable time.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_time( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $raw, $matches ) ) {
			$hour = (int) $matches[1];

			if ( $hour > 23 ) {
				return null;
			}

			return sprintf( '%02d:%02d:%02d', $hour, (int) $matches[2], isset( $matches[3] ) ? (int) $matches[3] : 0 );
		}

		$timestamp = self::to_timestamp( $raw );

		return null === $timestamp ? null : gmdate( 'H:i:s', $timestamp );
	}

	/**
	 * Normalises a datetime to Y-m-d H:i:s in site local time.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_datetime( $value ): ?string {
		$timestamp = self::to_timestamp( $value );

		if ( null === $timestamp ) {
			return null;
		}

		return (string) wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Parses a date, datetime or unix timestamp into a UTC timestamp.
	 *
	 * A value without a timezone is read as site local time, which is what an
	 * API that hands out naive timestamps means.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function to_timestamp( $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) && strlen( trim( $value ) ) >= 9 ) ) {
			return (int) $value;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return null;
		}

		// German notation first: strtotime() would read 21.09.2026 as a US date.
		if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})(.*)$/', $raw, $matches ) ) {
			$raw = sprintf( '%04d-%02d-%02d%s', (int) $matches[3], (int) $matches[2], (int) $matches[1], $matches[4] );
		}

		$has_zone = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $raw );
		$parsed   = $has_zone ? strtotime( $raw ) : strtotime( $raw . ' ' . self::local_offset( $raw ) );

		return false === $parsed ? null : $parsed;
	}

	/**
	 * The site's UTC offset for the given local datetime, as +HH:MM.
	 *
	 * @param string $local_datetime Datetime without a timezone.
	 */
	private static function local_offset( string $local_datetime ): string {
		$naive = strtotime( $local_datetime . ' UTC' );

		if ( false === $naive ) {
			return '+00:00';
		}

		$offset = (int) wp_timezone()->getOffset( ( new \DateTimeImmutable( '@' . $naive ) ) );
		$sign   = $offset < 0 ? '-' : '+';
		$offset = abs( $offset );

		return sprintf( '%s%02d:%02d', $sign, intdiv( $offset, 3600 ), intdiv( $offset % 3600, 60 ) );
	}

	/**
	 * Normalises a weekday to the ISO number, Monday = 1.
	 *
	 * Accepts numbers, German and English names and their abbreviations. A
	 * plain 0 is read as Sunday, the way JavaScript style APIs count.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function to_weekday( $value ): int {
		if ( is_numeric( $value ) ) {
			$number = (int) $value;

			if ( 0 === $number ) {
				return 7;
			}

			return ( $number >= 1 && $number <= 7 ) ? $number : 0;
		}

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$names = array(
			1 => array( 'monday', 'montag', 'mon', 'mo' ),
			2 => array( 'tuesday', 'dienstag', 'tue', 'tues', 'di' ),
			3 => array( 'wednesday', 'mittwoch', 'wed', 'mi' ),
			4 => array( 'thursday', 'donnerstag', 'thu', 'thur', 'thurs', 'do' ),
			5 => array( 'friday', 'freitag', 'fri', 'fr' ),
			6 => array( 'saturday', 'samstag', 'sonnabend', 'sat', 'sa' ),
			7 => array( 'sunday', 'sonntag', 'sun', 'so' ),
		);

		$needle = strtolower( trim( (string) $value ) );
		$needle = rtrim( $needle, '.' );

		foreach ( $names as $number => $spellings ) {
			if ( in_array( $needle, $spellings, true ) ) {
				return $number;
			}
		}

		return 0;
	}
}

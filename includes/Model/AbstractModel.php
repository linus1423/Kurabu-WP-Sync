<?php
/**
 * Base model for a cached KURABU record.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Model;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps one row of the local cache.
 *
 * Models are read-only value objects. `get()` resolves a field name against
 * the mapped columns first and falls back to the raw KURABU payload, so the
 * template engine can offer KURABU fields the schema does not model.
 */
abstract class AbstractModel {

	/**
	 * Mapped column values.
	 *
	 * @var array<string, mixed>
	 */
	protected array $data = array();

	/**
	 * Decoded raw KURABU payload.
	 *
	 * @var array<string, mixed>
	 */
	protected array $payload = array();

	/**
	 * @param array<string, mixed> $data Row data.
	 */
	public function __construct( array $data = array() ) {
		$payload = $data['payload'] ?? null;
		unset( $data['payload'] );

		$this->data = $data;

		if ( is_array( $payload ) ) {
			$this->payload = $payload;
		} elseif ( is_string( $payload ) && '' !== $payload ) {
			$decoded       = json_decode( $payload, true );
			$this->payload = is_array( $decoded ) ? $decoded : array();
		}
	}

	/**
	 * Builds a model from a database row.
	 *
	 * @param array<string, mixed> $row Row as returned by wpdb.
	 *
	 * @return static
	 */
	public static function from_row( array $row ): self {
		return new static( $row );
	}

	/**
	 * Returns a field, falling back to the raw KURABU payload.
	 *
	 * @param string $key     Field name.
	 * @param mixed  $default Returned when the field is absent or empty.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( isset( $this->data[ $key ] ) && '' !== $this->data[ $key ] ) {
			return $this->data[ $key ];
		}

		if ( isset( $this->payload[ $key ] ) && '' !== $this->payload[ $key ] ) {
			return $this->payload[ $key ];
		}

		return $default;
	}

	/**
	 * Whether a field is present and non-empty.
	 *
	 * @param string $key Field name.
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * The local row id.
	 */
	public function id(): int {
		return (int) ( $this->data['id'] ?? 0 );
	}

	/**
	 * The stable KURABU id this record is identified by.
	 */
	public function kurabu_id(): string {
		return (string) ( $this->data['kurabu_id'] ?? '' );
	}

	/**
	 * The untouched KURABU payload.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * The mapped columns plus the decoded payload.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data + array( 'payload' => $this->payload );
	}
}

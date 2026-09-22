<?php
/**
 * Base repository for the local KURABU cache.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Database\Repository;

use Kurabu\WPSync\Database\Schema;
use Kurabu\WPSync\Model\AbstractModel;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps all database access to one cache table.
 *
 * Repositories are the only layer that talks to $wpdb: the sync engine writes
 * through `upsert()` / `prune()`, shortcodes and the template engine read
 * through `find_*()` / `query()`. No other layer builds SQL.
 */
abstract class AbstractRepository {

	/**
	 * Cached column lists, keyed by table name.
	 *
	 * @var array<string, string[]>
	 */
	private static array $columns_cache = array();

	/**
	 * The unprefixed table name, one of the Schema constants.
	 */
	abstract protected function table_name(): string;

	/**
	 * Fully qualified model class this repository returns.
	 */
	abstract protected function model_class(): string;

	/**
	 * The prefixed table name.
	 */
	public function table(): string {
		return Schema::table( $this->table_name() );
	}

	/**
	 * Finds one record by its local row id.
	 */
	public function find( int $id ): ?AbstractModel {
		return $this->first( array( 'id' => $id ) );
	}

	/**
	 * Finds one record by its stable KURABU id.
	 */
	public function find_by_kurabu_id( string $kurabu_id ): ?AbstractModel {
		return $this->first( array( 'kurabu_id' => $kurabu_id ) );
	}

	/**
	 * Finds one record by its slug.
	 */
	public function find_by_slug( string $slug ): ?AbstractModel {
		return $this->first( array( 'slug' => $slug ) );
	}

	/**
	 * Resolves a shortcode style reference: a KURABU id or a slug.
	 *
	 * Shortcodes accept both `id="12345"` and `id="turnen"`, so this tries the
	 * stable id first and falls back to the slug.
	 */
	public function find_by_reference( string $reference ): ?AbstractModel {
		$reference = trim( $reference );

		if ( '' === $reference ) {
			return null;
		}

		return $this->find_by_kurabu_id( $reference ) ?? $this->find_by_slug( $reference );
	}

	/**
	 * Returns the first record matching the given conditions.
	 *
	 * @param array<string, mixed> $where Column => value conditions.
	 */
	public function first( array $where ): ?AbstractModel {
		$results = $this->query(
			array(
				'where' => $where,
				'limit' => 1,
			)
		);

		return $results[0] ?? null;
	}

	/**
	 * Queries records.
	 *
	 * @param array{where?: array<string, mixed>, orderby?: string, order?: string, limit?: int, offset?: int} $args Query arguments.
	 *
	 * @return AbstractModel[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'where'   => array(),
				'orderby' => 'menu_order',
				'order'   => 'ASC',
				'limit'   => 0,
				'offset'  => 0,
			)
		);

		list( $where_sql, $values ) = $this->build_where( (array) $args['where'] );

		$sql = 'SELECT * FROM ' . $this->table() . $where_sql
			. $this->build_order( (string) $args['orderby'], (string) $args['order'] );

		if ( (int) $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = (int) $args['limit'];
			$values[] = max( 0, (int) $args['offset'] );
		}

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
			$sql = $wpdb->prepare( $sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$model = $this->model_class();

		return array_map(
			static function ( array $row ) use ( $model ): AbstractModel {
				return $model::from_row( $row );
			},
			$rows
		);
	}

	/**
	 * Counts records matching the given conditions.
	 *
	 * @param array<string, mixed> $where Column => value conditions.
	 */
	public function count( array $where = array() ): int {
		global $wpdb;

		list( $where_sql, $values ) = $this->build_where( $where );

		$sql = 'SELECT COUNT(*) FROM ' . $this->table() . $where_sql;

		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
			$sql = $wpdb->prepare( $sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Inserts or updates a record identified by its KURABU id.
	 *
	 * The checksum of the incoming record is compared with the stored one, so
	 * an unchanged record only refreshes `synced_at` instead of rewriting the
	 * row. Returns the local row id, or 0 when the write failed.
	 *
	 * @param string               $kurabu_id Stable KURABU id.
	 * @param array<string, mixed> $data      Column values; `payload` may be an array.
	 */
	public function upsert( string $kurabu_id, array $data ): int {
		global $wpdb;

		$kurabu_id = trim( $kurabu_id );

		if ( '' === $kurabu_id ) {
			return 0;
		}

		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$data['payload'] = (string) wp_json_encode( $data['payload'] );
		}

		$data['kurabu_id'] = $kurabu_id;
		$data              = $this->filter_columns( $data );
		$now               = current_time( 'mysql' );

		$checksum          = $this->checksum( $data );
		$data['checksum']  = $checksum;
		$data['synced_at'] = $now;
		$data['updated_at'] = $now;

		$existing = $this->find_by_kurabu_id( $kurabu_id );

		if ( null === $existing ) {
			$data['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert( $this->table(), $data );

			return $inserted ? (int) $wpdb->insert_id : 0;
		}

		if ( $checksum === (string) $existing->get( 'checksum', '' ) ) {
			// Unchanged: only record that we saw it in this run.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $this->table(), array( 'synced_at' => $now ), array( 'id' => $existing->id() ) );

			return $existing->id();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table(), $data, array( 'id' => $existing->id() ) );

		return $existing->id();
	}

	/**
	 * Deletes one record by its KURABU id.
	 */
	public function delete_by_kurabu_id( string $kurabu_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( $this->table(), array( 'kurabu_id' => $kurabu_id ) );
	}

	/**
	 * Removes records that KURABU no longer lists.
	 *
	 * Only call this after a complete, successful sync of this resource. An
	 * empty list is treated as "nothing confirmed" and deletes nothing, so a
	 * failed API call can never wipe the cache.
	 *
	 * @param string[] $keep_kurabu_ids Ids confirmed by the current sync run.
	 *
	 * @return int Number of deleted rows.
	 */
	public function prune( array $keep_kurabu_ids ): int {
		global $wpdb;

		$keep = array_values( array_unique( array_filter( array_map( 'strval', $keep_kurabu_ids ) ) ) );

		if ( ! $keep ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $keep ), '%s' ) );
		$sql          = 'DELETE FROM ' . $this->table() . " WHERE kurabu_id NOT IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( $sql, $keep ) );
	}

	/**
	 * Empties the table.
	 */
	public function truncate(): void {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Builds a WHERE clause from column => value conditions.
	 *
	 * @param array<string, mixed> $where Conditions; array values become IN ().
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	protected function build_where( array $where ): array {
		$clauses = array();
		$values  = array();
		$columns = $this->columns();

		foreach ( $where as $column => $value ) {
			if ( ! in_array( $column, $columns, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = array_values( $value );

				if ( ! $value ) {
					// An explicit empty set matches nothing.
					return array( ' WHERE 1=0', array() );
				}

				$placeholders = implode( ', ', array_fill( 0, count( $value ), '%s' ) );
				$clauses[]    = "{$column} IN ( {$placeholders} )";
				$values       = array_merge( $values, array_map( 'strval', $value ) );
				continue;
			}

			$clauses[] = "{$column} = %s";
			$values[]  = (string) $value;
		}

		if ( ! $clauses ) {
			return array( '', array() );
		}

		return array( ' WHERE ' . implode( ' AND ', $clauses ), $values );
	}

	/**
	 * Builds a validated ORDER BY clause.
	 *
	 * @param string $orderby Requested column.
	 * @param string $order   ASC or DESC.
	 */
	protected function build_order( string $orderby, string $order ): string {
		$columns = $this->columns();

		if ( ! in_array( $orderby, $columns, true ) ) {
			$orderby = in_array( 'menu_order', $columns, true ) ? 'menu_order' : 'id';
		}

		$order = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';

		return " ORDER BY {$orderby} {$order}";
	}

	/**
	 * Drops keys that are not real columns of this table.
	 *
	 * @param array<string, mixed> $data Incoming data.
	 *
	 * @return array<string, mixed>
	 */
	protected function filter_columns( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->columns() ) );
	}

	/**
	 * Returns the column names of this table.
	 *
	 * @return string[]
	 */
	protected function columns(): array {
		global $wpdb;

		$table = $this->table();

		if ( ! isset( self::$columns_cache[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

			self::$columns_cache[ $table ] = is_array( $columns ) ? $columns : array();
		}

		return self::$columns_cache[ $table ];
	}

	/**
	 * Builds the change-detection checksum of a record.
	 *
	 * Bookkeeping columns are excluded so that a pure re-sync of unchanged
	 * data does not look like a change.
	 *
	 * @param array<string, mixed> $data Column values.
	 */
	protected function checksum( array $data ): string {
		unset( $data['id'], $data['checksum'], $data['synced_at'], $data['created_at'], $data['updated_at'] );

		ksort( $data );

		return md5( (string) wp_json_encode( $data ) );
	}

	/**
	 * Clears the cached column list. Used after a schema upgrade.
	 */
	public static function flush_columns_cache(): void {
		self::$columns_cache = array();
	}
}

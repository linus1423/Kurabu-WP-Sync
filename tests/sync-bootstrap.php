<?php
/**
 * Extends the shared test bootstrap with what the sync layer needs.
 *
 * `bootstrap.php` stubs the WordPress functions the display layer touches and
 * installs a read-only $wpdb. The sync layer also writes, schedules cron
 * events and creates posts, so this file adds the missing stubs and replaces
 * $wpdb with a writable one whose columns come out of the real Schema — the
 * stub therefore cannot drift away from the schema it stands in for.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace {
	require_once __DIR__ . '/bootstrap.php';

	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	// The site runs on Berlin time, so the tests can tell a naive timestamp
	// from one that was converted.
	$GLOBALS['kurabu_timezone'] = 'Europe/Berlin';
	$GLOBALS['kurabu_posts']    = array();
	$GLOBALS['kurabu_meta']     = array();
	$GLOBALS['kurabu_cron']     = array();
	$GLOBALS['kurabu_notices']  = array();

	/**
	 * The canned HTTP responder the current test installed.
	 *
	 * @var callable|null
	 */
	$GLOBALS['kurabu_responder'] = null;

	/**
	 * Writable in-memory $wpdb.
	 *
	 * Understands the query shapes the plugin actually builds: the SELECTs of
	 * the repositories, the state and map lookups, the log, and the two DELETEs
	 * prune() and purge_older_than() issue.
	 */
	final class KurabuSyncWpdb {

		public string $prefix = 'wp_';

		public int $insert_id = 0;

		/**
		 * Rows per prefixed table name.
		 *
		 * @var array<string, array<int, array<string, mixed>>>
		 */
		public array $data = array();

		/**
		 * Column names per prefixed table name.
		 *
		 * @var array<string, string[]>
		 */
		public array $columns = array();

		/**
		 * Last used auto increment value per table.
		 *
		 * @var array<string, int>
		 */
		private array $auto = array();

		/**
		 * Reads the tables and their columns out of the real schema.
		 */
		public function __construct() {
			// Schema::definitions() asks $wpdb for the charset, so it has to
			// see this instance already.
			$GLOBALS['wpdb'] = $this;

			$definitions = new ReflectionMethod( Kurabu\WPSync\Database\Schema::class, 'definitions' );
			$definitions->setAccessible( true );

			foreach ( (array) $definitions->invoke( null ) as $sql ) {
				if ( ! preg_match( '/CREATE TABLE (\S+) \(/', (string) $sql, $name ) ) {
					continue;
				}

				$table   = $name[1];
				$columns = array();

				foreach ( explode( "\n", (string) $sql ) as $line ) {
					if ( preg_match( '/^([a-z_]+) (bigint|varchar|longtext|text|char|datetime|date|time|int|tinyint|decimal)/', trim( $line ), $column ) ) {
						$columns[] = $column[1];
					}
				}

				$this->columns[ $table ] = $columns;
				$this->data[ $table ]    = array();
				$this->auto[ $table ]    = 0;
			}
		}

		public function get_charset_collate(): string {
			return '';
		}

		/**
		 * Fills in %s and %d placeholders, quoting strings.
		 *
		 * @param string $sql  Query with placeholders.
		 * @param mixed  ...$args Values, or a single array of values.
		 */
		public function prepare( string $sql, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$out    = '';
			$index  = 0;
			$length = strlen( $sql );

			for ( $position = 0; $position < $length; $position++ ) {
				$next = $sql[ $position + 1 ] ?? '';

				if ( '%' === $sql[ $position ] && in_array( $next, array( 's', 'd' ), true ) ) {
					$value = $args[ $index++ ] ?? '';
					$out  .= 'd' === $next
						? (string) (int) $value
						: "'" . str_replace( "'", "''", (string) $value ) . "'";
					$position++;
					continue;
				}

				$out .= $sql[ $position ];
			}

			return $out;
		}

		/**
		 * @param string               $table Prefixed table name.
		 * @param array<string, mixed> $data  Column values.
		 */
		public function insert( string $table, array $data ): int {
			$data['id']            = ++$this->auto[ $table ];
			$this->data[ $table ][] = $data;
			$this->insert_id       = (int) $data['id'];

			return 1;
		}

		/**
		 * @param string               $table Prefixed table name.
		 * @param array<string, mixed> $data  Column values.
		 * @param array<string, mixed> $where Conditions.
		 */
		public function update( string $table, array $data, array $where ): int {
			$changed = 0;

			foreach ( $this->data[ $table ] as $index => $row ) {
				if ( ! $this->matches( $row, $where ) ) {
					continue;
				}

				$this->data[ $table ][ $index ] = array_merge( $row, $data );
				$changed++;
			}

			return $changed;
		}

		/**
		 * @param string               $table Prefixed table name.
		 * @param array<string, mixed> $where Conditions.
		 */
		public function delete( string $table, array $where ): int {
			$deleted = 0;

			foreach ( $this->data[ $table ] as $index => $row ) {
				if ( $this->matches( $row, $where ) ) {
					unset( $this->data[ $table ][ $index ] );
					$deleted++;
				}
			}

			$this->data[ $table ] = array_values( $this->data[ $table ] );

			return $deleted;
		}

		/**
		 * @param string      $sql    Query.
		 * @param string|null $output Ignored; rows are always associative.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function get_results( string $sql, $output = null ): array {
			return preg_match( '/^SELECT .+? FROM (\S+)(.*)$/s', trim( $sql ), $parts )
				? $this->select( $parts[1], $parts[2] )
				: array();
		}

		/**
		 * @param string      $sql    Query.
		 * @param string|null $output Ignored.
		 *
		 * @return array<string, mixed>|null
		 */
		public function get_row( string $sql, $output = null ): ?array {
			return $this->get_results( $sql )[0] ?? null;
		}

		/**
		 * @param string $sql Query.
		 *
		 * @return mixed
		 */
		public function get_var( string $sql ) {
			$sql = trim( $sql );

			if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\S+)(.*)$/s', $sql, $parts ) ) {
				return (string) count( $this->select( $parts[1], $parts[2] ) );
			}

			if ( preg_match( '/^SELECT (\S+) FROM (\S+)(.*)$/s', $sql, $parts ) ) {
				$rows = $this->select( $parts[2], $parts[3] );

				return $rows ? ( $rows[0][ $parts[1] ] ?? null ) : null;
			}

			return null;
		}

		/**
		 * @param string $sql   Query.
		 * @param int    $index Ignored.
		 *
		 * @return string[]
		 */
		public function get_col( string $sql, int $index = 0 ): array {
			return preg_match( '/^SHOW COLUMNS FROM (\S+)$/', trim( $sql ), $parts )
				? ( $this->columns[ $parts[1] ] ?? array() )
				: array();
		}

		/**
		 * Runs the statements the plugin issues through query().
		 *
		 * @param string $sql Query.
		 */
		public function query( string $sql ): int {
			$sql = trim( $sql );

			if ( preg_match( '/^TRUNCATE TABLE (\S+)$/', $sql, $parts ) ) {
				$this->data[ $parts[1] ] = array();

				return 1;
			}

			if ( preg_match( '/^DELETE FROM (\S+) WHERE kurabu_id NOT IN \( (.+) \)$/s', $sql, $parts ) ) {
				$keep = array_map( array( $this, 'unquote' ), explode( ',', $parts[2] ) );

				return $this->delete_where(
					$parts[1],
					static function ( array $row ) use ( $keep ): bool {
						return ! in_array( (string) ( $row['kurabu_id'] ?? '' ), $keep, true );
					}
				);
			}

			if ( preg_match( "/^DELETE FROM (\S+) WHERE created_at < '(.+)'$/s", $sql, $parts ) ) {
				return $this->delete_where(
					$parts[1],
					static function ( array $row ) use ( $parts ): bool {
						return (string) ( $row['created_at'] ?? '' ) < $parts[2];
					}
				);
			}

			return 0;
		}

		/**
		 * Applies the WHERE, ORDER BY and LIMIT tail of a SELECT.
		 *
		 * @param string $table Prefixed table name.
		 * @param string $tail  Everything after the table name.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function select( string $table, string $tail ): array {
			$rows = $this->data[ $table ] ?? array();

			if ( preg_match( '/WHERE (.+?)(?= ORDER BY | LIMIT |$)/s', $tail, $parts ) ) {
				$clause = trim( $parts[1] );

				if ( '1=0' === $clause ) {
					return array();
				}

				foreach ( explode( ' AND ', $clause ) as $condition ) {
					$rows = $this->apply_condition( $rows, trim( $condition ) );
				}
			}

			if ( preg_match( '/ORDER BY (\S+) (ASC|DESC)/', $tail, $parts ) ) {
				usort(
					$rows,
					static function ( array $left, array $right ) use ( $parts ): int {
						$a          = $left[ $parts[1] ] ?? '';
						$b          = $right[ $parts[1] ] ?? '';
						$comparison = is_numeric( $a ) && is_numeric( $b )
							? ( (float) $a <=> (float) $b )
							: strcmp( (string) $a, (string) $b );

						return 'DESC' === $parts[2] ? -$comparison : $comparison;
					}
				);
			}

			if ( preg_match( '/LIMIT (\d+)(?: OFFSET (\d+))?/', $tail, $parts ) ) {
				$rows = array_slice( $rows, (int) ( $parts[2] ?? 0 ), (int) $parts[1] );
			}

			return $rows;
		}

		/**
		 * Filters rows by one `col = 'x'` or `col IN ( 'a', 'b' )` condition.
		 *
		 * @param array<int, array<string, mixed>> $rows      Rows.
		 * @param string                           $condition Condition.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function apply_condition( array $rows, string $condition ): array {
			if ( preg_match( '/^(\S+) IN \( (.+) \)$/', $condition, $parts ) ) {
				$values = array_map( array( $this, 'unquote' ), explode( ',', $parts[2] ) );

				return array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $parts, $values ): bool {
							return in_array( (string) ( $row[ $parts[1] ] ?? '' ), $values, true );
						}
					)
				);
			}

			if ( preg_match( "/^(\S+) = '?(.*?)'?$/", $condition, $parts ) ) {
				return array_values(
					array_filter(
						$rows,
						static function ( array $row ) use ( $parts ): bool {
							return (string) ( $row[ $parts[1] ] ?? '' ) === $parts[2];
						}
					)
				);
			}

			return $rows;
		}

		/**
		 * Deletes every row the callback accepts.
		 *
		 * @param string   $table Prefixed table name.
		 * @param callable $drop  Returns true for rows to delete.
		 */
		private function delete_where( string $table, callable $drop ): int {
			$deleted = 0;

			foreach ( $this->data[ $table ] ?? array() as $index => $row ) {
				if ( $drop( $row ) ) {
					unset( $this->data[ $table ][ $index ] );
					$deleted++;
				}
			}

			$this->data[ $table ] = array_values( $this->data[ $table ] ?? array() );

			return $deleted;
		}

		/**
		 * Strips the quotes around a prepared value.
		 *
		 * @param string $value Quoted value.
		 */
		private function unquote( string $value ): string {
			return trim( trim( $value ), "'" );
		}

		/**
		 * @param array<string, mixed> $row   Row.
		 * @param array<string, mixed> $where Conditions.
		 */
		private function matches( array $row, array $where ): bool {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					return false;
				}
			}

			return true;
		}
	}

	$GLOBALS['wpdb'] = new KurabuSyncWpdb();

	// --- Options ----------------------------------------------------------

	/**
	 * Only inserts when the option does not exist, the way the run lock needs.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Unused.
	 * @param string $autoload   Unused.
	 */
	function add_option( string $option, $value = '', string $deprecated = '', string $autoload = 'yes' ): bool {
		if ( array_key_exists( $option, $GLOBALS['kurabu_options'] ) ) {
			return false;
		}

		$GLOBALS['kurabu_options'][ $option ] = $value;

		return true;
	}

	// --- Time -------------------------------------------------------------

	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( $GLOBALS['kurabu_timezone'] );
	}

	/**
	 * @param string   $format    Date format.
	 * @param int|null $timestamp UTC timestamp.
	 */
	function wp_date( string $format, ?int $timestamp = null ): string {
		$timestamp = null === $timestamp ? time() : $timestamp;

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format );
	}

	/**
	 * @param string $format   Date format.
	 * @param string $datetime MySQL datetime.
	 */
	function mysql2date( string $format, string $datetime ): string {
		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? '' : gmdate( $format, $timestamp );
	}

	// --- HTTP -------------------------------------------------------------

	/**
	 * Hands the request to the responder the running test installed.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_remote_request( string $url, array $args = array() ) {
		++$GLOBALS['kurabu_http_requests'];

		$responder = $GLOBALS['kurabu_responder'];

		if ( ! is_callable( $responder ) ) {
			return new WP_Error( 'kurabu_no_responder', 'Kein Responder installiert.' );
		}

		return $responder( $url, $args );
	}

	/**
	 * @param array<string, mixed>|WP_Error $response Response.
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['status'] ?? 0 ) : 0;
	}

	/**
	 * @param array<string, mixed>|WP_Error $response Response.
	 *
	 * @return array<string, string>
	 */
	function wp_remote_retrieve_headers( $response ): array {
		return is_array( $response ) ? (array) ( $response['headers'] ?? array() ) : array();
	}

	/**
	 * @param array<string, mixed>|WP_Error $response Response.
	 */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}

	function home_url(): string {
		return 'https://example.org';
	}

	/**
	 * @param array<string, string>|string $args  Arguments, or a single key.
	 * @param string                       $value Value, or the URL.
	 * @param string                       $url   URL when a key was given.
	 */
	function add_query_arg( $args, string $value = '', string $url = '' ): string {
		if ( ! is_array( $args ) ) {
			$url  = $url;
			$args = array( $args => $value );
		} else {
			$url = $value;
		}

		$parts = explode( '?', $url, 2 );
		$query = array();

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}

		return $parts[0] . '?' . http_build_query( array_merge( $query, $args ) );
	}

	// --- Sanitising the sync layer needs ----------------------------------

	/**
	 * @param string $email Address.
	 */
	function sanitize_email( string $email ): string {
		$email = filter_var( trim( $email ), FILTER_VALIDATE_EMAIL );

		return is_string( $email ) ? $email : '';
	}

	/**
	 * @param string $title Title.
	 */
	function sanitize_title( string $title ): string {
		$slug = strtolower( remove_accents( $title ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );

		return trim( (string) $slug, '-' );
	}

	function wp_generate_uuid4(): string {
		return sprintf( '%04x%04x-test-run', random_int( 0, 0xffff ), random_int( 0, 0xffff ) );
	}

	// --- Posts ------------------------------------------------------------

	/**
	 * @param string $post_type Post type name.
	 */
	function post_type_exists( string $post_type ): bool {
		return in_array( $post_type, array( 'post', 'page', 'tribe_events' ), true );
	}

	/**
	 * @param array<string, mixed> $args   Query arguments, ignored.
	 * @param string               $output names or objects.
	 *
	 * @return array<string, object>|string[]
	 */
	function get_post_types( array $args = array(), string $output = 'names' ): array {
		$types = array(
			'post' => (object) array(
				'name'   => 'post',
				'labels' => (object) array( 'singular_name' => 'Beitrag' ),
			),
			'page' => (object) array(
				'name'   => 'page',
				'labels' => (object) array( 'singular_name' => 'Seite' ),
			),
		);

		return 'objects' === $output ? $types : array_keys( $types );
	}

	/**
	 * @param array<string, mixed> $postarr Post data.
	 * @param bool                 $error   Unused.
	 */
	function wp_insert_post( array $postarr, bool $error = false ): int {
		$id              = count( $GLOBALS['kurabu_posts'] ) + 100;
		$postarr['ID']   = $id;
		$postarr['post_status'] = $postarr['post_status'] ?? 'publish';

		$GLOBALS['kurabu_posts'][ $id ] = (object) $postarr;

		return $id;
	}

	/**
	 * @param array<string, mixed> $postarr Post data including ID.
	 * @param bool                 $error   Unused.
	 */
	function wp_update_post( array $postarr, bool $error = false ): int {
		$id = (int) ( $postarr['ID'] ?? 0 );

		if ( ! isset( $GLOBALS['kurabu_posts'][ $id ] ) ) {
			return 0;
		}

		$GLOBALS['kurabu_posts'][ $id ] = (object) array_merge(
			(array) $GLOBALS['kurabu_posts'][ $id ],
			$postarr
		);

		return $id;
	}

	/**
	 * @param int $id Post id.
	 */
	function get_post( int $id ): ?object {
		return $GLOBALS['kurabu_posts'][ $id ] ?? null;
	}

	/**
	 * @param int    $id    Post id.
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 */
	function update_post_meta( int $id, string $key, $value ): bool {
		$GLOBALS['kurabu_meta'][ $id ][ $key ] = $value;

		return true;
	}

	/**
	 * @param int    $id  Post id.
	 * @param string $key Meta key.
	 */
	function delete_post_meta( int $id, string $key ): bool {
		unset( $GLOBALS['kurabu_meta'][ $id ][ $key ] );

		return true;
	}

	// --- Cron -------------------------------------------------------------

	/**
	 * @param string $hook Hook name.
	 *
	 * @return int|false
	 */
	function wp_next_scheduled( string $hook ) {
		return $GLOBALS['kurabu_cron'][ $hook ]['timestamp'] ?? false;
	}

	/**
	 * @param string $hook Hook name.
	 *
	 * @return string|false
	 */
	function wp_get_schedule( string $hook ) {
		return $GLOBALS['kurabu_cron'][ $hook ]['schedule'] ?? false;
	}

	/**
	 * @param int    $timestamp When to start.
	 * @param string $schedule  Schedule name.
	 * @param string $hook      Hook name.
	 */
	function wp_schedule_event( int $timestamp, string $schedule, string $hook ): bool {
		$GLOBALS['kurabu_cron'][ $hook ] = array(
			'timestamp' => $timestamp,
			'schedule'  => $schedule,
		);

		return true;
	}

	/**
	 * @param int    $timestamp When to run.
	 * @param string $hook      Hook name.
	 */
	function wp_schedule_single_event( int $timestamp, string $hook ): bool {
		$GLOBALS['kurabu_cron'][ $hook ] = array(
			'timestamp' => $timestamp,
			'schedule'  => false,
		);

		return true;
	}

	/**
	 * @param string $hook Hook name.
	 */
	function wp_clear_scheduled_hook( string $hook ): void {
		unset( $GLOBALS['kurabu_cron'][ $hook ] );
	}

	// --- Admin screens ----------------------------------------------------

	/**
	 * @param string $message Message.
	 */
	function wp_die( string $message ): void {
		throw new RuntimeException( 'wp_die: ' . $message );
	}

	/**
	 * @param string $action Nonce action.
	 */
	function wp_nonce_field( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="test">';
	}

	/**
	 * @param string $action Nonce action.
	 */
	function check_admin_referer( string $action ): bool {
		return true;
	}

	/**
	 * @param string $text Button label.
	 * @param string $type Button style.
	 * @param string $name Button name.
	 * @param bool   $wrap Whether to wrap in a paragraph.
	 */
	function submit_button( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
		printf(
			'%s<button name="%s">%s</button>%s',
			$wrap ? '<p>' : '',
			esc_attr( $name ),
			esc_html( $text ),
			$wrap ? '</p>' : ''
		);
	}

	/**
	 * @param string $slug    Setting group.
	 * @param string $code    Message code.
	 * @param string $message Message.
	 * @param string $type    Notice type.
	 */
	function add_settings_error( string $slug, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['kurabu_notices'][] = array(
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}

	/**
	 * @param string $slug Setting group.
	 */
	function settings_errors( string $slug = '' ): void {
		foreach ( $GLOBALS['kurabu_notices'] as $notice ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * @param mixed $disabled Value to compare.
	 * @param mixed $current  Value to compare against.
	 * @param bool  $echo     Unused.
	 */
	function disabled( $disabled, $current = true, bool $echo = true ): string {
		return $disabled == $current ? ' disabled' : ''; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	}

	/**
	 * @param string $slug Page slug.
	 * @param bool   $echo Unused.
	 */
	function menu_page_url( string $slug, bool $echo = true ): string {
		return 'https://example.org/wp-admin/admin.php?page=' . $slug;
	}

	/**
	 * @param array<string, mixed> $args Dropdown arguments.
	 */
	function wp_dropdown_categories( array $args = array() ): void {
		printf( '<select name="%s"></select>', esc_attr( (string) ( $args['name'] ?? '' ) ) );
	}

	/**
	 * @param array<string, mixed> $args Dropdown arguments.
	 */
	function wp_dropdown_users( array $args = array() ): void {
		printf( '<select name="%s"></select>', esc_attr( (string) ( $args['name'] ?? '' ) ) );
	}

	/**
	 * The error object WordPress hands back from failed writes.
	 */
	class WP_Error {

		/**
		 * Error code.
		 */
		private string $code;

		/**
		 * Error message.
		 */
		private string $message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

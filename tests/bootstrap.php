<?php
/**
 * Minimal WordPress stand-in for the shortcode and template tests.
 *
 * The tests run the real plugin classes without a WordPress installation:
 * only the handful of WordPress functions the display layer touches are
 * stubbed here, plus an in-memory $wpdb that understands exactly the SQL the
 * repositories build. The stubs are deliberately simple; they exist to prove
 * that the layers work together, not to reimplement WordPress.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'OBJECT', 'OBJECT' );
	define( 'KURABU_WP_SYNC_VERSION', '0.1.0' );
	define( 'KURABU_WP_SYNC_FILE', dirname( __DIR__ ) . '/kurabu-wp-sync.php' );
	define( 'KURABU_WP_SYNC_DIR', dirname( __DIR__ ) . '/' );
	define( 'KURABU_WP_SYNC_URL', 'https://example.org/wp-content/plugins/kurabu-wp-sync/' );

	/**
	 * In-memory replacement for $wpdb.
	 */
	final class KurabuFakeWpdb {

		public string $prefix = 'wp_';

		public int $insert_id = 0;

		/**
		 * Rows per table.
		 *
		 * @var array<string, array<int, array<string, mixed>>>
		 */
		public array $data = array();

		/**
		 * Column names per table.
		 *
		 * @var array<string, string[]>
		 */
		public array $columns = array();

		/**
		 * Number of queries, so tests can watch for surprises.
		 */
		public int $queries = 0;

		/**
		 * Defines a table.
		 *
		 * @param string                              $table   Prefixed table name.
		 * @param string[]                            $columns Column names.
		 * @param array<int, array<string, mixed>>    $rows    Initial rows.
		 */
		public function seed( string $table, array $columns, array $rows = array() ): void {
			$this->columns[ $table ] = $columns;
			$this->data[ $table ]    = array();

			foreach ( $rows as $row ) {
				$this->data[ $table ][] = array_merge( array_fill_keys( $columns, '' ), $row );
			}
		}

		public function get_charset_collate(): string {
			return '';
		}

		/**
		 * Substitutes %s and %d placeholders.
		 *
		 * @param string $sql  Query with placeholders.
		 * @param mixed  $args Values, as array or as further arguments.
		 */
		public function prepare( string $sql, $args = null ): string {
			$values = is_array( $args ) ? $args : array_slice( func_get_args(), 1 );

			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( array $match ) use ( &$values ): string {
					$value = array_shift( $values );

					return '%d' === $match[0]
						? (string) (int) $value
						: "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				$sql
			);
		}

		/**
		 * Runs a SELECT and returns the matching rows.
		 *
		 * @param string $sql    Query.
		 * @param mixed  $output Ignored, rows are always associative.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public function get_results( string $sql, $output = null ): array {
			++$this->queries;

			if ( ! preg_match( '/^SELECT \* FROM (\S+)(.*)$/s', trim( $sql ), $matches ) ) {
				return array();
			}

			$table = $matches[1];
			$rest  = $matches[2];
			$rows  = $this->data[ $table ] ?? array();

			if ( preg_match( '/WHERE (.*?)(?: ORDER BY | LIMIT |$)/s', $rest, $where ) ) {
				$rows = $this->filter( $rows, trim( $where[1] ) );
			}

			if ( preg_match( '/ORDER BY (\w+) (ASC|DESC)/', $rest, $order ) ) {
				$column    = $order[1];
				$direction = 'DESC' === $order[2] ? -1 : 1;

				usort(
					$rows,
					static function ( array $a, array $b ) use ( $column, $direction ): int {
						return $direction * ( ( $a[ $column ] ?? '' ) <=> ( $b[ $column ] ?? '' ) );
					}
				);
			}

			if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $rest, $limit ) ) {
				$rows = array_slice( $rows, (int) $limit[2], (int) $limit[1] );
			}

			return array_values( $rows );
		}

		/**
		 * Returns the first row of a SELECT.
		 *
		 * @param string $sql    Query.
		 * @param mixed  $output Ignored.
		 *
		 * @return array<string, mixed>|null
		 */
		public function get_row( string $sql, $output = null ): ?array {
			$rows = $this->get_results( $sql );

			return $rows[0] ?? null;
		}

		/**
		 * Handles the COUNT(*) queries.
		 *
		 * @param string $sql Query.
		 *
		 * @return mixed
		 */
		public function get_var( string $sql ) {
			++$this->queries;

			if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\S+)(.*)$/s', trim( $sql ), $matches ) ) {
				$rows = $this->data[ $matches[1] ] ?? array();

				if ( preg_match( '/WHERE (.*)$/s', $matches[2], $where ) ) {
					$rows = $this->filter( $rows, trim( $where[1] ) );
				}

				return count( $rows );
			}

			return null;
		}

		/**
		 * Handles SHOW COLUMNS.
		 *
		 * @param string $sql   Query.
		 * @param int    $index Ignored.
		 *
		 * @return string[]
		 */
		public function get_col( string $sql, int $index = 0 ): array {
			if ( preg_match( '/^SHOW COLUMNS FROM (\S+)/', trim( $sql ), $matches ) ) {
				return $this->columns[ $matches[1] ] ?? array();
			}

			return array();
		}

		/**
		 * Filters rows by a WHERE clause the repositories built.
		 *
		 * @param array<int, array<string, mixed>> $rows   Rows.
		 * @param string                           $clause WHERE clause.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function filter( array $rows, string $clause ): array {
			if ( '1=0' === $clause ) {
				return array();
			}

			foreach ( explode( ' AND ', $clause ) as $condition ) {
				$condition = trim( $condition );

				if ( preg_match( "/^(\w+) = '(.*)'$/s", $condition, $equals ) ) {
					$column = $equals[1];
					$value  = str_replace( "''", "'", $equals[2] );

					$rows = array_filter(
						$rows,
						static function ( array $row ) use ( $column, $value ): bool {
							return (string) ( $row[ $column ] ?? '' ) === $value;
						}
					);

					continue;
				}

				if ( preg_match( "/^(\w+) IN \( (.*) \)$/s", $condition, $in ) ) {
					$column = $in[1];
					$values = array_map(
						static function ( string $value ): string {
							return str_replace( "''", "'", trim( trim( $value ), "'" ) );
						},
						explode( ', ', $in[2] )
					);

					$rows = array_filter(
						$rows,
						static function ( array $row ) use ( $column, $values ): bool {
							return in_array( (string) ( $row[ $column ] ?? '' ), $values, true );
						}
					);
				}
			}

			return $rows;
		}
	}

	$GLOBALS['wpdb']                 = new KurabuFakeWpdb();
	$GLOBALS['kurabu_options']       = array();
	$GLOBALS['kurabu_transients']    = array();
	$GLOBALS['kurabu_filters']       = array();
	$GLOBALS['kurabu_shortcodes']    = array();
	$GLOBALS['kurabu_actions']       = array();
	$GLOBALS['kurabu_can_manage']    = false;
	$GLOBALS['kurabu_http_requests'] = 0;

	// --- Options and transients -------------------------------------------

	function get_option( string $option, $default = false ) {
		return $GLOBALS['kurabu_options'][ $option ] ?? $default;
	}

	function update_option( string $option, $value ): bool {
		$GLOBALS['kurabu_options'][ $option ] = $value;

		return true;
	}

	function delete_option( string $option ): bool {
		unset( $GLOBALS['kurabu_options'][ $option ] );

		return true;
	}

	function get_transient( string $key ) {
		return $GLOBALS['kurabu_transients'][ $key ] ?? false;
	}

	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['kurabu_transients'][ $key ] = $value;

		return true;
	}

	// --- Hooks ------------------------------------------------------------

	function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['kurabu_filters'][ $hook ][] = $callback;

		return true;
	}

	function remove_all_filters( string $hook ): void {
		unset( $GLOBALS['kurabu_filters'][ $hook ] );
	}

	function apply_filters( string $hook, $value ) {
		$args = func_get_args();

		foreach ( $GLOBALS['kurabu_filters'][ $hook ] ?? array() as $callback ) {
			$value   = $callback( ...array_slice( $args, 1 ) );
			$args[1] = $value;
		}

		return $value;
	}

	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['kurabu_actions'][ $hook ][] = $callback;

		return true;
	}

	function do_action( string $hook, ...$args ): void {
		foreach ( $GLOBALS['kurabu_actions'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}

	function add_shortcode( string $tag, callable $callback ): void {
		$GLOBALS['kurabu_shortcodes'][ $tag ] = $callback;
	}

	/**
	 * Calls a registered shortcode, the way WordPress would.
	 *
	 * @param string                $tag  Shortcode tag.
	 * @param array<string, string> $atts Attributes.
	 */
	function kurabu_do_shortcode( string $tag, array $atts = array() ): string {
		$callback = $GLOBALS['kurabu_shortcodes'][ $tag ] ?? null;

		return null === $callback ? '' : (string) $callback( $atts );
	}

	function shortcode_atts( array $pairs, $atts, string $shortcode = '' ): array {
		$atts = is_array( $atts ) ? $atts : array();

		return array_merge( $pairs, array_intersect_key( $atts, $pairs ) );
	}

	// --- Escaping and sanitising ------------------------------------------

	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( $url ): string {
		return esc_attr( esc_url_raw( (string) $url ) );
	}

	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );

		return preg_match( '#^(https?:|mailto:|tel:|/)#i', $url ) ? $url : '';
	}

	function wp_kses_post( $html ): string {
		return (string) preg_replace( '#<script\b.*?</script>#is', '', (string) $html );
	}

	function sanitize_html_class( $class ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
	}

	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}

	function wp_strip_all_tags( $text ): string {
		return strip_tags( (string) $text );
	}

	function remove_accents( $text ): string {
		return strtr( (string) $text, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue' ) );
	}

	function wp_unslash( $value ) {
		return $value;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_parse_args( $args, array $defaults = array() ): array {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}

	// --- Users, i18n, assets ----------------------------------------------

	function current_user_can( string $capability ): bool {
		return (bool) $GLOBALS['kurabu_can_manage'];
	}

	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['kurabu_can_manage'];
	}

	function determine_locale(): string {
		return 'de_DE';
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function esc_html__( string $text, string $domain = '' ): string {
		return esc_html( $text );
	}

	function esc_attr__( string $text, string $domain = '' ): string {
		return esc_attr( $text );
	}

	function checked( $checked, $current = true, bool $echo = true ): string {
		return $checked == $current ? ' checked' : ''; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	function selected( $selected, $current = true, bool $echo = true ): string {
		return $selected == $current ? ' selected' : ''; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	function wp_style_is( string $handle, string $state = 'enqueued' ): bool {
		return false;
	}

	function wp_register_style( ...$args ): bool {
		return true;
	}

	function wp_enqueue_style( ...$args ): void {
	}

	function current_time( string $type ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}

	/**
	 * Any HTTP call during rendering is a bug: the spec forbids it.
	 */
	function wp_remote_get( ...$args ) {
		++$GLOBALS['kurabu_http_requests'];

		return array();
	}

	function wp_remote_post( ...$args ) {
		++$GLOBALS['kurabu_http_requests'];

		return array();
	}

	require_once dirname( __DIR__ ) . '/includes/Autoloader.php';

	Kurabu\WPSync\Autoloader::register();
}

namespace Kurabu\WPSync {
	/**
	 * The container accessor the plugin file normally defines.
	 */
	function plugin(): Plugin {
		return Plugin::instance();
	}
}

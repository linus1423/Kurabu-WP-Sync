<?php
/**
 * Central storage of the templates.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every template in one option, so the design is defined once.
 *
 * The specification asks for templates that are stored centrally and picked
 * per shortcode, so all shortcodes read through this class. Templates the
 * administrator has not touched come from DefaultTemplates and are not
 * written to the database until they are saved.
 */
final class TemplateStore {

	public const OPTION = 'kurabu_wp_sync_templates';

	/**
	 * Bumped on every write; part of the shortcode render cache key.
	 */
	public const REVISION_OPTION = 'kurabu_wp_sync_templates_revision';

	public const TYPE_TRAINING   = 'training';
	public const TYPE_DEPARTMENT = 'department';

	/**
	 * The record types and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_TRAINING   => __( 'Training', 'kurabu-wp-sync' ),
			self::TYPE_DEPARTMENT => __( 'Abteilung', 'kurabu-wp-sync' ),
		);
	}

	/**
	 * Whether the given string is a known record type.
	 *
	 * @param string $type Record type.
	 */
	public static function is_type( string $type ): bool {
		return isset( self::types()[ $type ] );
	}

	/**
	 * All templates of one record type, keyed by their shortcode key.
	 *
	 * @param string $type Record type.
	 *
	 * @return array<string, Template>
	 */
	public static function all( string $type ): array {
		if ( ! self::is_type( $type ) ) {
			return array();
		}

		$templates = DefaultTemplates::all()[ $type ] ?? array();

		foreach ( (array) ( self::stored()[ $type ] ?? array() ) as $key => $data ) {
			$key = (string) $key;

			if ( ! is_array( $data ) || '' === $key ) {
				continue;
			}

			$templates[ $key ] = Template::from_array( $key, $type, $data );
		}

		/**
		 * Filters the templates of one record type.
		 *
		 * @param array<string, Template> $templates Templates by key.
		 * @param string                  $type      Record type.
		 */
		return (array) apply_filters( 'kurabu_wp_sync_templates', $templates, $type );
	}

	/**
	 * One template, or null when the key is unknown.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	public static function get( string $type, string $key ): ?Template {
		return self::all( $type )[ $key ] ?? null;
	}

	/**
	 * The template a shortcode should use.
	 *
	 * Falls back to "standard" and, should even that be missing, to the first
	 * template of the type, so a typo in the shortcode never empties a page.
	 *
	 * @param string $type Record type.
	 * @param string $key  Requested template key, may be empty.
	 */
	public static function resolve( string $type, string $key = '' ): ?Template {
		$templates = self::all( $type );

		if ( '' !== $key && isset( $templates[ $key ] ) ) {
			return $templates[ $key ];
		}

		if ( isset( $templates[ DefaultTemplates::FALLBACK_KEY ] ) ) {
			return $templates[ DefaultTemplates::FALLBACK_KEY ];
		}

		return $templates ? reset( $templates ) : null;
	}

	/**
	 * Whether a template exists under that key.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	public static function exists( string $type, string $key ): bool {
		return null !== self::get( $type, $key );
	}

	/**
	 * Whether a template is still exactly as shipped.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	public static function is_default( string $type, string $key ): bool {
		return isset( DefaultTemplates::all()[ $type ][ $key ] )
			&& ! isset( self::stored()[ $type ][ $key ] );
	}

	/**
	 * Stores a template.
	 *
	 * @param Template $template The template to write.
	 */
	public static function save( Template $template ): void {
		if ( ! self::is_type( $template->type() ) || '' === $template->key() ) {
			return;
		}

		$stored = self::stored();

		$stored[ $template->type() ][ $template->key() ] = $template->to_array();

		self::write( $stored );
	}

	/**
	 * Removes a template.
	 *
	 * A shipped template reappears in its original state, because only the
	 * stored override is deleted.
	 *
	 * @param string $type Record type.
	 * @param string $key  Template key.
	 */
	public static function delete( string $type, string $key ): void {
		$stored = self::stored();

		if ( isset( $stored[ $type ][ $key ] ) ) {
			unset( $stored[ $type ][ $key ] );

			self::write( $stored );
		}
	}

	/**
	 * Turns a name into a template key that is free within its type.
	 *
	 * @param string $type Record type.
	 * @param string $name Requested name.
	 */
	public static function unique_key( string $type, string $name ): string {
		$base = sanitize_key( remove_accents( $name ) );
		$base = '' !== $base ? $base : 'vorlage';
		$key  = $base;
		$i    = 2;

		while ( self::exists( $type, $key ) ) {
			$key = $base . '-' . $i;
			++$i;
		}

		return $key;
	}

	/**
	 * The current revision, used to invalidate rendered output.
	 */
	public static function revision(): int {
		return (int) get_option( self::REVISION_OPTION, 0 );
	}

	/**
	 * Drops every stored template, restoring the shipped ones.
	 */
	public static function reset(): void {
		self::write( array() );
	}

	/**
	 * The raw stored option.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Writes the option and bumps the revision.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $stored Templates to store.
	 */
	private static function write( array $stored ): void {
		update_option( self::OPTION, $stored );
		update_option( self::REVISION_OPTION, self::revision() + 1 );

		/**
		 * Fires after a template was created, changed or deleted.
		 *
		 * Rendered shortcode output is keyed by the revision, so caches do not
		 * need to be cleared explicitly.
		 */
		do_action( 'kurabu_wp_sync_templates_saved' );
	}

	/**
	 * Removes the stored templates. Used on uninstall.
	 */
	public static function delete_option(): void {
		delete_option( self::OPTION );
		delete_option( self::REVISION_OPTION );
	}
}

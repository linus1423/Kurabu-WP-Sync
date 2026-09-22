<?php
/**
 * Mapping screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Mapping\Definition;
use Kurabu\WPSync\Sync\Mapping\FieldMap;
use Kurabu\WPSync\Sync\Resource;

defined( 'ABSPATH' ) || exit;

/**
 * Says where the data comes from and which KURABU field feeds which local one.
 *
 * As long as the KURABU documentation is not available, this screen is what
 * makes the sync adaptable: endpoint paths, the query parameters and the field
 * names are all settings, so a wrong guess is corrected here instead of in the
 * code.
 */
final class MappingPage extends AbstractPage {

	private const NONCE = 'kurabu_wp_sync_mapping';

	public function slug(): string {
		return 'kurabu-wp-sync-mapping';
	}

	public function menu_title(): string {
		return __( 'Mapping', 'kurabu-wp-sync' );
	}

	/**
	 * Saves whichever of the three forms was submitted.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['kurabu_mapping_query'] ) ) {
			check_admin_referer( self::NONCE );
			$this->save_query();

			return;
		}

		if ( isset( $_POST['kurabu_mapping_news'] ) ) {
			check_admin_referer( self::NONCE );
			$this->save_news();

			return;
		}

		if ( isset( $_POST['kurabu_mapping_fields'] ) || isset( $_POST['kurabu_mapping_reset'] ) ) {
			check_admin_referer( self::NONCE );
			$this->save_fields( isset( $_POST['kurabu_mapping_reset'] ) );
		}
	}

	protected function render_body(): void {
		settings_errors( 'kurabu_wp_sync' );

		$this->render_query_form();
		$this->render_news_form();
		$this->render_field_form();
	}

	/**
	 * Renders the query parameters of the API requests.
	 */
	private function render_query_form(): void {
		$settings = Settings::all();

		echo '<h2>' . esc_html__( 'Abfrage', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Wie die Endpunkte abgefragt werden. Die Namen hängen von der KURABU-API ab; ein leeres Feld lässt den Parameter weg.', 'kurabu-wp-sync' )
			. '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="incremental" %s> %s</label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Inkrementell', 'kurabu-wp-sync' ),
			checked( (bool) $settings['incremental'], true, false ),
			esc_html__( 'Nur seit dem letzten erfolgreichen Lauf geänderte Daten abfragen', 'kurabu-wp-sync' ),
			esc_html__( 'Unabhängig davon läuft mindestens einmal täglich ein vollständiger Lauf, weil nur er erkennt, was in KURABU gelöscht wurde.', 'kurabu-wp-sync' )
		);

		$this->render_text_row(
			'since_param',
			__( '"Geändert seit"-Parameter', 'kurabu-wp-sync' ),
			(string) $settings['since_param'],
			__( 'Der Wert wird als ISO-8601-Zeitstempel gesendet. Leer lassen, wenn die API keinen solchen Filter kennt.', 'kurabu-wp-sync' )
		);

		$this->render_text_row(
			'page_param',
			__( 'Seiten-Parameter', 'kurabu-wp-sync' ),
			(string) $settings['page_param'],
			__( 'Parameter für die Seitennummer beim Blättern.', 'kurabu-wp-sync' )
		);

		$this->render_text_row(
			'per_page_param',
			__( 'Parameter für die Seitengröße', 'kurabu-wp-sync' ),
			(string) $settings['per_page_param'],
			''
		);

		printf(
			'<tr><th scope="row"><label for="per_page">%s</label></th><td>'
			. '<input type="number" min="1" max="500" name="per_page" id="per_page" value="%s" class="small-text"></td></tr>',
			esc_html__( 'Datensätze pro Seite', 'kurabu-wp-sync' ),
			esc_attr( (string) $settings['per_page'] )
		);

		printf(
			'<tr><th scope="row"><label for="log_retention_days">%s</label></th><td>'
			. '<input type="number" min="0" max="365" name="log_retention_days" id="log_retention_days" value="%s" class="small-text">'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Protokoll aufbewahren (Tage)', 'kurabu-wp-sync' ),
			esc_attr( (string) $settings['log_retention_days'] ),
			esc_html__( '0 bewahrt das Protokoll unbegrenzt auf.', 'kurabu-wp-sync' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Abfrage speichern', 'kurabu-wp-sync' ), 'primary', 'kurabu_mapping_query' );
		echo '</form>';
	}

	/**
	 * Renders where news are written to.
	 */
	private function render_news_form(): void {
		$settings = Settings::all();

		echo '<hr><h2>' . esc_html__( 'Beiträge', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'KURABU-News werden als WordPress-Beiträge übernommen und anhand der KURABU-ID wiedererkannt.', 'kurabu-wp-sync' )
			. '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="news_post_type">' . esc_html__( 'Beitragstyp', 'kurabu-wp-sync' ) . '</label></th><td>';
		echo '<select name="news_post_type" id="news_post_type">';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $post_type->name ),
				selected( $settings['news_post_type'], $post_type->name, false ),
				esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' )
			);
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="news_post_status">' . esc_html__( 'Status', 'kurabu-wp-sync' ) . '</label></th><td>';
		echo '<select name="news_post_status" id="news_post_status">';

		$statuses = array(
			'publish' => __( 'veröffentlicht', 'kurabu-wp-sync' ),
			'draft'   => __( 'Entwurf', 'kurabu-wp-sync' ),
			'pending' => __( 'ausstehend', 'kurabu-wp-sync' ),
			'private' => __( 'privat', 'kurabu-wp-sync' ),
		);

		foreach ( $statuses as $status => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $status ),
				selected( $settings['news_post_status'], $status, false ),
				esc_html( $label )
			);
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="news_category">' . esc_html__( 'Kategorie', 'kurabu-wp-sync' ) . '</label></th><td>';
		wp_dropdown_categories(
			array(
				'name'             => 'news_category',
				'id'               => 'news_category',
				'selected'         => (int) $settings['news_category'],
				'show_option_none' => __( '— keine —', 'kurabu-wp-sync' ),
				'option_none_value' => 0,
				'hide_empty'       => false,
			)
		);
		echo '<p class="description">' . esc_html__( 'Gilt nur für den Beitragstyp "Beitrag".', 'kurabu-wp-sync' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="news_author">' . esc_html__( 'Autor', 'kurabu-wp-sync' ) . '</label></th><td>';
		wp_dropdown_users(
			array(
				'name'             => 'news_author',
				'id'               => 'news_author',
				'selected'         => (int) $settings['news_author'],
				'show_option_none' => __( '— Standard —', 'kurabu-wp-sync' ),
				'option_none_value' => 0,
			)
		);
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Beiträge speichern', 'kurabu-wp-sync' ), 'primary', 'kurabu_mapping_news' );
		echo '</form>';
	}

	/**
	 * Renders the endpoint and the field mapping of the selected resource.
	 */
	private function render_field_form(): void {
		$resource = $this->current_resource();

		echo '<hr><h2>' . esc_html__( 'Endpunkte und Felder', 'kurabu-wp-sync' ) . '</h2>';

		$this->render_resource_nav( $resource );

		$fields = Definition::fields( $resource );

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="resource" value="%s">', esc_attr( $resource ) );

		printf(
			'<table class="form-table" role="presentation"><tbody>'
			. '<tr><th scope="row"><label for="endpoint">%s</label></th><td>'
			. '<input type="text" name="endpoint" id="endpoint" class="regular-text code" value="%s" placeholder="%s">'
			. '<p class="description">%s</p></td></tr></tbody></table>',
			esc_html__( 'Endpunkt', 'kurabu-wp-sync' ),
			esc_attr( FieldMap::endpoint( $resource ) ),
			esc_attr( Definition::endpoints()[ $resource ] ?? '' ),
			esc_html__( 'Pfad relativ zur API-Basis-URL, ohne führenden Schrägstrich.', 'kurabu-wp-sync' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:16em">' . esc_html__( 'Feld im Plugin', 'kurabu-wp-sync' ) . '</th>';
		echo '<th style="width:18em">' . esc_html__( 'KURABU-Feldname', 'kurabu-wp-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Wird sonst gesucht als', 'kurabu-wp-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $fields as $field => $definition ) {
			$candidates = array();

			foreach ( (array) $definition['candidates'] as $candidate ) {
				$candidates[] = '<code>' . esc_html( (string) $candidate ) . '</code>';
			}

			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td>'
				. '<td><input type="text" name="fields[%s]" class="regular-text code" value="%s"></td>'
				. '<td class="description">%s</td></tr>',
				esc_html( (string) $definition['label'] ),
				esc_html( (string) $field ),
				esc_attr( (string) $field ),
				esc_attr( FieldMap::pinned( $resource, (string) $field ) ),
				wp_kses_post( implode( ', ', $candidates ) )
			);
		}

		echo '</tbody></table>';

		echo '<p class="description">'
			. esc_html__( 'Leer bedeutet: die Kandidaten rechts werden der Reihe nach probiert. Ein Punkt greift in verschachtelte Werte, zum Beispiel location.name.', 'kurabu-wp-sync' )
			. '</p>';

		echo '<p>';
		submit_button( __( 'Mapping speichern', 'kurabu-wp-sync' ), 'primary', 'kurabu_mapping_fields', false );
		echo ' ';
		submit_button( __( 'Auf Standard zurücksetzen', 'kurabu-wp-sync' ), 'secondary', 'kurabu_mapping_reset', false );
		echo '</p></form>';
	}

	/**
	 * Renders the resource switcher.
	 *
	 * @param string $current Selected resource.
	 */
	private function render_resource_nav( string $current ): void {
		$base  = (string) menu_page_url( $this->slug(), false );
		$links = array();

		foreach ( Resource::labels() as $resource => $label ) {
			$links[] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( 'resource', $resource, $base ) ),
				$current === $resource ? ' class="current"' : '',
				esc_html( $label )
			);
		}

		echo '<ul class="subsubsub"><li>' . wp_kses_post( implode( ' | </li><li>', $links ) ) . '</li></ul>';
		echo '<div style="clear:both"></div>';
	}

	/**
	 * The resource the screen is showing.
	 */
	private function current_resource(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection.
		$resource = isset( $_GET['resource'] ) ? sanitize_key( wp_unslash( $_GET['resource'] ) ) : '';

		return Resource::is_valid( $resource ) ? $resource : Resource::DEPARTMENTS;
	}

	/**
	 * Renders one text input row.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $description Description below the field.
	 */
	private function render_text_row( string $name, string $label, string $value, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
			. '<input type="text" name="%1$s" id="%1$s" class="regular-text code" value="%3$s">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * Saves the query parameters.
	 */
	private function save_query(): void {
		Settings::update(
			array(
				'incremental'        => isset( $_POST['incremental'] ),
				'since_param'        => sanitize_text_field( wp_unslash( $_POST['since_param'] ?? '' ) ),
				'page_param'         => sanitize_text_field( wp_unslash( $_POST['page_param'] ?? '' ) ),
				'per_page_param'     => sanitize_text_field( wp_unslash( $_POST['per_page_param'] ?? '' ) ),
				'per_page'           => max( 1, min( 500, (int) ( $_POST['per_page'] ?? 100 ) ) ),
				'log_retention_days' => max( 0, min( 365, (int) ( $_POST['log_retention_days'] ?? 30 ) ) ),
			)
		);

		add_settings_error( 'kurabu_wp_sync', 'saved', __( 'Abfrage gespeichert.', 'kurabu-wp-sync' ), 'success' );
	}

	/**
	 * Saves where news are written to.
	 */
	private function save_news(): void {
		$post_type = sanitize_key( wp_unslash( $_POST['news_post_type'] ?? 'post' ) );

		Settings::update(
			array(
				'news_post_type'   => post_type_exists( $post_type ) ? $post_type : 'post',
				'news_post_status' => sanitize_key( wp_unslash( $_POST['news_post_status'] ?? 'publish' ) ),
				'news_category'    => max( 0, (int) ( $_POST['news_category'] ?? 0 ) ),
				'news_author'      => max( 0, (int) ( $_POST['news_author'] ?? 0 ) ),
			)
		);

		add_settings_error( 'kurabu_wp_sync', 'saved', __( 'Beitragsziel gespeichert.', 'kurabu-wp-sync' ), 'success' );
	}

	/**
	 * Saves or resets the endpoint and the field mapping of one resource.
	 *
	 * @param bool $reset Whether the reset button was pressed.
	 */
	private function save_fields( bool $reset ): void {
		$resource = sanitize_key( wp_unslash( $_POST['resource'] ?? '' ) );

		if ( ! Resource::is_valid( $resource ) ) {
			return;
		}

		if ( $reset ) {
			FieldMap::reset( $resource );

			add_settings_error(
				'kurabu_wp_sync',
				'reset',
				__( 'Mapping auf den Standard zurückgesetzt.', 'kurabu-wp-sync' ),
				'success'
			);

			return;
		}

		$raw    = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$fields = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $field => $source ) {
				$fields[ sanitize_key( (string) $field ) ] = sanitize_text_field( (string) $source );
			}
		}

		FieldMap::save(
			$resource,
			sanitize_text_field( wp_unslash( $_POST['endpoint'] ?? '' ) ),
			$fields
		);

		add_settings_error( 'kurabu_wp_sync', 'saved', __( 'Mapping gespeichert.', 'kurabu-wp-sync' ), 'success' );
	}
}

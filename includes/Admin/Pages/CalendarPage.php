<?php
/**
 * Kalenderintegration screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Database\ObjectMap;
use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;
use Kurabu\WPSync\Sync\Calendar\CalendarRegistry;
use Kurabu\WPSync\Sync\Calendar\PostTypeAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the calendar KURABU events are written into.
 *
 * The specification asks for the events to go into the club's existing
 * calendar, so the plugin does not bring one along: it detects the calendars
 * it can write to and lets the administrator choose.
 */
final class CalendarPage extends AbstractPage {

	private const NONCE = 'kurabu_wp_sync_calendar';

	public function slug(): string {
		return 'kurabu-wp-sync-calendar';
	}

	public function menu_title(): string {
		return __( 'Kalenderintegration', 'kurabu-wp-sync' );
	}

	/**
	 * Saves the calendar target.
	 */
	public function handle_request(): void {
		if ( ! isset( $_POST['kurabu_calendar_submit'] ) || ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$target = sanitize_key( wp_unslash( $_POST['calendar_target'] ?? '' ) );

		if ( '' !== $target && null === CalendarRegistry::get( $target ) ) {
			$target = '';
		}

		$post_type = sanitize_key( wp_unslash( $_POST['event_post_type'] ?? '' ) );

		Settings::update(
			array(
				'calendar_target'     => $target,
				'event_post_type'     => post_type_exists( $post_type ) ? $post_type : '',
				'event_meta_start'    => sanitize_text_field( wp_unslash( $_POST['event_meta_start'] ?? '' ) ),
				'event_meta_end'      => sanitize_text_field( wp_unslash( $_POST['event_meta_end'] ?? '' ) ),
				'event_meta_location' => sanitize_text_field( wp_unslash( $_POST['event_meta_location'] ?? '' ) ),
			)
		);

		add_settings_error( 'kurabu_wp_sync', 'saved', __( 'Kalenderziel gespeichert.', 'kurabu-wp-sync' ), 'success' );
	}

	protected function render_body(): void {
		settings_errors( 'kurabu_wp_sync' );

		$settings = Settings::all();
		$selected = (string) $settings['calendar_target'];

		echo '<p class="description">'
			. esc_html__( 'KURABU-Events werden mit Titel, Beschreibung, Datum, Uhrzeit und Ort in den hier gewählten Kalender übertragen und über die KURABU-ID wiedererkannt.', 'kurabu-wp-sync' )
			. '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );

		echo '<table class="widefat striped" style="margin:1em 0"><tbody>';

		printf(
			'<tr><td style="width:2em"><input type="radio" name="calendar_target" id="calendar_target_none" value="" %s></td>'
			. '<td><label for="calendar_target_none"><strong>%s</strong></label><p class="description">%s</p></td></tr>',
			checked( '' === $selected, true, false ),
			esc_html__( 'Keine Kalenderintegration', 'kurabu-wp-sync' ),
			esc_html__( 'Events werden bei einem Lauf übersprungen; alle anderen Datenarten laufen normal weiter.', 'kurabu-wp-sync' )
		);

		foreach ( CalendarRegistry::adapters() as $adapter ) {
			$available = $adapter->is_available();
			$id        = 'calendar_target_' . $adapter->key();

			printf(
				'<tr><td><input type="radio" name="calendar_target" id="%s" value="%s" %s %s></td>'
				. '<td><label for="%s"><strong>%s</strong></label> %s<p class="description">%s</p></td></tr>',
				esc_attr( $id ),
				esc_attr( $adapter->key() ),
				checked( $selected === $adapter->key(), true, false ),
				disabled( $available, false, false ),
				esc_attr( $id ),
				esc_html( $adapter->label() ),
				$available
					? ''
					: '<em>' . esc_html__( '— auf dieser Website nicht verfügbar', 'kurabu-wp-sync' ) . '</em>',
				esc_html( $adapter->description() )
			);
		}

		echo '</tbody></table>';

		$this->render_post_type_fields( $settings );

		submit_button( __( 'Kalenderziel speichern', 'kurabu-wp-sync' ), 'primary', 'kurabu_calendar_submit' );
		echo '</form>';

		$this->render_state();
	}

	/**
	 * Renders the settings the generic post type adapter needs.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	private function render_post_type_fields( array $settings ): void {
		echo '<h2>' . esc_html__( 'Einstellungen für "Beitragstyp mit eigenen Feldern"', 'kurabu-wp-sync' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Nur relevant, wenn oben dieser Kalender gewählt ist. Die Feldnamen müssen zu denen des eingesetzten Kalender-Plugins passen.', 'kurabu-wp-sync' )
			. '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="event_post_type">' . esc_html__( 'Beitragstyp', 'kurabu-wp-sync' ) . '</label></th><td>';
		echo '<select name="event_post_type" id="event_post_type">';
		printf(
			'<option value="" %s>%s</option>',
			selected( '', (string) $settings['event_post_type'], false ),
			esc_html__( '— bitte wählen —', 'kurabu-wp-sync' )
		);

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $post_type->name ),
				selected( $settings['event_post_type'], $post_type->name, false ),
				esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' )
			);
		}

		echo '</select></td></tr>';

		$meta_fields = array(
			'event_meta_start'    => __( 'Feld für den Beginn', 'kurabu-wp-sync' ),
			'event_meta_end'      => __( 'Feld für das Ende', 'kurabu-wp-sync' ),
			'event_meta_location' => __( 'Feld für den Ort', 'kurabu-wp-sync' ),
		);

		foreach ( $meta_fields as $name => $label ) {
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
				. '<input type="text" name="%1$s" id="%1$s" class="regular-text code" value="%3$s"></td></tr>',
				esc_attr( $name ),
				esc_html( $label ),
				esc_attr( (string) $settings[ $name ] )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">'
			. esc_html__( 'Beginn und Ende werden als Y-m-d H:i:s in der Zeitzone der Website gespeichert.', 'kurabu-wp-sync' )
			. '</p>';
	}

	/**
	 * Shows how many events are currently linked.
	 */
	private function render_state(): void {
		$active = CalendarRegistry::active();
		$count  = ObjectMap::count( ObjectMap::TYPE_EVENT );

		echo '<hr><table class="widefat striped" style="max-width:44em"><tbody>';

		printf(
			'<tr><td style="width:18em"><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Aktives Kalenderziel', 'kurabu-wp-sync' ),
			esc_html(
				null !== $active
					? $active->label()
					: __( 'keines — Events werden übersprungen', 'kurabu-wp-sync' )
			)
		);

		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Verknüpfte Events', 'kurabu-wp-sync' ),
			esc_html( (string) $count )
		);

		echo '</tbody></table>';

		if ( null === $active && '' !== (string) Settings::get( 'calendar_target', '' ) ) {
			$this->render_placeholder(
				__( 'Das gespeicherte Kalenderziel ist derzeit nicht verfügbar. Bitte prüfen, ob das Kalender-Plugin aktiv ist.', 'kurabu-wp-sync' )
			);
		}

		if ( null !== $active && PostTypeAdapter::KEY === $active->key() && '' === (string) Settings::get( 'event_post_type', '' ) ) {
			$this->render_placeholder(
				__( 'Ohne gewählten Beitragstyp landen die Events im Standard-Beitragstyp.', 'kurabu-wp-sync' )
			);
		}
	}
}

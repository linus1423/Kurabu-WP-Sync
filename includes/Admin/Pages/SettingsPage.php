<?php
/**
 * API-Konfiguration screen.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Plugin;
use Kurabu\WPSync\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the KURABU API credentials and the synchronisation interval.
 *
 * The sync engine only reads these values; it never writes them.
 */
final class SettingsPage extends AbstractPage {

	private const NONCE = 'kurabu_wp_sync_settings';

	public function slug(): string {
		return 'kurabu-wp-sync';
	}

	public function menu_title(): string {
		return __( 'API-Konfiguration', 'kurabu-wp-sync' );
	}

	/**
	 * Saves the submitted settings.
	 */
	public function handle_request(): void {
		if ( ! isset( $_POST['kurabu_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$values = array(
			'api_base_url'  => esc_url_raw( wp_unslash( $_POST['api_base_url'] ?? '' ) ),
			'sync_interval' => sanitize_text_field( wp_unslash( $_POST['sync_interval'] ?? '1h' ) ),
			'sync_enabled'  => isset( $_POST['sync_enabled'] ),
		);

		if ( ! isset( Settings::INTERVALS[ $values['sync_interval'] ] ) ) {
			$values['sync_interval'] = '1h';
		}

		// An empty token field keeps the stored token instead of clearing it.
		$token = sanitize_text_field( wp_unslash( $_POST['api_token'] ?? '' ) );

		if ( '' !== $token && ! defined( Settings::TOKEN_CONSTANT ) ) {
			$values['api_token'] = $token;
		}

		Settings::update( $values );

		/**
		 * Fires after the settings were saved.
		 *
		 * The sync engine reschedules its cron event from here.
		 */
		do_action( 'kurabu_wp_sync_settings_saved', Settings::all() );

		add_settings_error(
			'kurabu_wp_sync',
			'saved',
			__( 'Einstellungen gespeichert.', 'kurabu-wp-sync' ),
			'success'
		);
	}

	protected function render_body(): void {
		$settings       = Settings::all();
		$token_constant = defined( Settings::TOKEN_CONSTANT );

		settings_errors( 'kurabu_wp_sync' );

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="api_base_url">%s</label></th><td>'
			. '<input name="api_base_url" id="api_base_url" type="url" class="regular-text" value="%s" placeholder="https://api.kurabu.de">'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'API-Basis-URL', 'kurabu-wp-sync' ),
			esc_attr( (string) $settings['api_base_url'] ),
			esc_html__( 'Basis-URL der KURABU-API.', 'kurabu-wp-sync' )
		);

		if ( $token_constant ) {
			printf(
				'<tr><th scope="row">%s</th><td><p class="description">%s</p></td></tr>',
				esc_html__( 'API-Token', 'kurabu-wp-sync' ),
				sprintf(
					/* translators: %s: PHP constant name. */
					esc_html__( 'Der Token ist über die Konstante %s in der wp-config.php gesetzt und kann hier nicht geändert werden.', 'kurabu-wp-sync' ),
					'<code>' . esc_html( Settings::TOKEN_CONSTANT ) . '</code>'
				)
			);
		} else {
			printf(
				'<tr><th scope="row"><label for="api_token">%s</label></th><td>'
				. '<input name="api_token" id="api_token" type="password" class="regular-text" value="" autocomplete="new-password">'
				. '<p class="description">%s</p></td></tr>',
				esc_html__( 'API-Token', 'kurabu-wp-sync' ),
				esc_html(
					'' !== (string) $settings['api_token']
						? __( 'Ein Token ist hinterlegt. Leer lassen, um es beizubehalten.', 'kurabu-wp-sync' )
						: __( 'Noch kein Token hinterlegt.', 'kurabu-wp-sync' )
				)
			);
		}

		echo '<tr><th scope="row"><label for="sync_interval">' . esc_html__( 'Synchronisationsintervall', 'kurabu-wp-sync' ) . '</label></th><td>';
		echo '<select name="sync_interval" id="sync_interval">';

		foreach ( Settings::interval_labels() as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $settings['sync_interval'], $key, false ),
				esc_html( $label )
			);
		}

		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="sync_enabled" %s> %s</label></td></tr>',
			esc_html__( 'Automatische Synchronisation', 'kurabu-wp-sync' ),
			checked( (bool) $settings['sync_enabled'], true, false ),
			esc_html__( 'Regelmäßig im Hintergrund synchronisieren', 'kurabu-wp-sync' )
		);

		echo '</tbody></table>';

		submit_button( __( 'Einstellungen speichern', 'kurabu-wp-sync' ), 'primary', 'kurabu_settings_submit' );
		echo '</form>';

		if ( ! Settings::is_configured() ) {
			$this->render_placeholder(
				__( 'Solange URL und Token fehlen, kann nicht mit KURABU synchronisiert werden.', 'kurabu-wp-sync' )
			);
		}
	}
}

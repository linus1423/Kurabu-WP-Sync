<?php
/**
 * Base class for the KURABU admin screens.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin\Pages;

use Kurabu\WPSync\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One screen below the KURABU menu.
 */
abstract class AbstractPage {

	/**
	 * The page slug, without the menu prefix.
	 */
	abstract public function slug(): string;

	/**
	 * The menu label.
	 */
	abstract public function menu_title(): string;

	/**
	 * The heading shown on the screen.
	 */
	public function page_title(): string {
		return $this->menu_title();
	}

	/**
	 * Renders the screen body, below the heading.
	 */
	abstract protected function render_body(): void;

	/**
	 * Handles a form submission before the screen renders.
	 *
	 * Runs on `load-{page}`, so it can still redirect.
	 */
	public function handle_request(): void {
	}

	/**
	 * Renders the whole screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diese Seite.', 'kurabu-wp-sync' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $this->page_title() ) . '</h1>';

		$this->render_body();

		echo '</div>';
	}

	/**
	 * Renders a notice explaining that a later build step fills this screen.
	 *
	 * @param string $message What is still missing.
	 */
	protected function render_placeholder( string $message ): void {
		echo '<div class="notice notice-info inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Formats a MySQL datetime for display, or returns a dash.
	 *
	 * The stored values come from current_time( 'mysql' ) and are therefore in
	 * site local time, which is exactly what mysql2date() expects.
	 *
	 * @param mixed $datetime MySQL datetime.
	 */
	protected function format_datetime( $datetime ): string {
		if ( ! is_string( $datetime ) || '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '—';
		}

		$formatted = mysql2date( $this->datetime_format(), $datetime );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : '—';
	}

	/**
	 * Formats a unix timestamp for display, or returns a dash.
	 *
	 * @param int $timestamp UTC unix timestamp.
	 */
	protected function format_timestamp( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}

		return (string) wp_date( $this->datetime_format(), $timestamp );
	}

	/**
	 * The site's combined date and time format.
	 */
	private function datetime_format(): string {
		return (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );
	}
}

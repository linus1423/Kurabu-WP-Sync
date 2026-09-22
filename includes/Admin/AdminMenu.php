<?php
/**
 * The KURABU admin menu.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync\Admin;

use Kurabu\WPSync\Admin\Pages\AbstractPage;
use Kurabu\WPSync\Admin\Pages\CalendarPage;
use Kurabu\WPSync\Admin\Pages\DepartmentsPage;
use Kurabu\WPSync\Admin\Pages\LogPage;
use Kurabu\WPSync\Admin\Pages\MappingPage;
use Kurabu\WPSync\Admin\Pages\SettingsPage;
use Kurabu\WPSync\Admin\Pages\StatusPage;
use Kurabu\WPSync\Admin\Pages\SyncPage;
use Kurabu\WPSync\Admin\Pages\TemplatesPage;
use Kurabu\WPSync\Admin\Pages\TrainingsPage;
use Kurabu\WPSync\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top level "KURABU" menu and its screens.
 */
final class AdminMenu {

	public const MENU_SLUG = 'kurabu-wp-sync';

	/**
	 * The screens, in menu order.
	 *
	 * @var AbstractPage[]
	 */
	private array $pages = array();

	/**
	 * Hooks the menu into the admin.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Builds the screen list.
	 *
	 * The first entry is also the top level screen.
	 *
	 * @return AbstractPage[]
	 */
	private function pages(): array {
		if ( ! $this->pages ) {
			$this->pages = array(
				new SettingsPage(),
				new SyncPage(),
				new StatusPage(),
				new LogPage(),
				new DepartmentsPage(),
				new TrainingsPage(),
				new MappingPage(),
				new CalendarPage(),
				new TemplatesPage(),
			);

			/**
			 * Filters the admin screens.
			 *
			 * Later layers add their own screens here rather than editing
			 * this class.
			 *
			 * @param AbstractPage[] $pages Registered screens.
			 */
			$this->pages = (array) apply_filters( 'kurabu_wp_sync_admin_pages', $this->pages );
		}

		return $this->pages;
	}

	/**
	 * Registers the menu and every screen.
	 */
	public function add_menu(): void {
		$pages = $this->pages();
		$first = $pages[0] ?? null;

		if ( ! $first instanceof AbstractPage ) {
			return;
		}

		$hook = add_menu_page(
			__( 'KURABU', 'kurabu-wp-sync' ),
			__( 'KURABU', 'kurabu-wp-sync' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			array( $first, 'render' ),
			'dashicons-groups',
			58
		);

		$this->on_load( $hook, $first );

		foreach ( $pages as $page ) {
			$hook = add_submenu_page(
				self::MENU_SLUG,
				$page->page_title(),
				$page->menu_title(),
				Plugin::CAPABILITY,
				$page->slug(),
				array( $page, 'render' )
			);

			$this->on_load( $hook, $page );
		}
	}

	/**
	 * Lets a screen handle its form submission before rendering.
	 *
	 * @param string|false $hook Screen hook returned by add_*_page().
	 * @param AbstractPage $page The screen.
	 */
	private function on_load( $hook, AbstractPage $page ): void {
		if ( ! is_string( $hook ) || '' === $hook ) {
			return;
		}

		add_action( 'load-' . $hook, array( $page, 'handle_request' ) );
	}
}

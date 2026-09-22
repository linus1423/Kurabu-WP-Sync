<?php
/**
 * Plugin container.
 *
 * @package Kurabu\WPSync
 */

declare( strict_types=1 );

namespace Kurabu\WPSync;

use Kurabu\WPSync\Admin\AdminMenu;
use Kurabu\WPSync\Database\Repository\DepartmentRepository;
use Kurabu\WPSync\Database\Repository\LocationRepository;
use Kurabu\WPSync\Database\Repository\TeamRepository;
use Kurabu\WPSync\Database\Repository\TrainingRepository;
use Kurabu\WPSync\Database\Repository\TrainingTimeRepository;
use Kurabu\WPSync\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the layers together and hands out the shared repositories.
 *
 * The layers stay separate on purpose (see ARCHITECTURE.md): the sync engine
 * writes into the repositories, shortcodes and the template engine only read
 * from them. This container is the one place they meet.
 */
final class Plugin {

	/**
	 * Cron hook the sync engine schedules its runs on.
	 */
	public const CRON_HOOK = 'kurabu_wp_sync_run';

	/**
	 * Capability required to manage the plugin.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Singleton instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Lazily created services.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether boot() already ran.
	 */
	private bool $booted = false;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {
	}

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the plugin's WordPress hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );

		if ( is_admin() ) {
			( new AdminMenu() )->register();
		}

		/**
		 * Fires once the container is ready.
		 *
		 * The sync engine and the shortcode layer hook in here instead of
		 * being hard-wired into this class.
		 *
		 * @param Plugin $plugin The container.
		 */
		do_action( 'kurabu_wp_sync_booted', $this );
	}

	/**
	 * Loads the translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kurabu-wp-sync',
			false,
			dirname( plugin_basename( KURABU_WP_SYNC_FILE ) ) . '/languages'
		);
	}

	/**
	 * Runs pending schema upgrades after a plugin update.
	 */
	public function maybe_upgrade(): void {
		Schema::maybe_install();
	}

	/**
	 * Repository for Abteilungen.
	 */
	public function departments(): DepartmentRepository {
		return $this->service( DepartmentRepository::class );
	}

	/**
	 * Repository for Trainingsgruppen.
	 */
	public function teams(): TeamRepository {
		return $this->service( TeamRepository::class );
	}

	/**
	 * Repository for Trainingsorte.
	 */
	public function locations(): LocationRepository {
		return $this->service( LocationRepository::class );
	}

	/**
	 * Repository for Trainings.
	 */
	public function trainings(): TrainingRepository {
		return $this->service( TrainingRepository::class );
	}

	/**
	 * Repository for Trainingszeiten.
	 */
	public function training_times(): TrainingTimeRepository {
		return $this->service( TrainingTimeRepository::class );
	}

	/**
	 * Returns a lazily instantiated service.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return mixed
	 */
	private function service( string $class_name ) {
		if ( ! isset( $this->services[ $class_name ] ) ) {
			$this->services[ $class_name ] = new $class_name();
		}

		return $this->services[ $class_name ];
	}
}

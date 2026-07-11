<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Core\Capabilities;
use Ghostwriter\Rest\ProjectsController;

/**
 * Menu di amministrazione e assets. Le pagine leggono i dati server-side
 * (via container) e mutano SEMPRE attraverso la REST API ghostwriter/v1
 * (fetch + nonce): un solo percorso di scrittura.
 */
final class Menu {

	public const SLUG_PROJECTS = 'ghostwriter';
	public const SLUG_NEW      = 'ghostwriter-new';
	public const SLUG_CHAPTERS = 'ghostwriter-chapters';
	public const SLUG_THEMES   = 'ghostwriter-themes';
	public const SLUG_SKILLS   = 'ghostwriter-skills';
	public const SLUG_SETTINGS = 'ghostwriter-settings';

	/** @var string[] Hook suffix delle nostre pagine (per gli assets). */
	private array $hooks = array();

	public function __construct(
		private ProjectsPage $projects,
		private NewProjectPage $new_project,
		private ChaptersPage $chapters,
		private ThemesPage $themes,
		private SkillsPage $skills,
		private SettingsPage $settings
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'Ghostwriter', 'ghostwriter' ),
			__( 'Ghostwriter', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_PROJECTS,
			array( $this->projects, 'render' ),
			'dashicons-book-alt',
			26
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Progetti', 'ghostwriter' ),
			__( 'Progetti', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_PROJECTS,
			array( $this->projects, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Aggiungi progetto', 'ghostwriter' ),
			__( 'Aggiungi progetto', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_NEW,
			array( $this->new_project, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Capitoli', 'ghostwriter' ),
			__( 'Capitoli', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_CHAPTERS,
			array( $this->chapters, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Temi', 'ghostwriter' ),
			__( 'Temi', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_THEMES,
			array( $this->themes, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Skills', 'ghostwriter' ),
			__( 'Skills', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_SKILLS,
			array( $this->skills, 'render' )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Impostazioni', 'ghostwriter' ),
			__( 'Impostazioni', 'ghostwriter' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG_SETTINGS,
			array( $this->settings, 'render' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'gw-admin',
			GHOSTWRITER_PLUGIN_URL . 'assets/css/gw-admin.css',
			array(),
			GHOSTWRITER_VERSION
		);

		wp_enqueue_script(
			'gw-admin',
			GHOSTWRITER_PLUGIN_URL . 'assets/js/gw-admin.js',
			array(),
			GHOSTWRITER_VERSION,
			true
		);

		wp_localize_script(
			'gw-admin',
			'gwAdmin',
			array(
				'restRoot' => esc_url_raw( rest_url( ProjectsController::REST_NAMESPACE ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'confirm'  => __( 'Confermi?', 'ghostwriter' ),
					'queued'   => __( 'Operazione accodata. La pagina si ricarica…', 'ghostwriter' ),
					'error'    => __( 'Errore', 'ghostwriter' ),
					'feedback' => __( 'Descrivi cosa non va nel blocco (il feedback guida la riscrittura):', 'ghostwriter' ),
				),
			)
		);
	}
}

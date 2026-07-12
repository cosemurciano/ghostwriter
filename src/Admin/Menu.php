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

	/** @var callable(class-string): object */
	private $resolve;

	/**
	 * Le pagine si risolvono SOLO dentro i callback (lazy): costruirle su
	 * plugins_loaded è vietato — WP_List_Table & co. richiedono gli include
	 * admin, non ancora caricati (500 altrimenti).
	 *
	 * @param callable(class-string): object $resolve Risolve una pagina dal container.
	 */
	public function __construct( callable $resolve ) {
		$this->resolve = $resolve;
	}

	private function page_callback( string $page_class ): callable {
		return function () use ( $page_class ): void {
			( $this->resolve )( $page_class )->render();
		};
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
			$this->page_callback( ProjectsPage::class ),
			'dashicons-book-alt',
			26
		);

		// NB: stesso slug del top-level SENZA callback — ripassarlo qui
		// registrerebbe due azioni sullo stesso hook e la pagina verrebbe
		// renderizzata due volte (campi duplicati).
		add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Progetti', 'ghostwriter' ),
			__( 'Progetti', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_PROJECTS
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Aggiungi progetto', 'ghostwriter' ),
			__( 'Aggiungi progetto', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_NEW,
			$this->page_callback( NewProjectPage::class )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Capitoli', 'ghostwriter' ),
			__( 'Capitoli', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_CHAPTERS,
			$this->page_callback( ChaptersPage::class )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Temi', 'ghostwriter' ),
			__( 'Temi', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_THEMES,
			$this->page_callback( ThemesPage::class )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Skills', 'ghostwriter' ),
			__( 'Skills', 'ghostwriter' ),
			Capabilities::MANAGE_PROJECTS,
			self::SLUG_SKILLS,
			$this->page_callback( SkillsPage::class )
		);

		$this->hooks[] = (string) add_submenu_page(
			self::SLUG_PROJECTS,
			__( 'Impostazioni', 'ghostwriter' ),
			__( 'Impostazioni', 'ghostwriter' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG_SETTINGS,
			$this->page_callback( SettingsPage::class )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_media(); // Selettore media per fonti/copertina.

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
					'queue'    => array(
						'running'   => __( 'in esecuzione', 'ghostwriter' ),
						'queued'    => __( 'in coda', 'ghostwriter' ),
						'attempt'   => __( 'tentativo %1$d di %2$d', 'ghostwriter' ),
						'nextRun'   => __( 'prossimo passaggio alle %s', 'ghostwriter' ),
						'lastError' => __( 'Ultimo tentativo fallito:', 'ghostwriter' ),
					),
				),
			)
		);
	}
}

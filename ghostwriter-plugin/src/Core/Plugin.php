<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

use Ghostwriter\Domain\BlockRevisionService;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\SourceRegistry;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Repository\UsageRepository;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Service container minimale e punto unico di registrazione degli hook.
 *
 * Nessun framework: un array di factory, risoluzione lazy, istanze condivise.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string, callable(Plugin): object> */
	private array $factories = array();

	/** @var array<string, object> */
	private array $services = array();

	private function __construct() {
		$this->factories = array(
			SchemaValidator::class      => static fn(): object => new SchemaValidator( GHOSTWRITER_PLUGIN_DIR . 'schemas' ),
			LogRepository::class        => static fn(): object => new LogRepository(),
			UsageRepository::class      => static fn(): object => new UsageRepository(),
			ProjectRepository::class    => static fn( Plugin $c ): object => new ProjectRepository( $c->get( SchemaValidator::class ) ),
			ChapterRepository::class    => static fn( Plugin $c ): object => new ChapterRepository( $c->get( SchemaValidator::class ) ),
			StateMachine::class         => static fn( Plugin $c ): object => new StateMachine( $c->get( LogRepository::class ) ),
			Dossier::class              => static fn( Plugin $c ): object => new Dossier( $c->get( ProjectRepository::class ) ),
			SourceRegistry::class       => static fn( Plugin $c ): object => new SourceRegistry( $c->get( ProjectRepository::class ) ),
			BlockRevisionService::class => static fn( Plugin $c ): object => new BlockRevisionService(
				$c->get( ChapterRepository::class ),
				$c->get( LogRepository::class )
			),
		);
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Risolve un servizio dal container (istanza condivisa).
	 *
	 * @template T of object
	 * @param class-string<T> $id Classe del servizio.
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( "Servizio non registrato: {$id}" );
			}
			$this->services[ $id ] = ( $this->factories[ $id ] )( $this );
		}
		/** @var T */
		return $this->services[ $id ];
	}

	/**
	 * Sovrascrive una factory (usato nei test per iniettare mock).
	 *
	 * @param class-string             $id      Classe del servizio.
	 * @param callable(Plugin): object $factory Factory sostitutiva.
	 */
	public function register_factory( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * Registra tutti gli hook del plugin. Chiamato una sola volta su plugins_loaded.
	 */
	public function register(): void {
		add_action( 'init', array( PostTypes::class, 'register' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Action Scheduler: caricato come libreria (non richiede WooCommerce).
		$action_scheduler = GHOSTWRITER_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
		if ( file_exists( $action_scheduler ) ) {
			require_once $action_scheduler;
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ghostwriter', false, dirname( plugin_basename( GHOSTWRITER_PLUGIN_FILE ) ) . '/languages' );
	}
}

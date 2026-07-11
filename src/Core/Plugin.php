<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

use Ghostwriter\Ai\ContextComposer;
use Ghostwriter\Ai\LocalRagService;
use Ghostwriter\Ai\PhaseSchemas;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\ProviderRouter;
use Ghostwriter\Ai\RagServiceInterface;
use Ghostwriter\Ai\SkillsManager;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\BlockRevisionService;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\SourceRegistry;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Dispatcher;
use Ghostwriter\Queue\Jobs\DraftChapterJob;
use Ghostwriter\Queue\Jobs\ExportJob;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Queue\Jobs\IndexChapterJob;
use Ghostwriter\Queue\Jobs\IngestSourcesJob;
use Ghostwriter\Queue\Jobs\MaterializeChaptersJob;
use Ghostwriter\Queue\Jobs\ProposeOutlineJob;
use Ghostwriter\Queue\Jobs\ReviewChapterJob;
use Ghostwriter\Queue\Jobs\RewriteBlockJob;
use Ghostwriter\Queue\Jobs\SynopsisJob;
use Ghostwriter\Queue\PipelineRouter;
use Ghostwriter\Rendering\BlockRenderer;
use Ghostwriter\Rest\ChaptersController;
use Ghostwriter\Rest\ProjectsController;
use Ghostwriter\Rest\RegistryController;
use Ghostwriter\Rendering\BookAssembler;
use Ghostwriter\Rendering\EpubExporter;
use Ghostwriter\Rendering\PdfExporter;
use Ghostwriter\Rendering\ThemeCompiler\EpubCssCompiler;
use Ghostwriter\Rendering\ThemeCompiler\MpdfCssCompiler;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Repository\RagChunkRepository;
use Ghostwriter\Repository\UsageRepository;
use Ghostwriter\Sources\TextExtractor;
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
			BlockRenderer::class        => static fn(): object => new BlockRenderer(),
			MpdfCssCompiler::class      => static fn(): object => new MpdfCssCompiler(),
			EpubCssCompiler::class      => static fn(): object => new EpubCssCompiler(),
			ThemeRegistry::class        => static function ( Plugin $c ): object {
				$uploads = wp_upload_dir();
				return new ThemeRegistry(
					$c->get( SchemaValidator::class ),
					GHOSTWRITER_PLUGIN_DIR . 'themes-bundled',
					trailingslashit( $uploads['basedir'] ) . 'ghostwriter/themes'
				);
			},
			PdfExporter::class          => static function ( Plugin $c ): object {
				$uploads = wp_upload_dir();
				return new PdfExporter(
					$c->get( BlockRenderer::class ),
					$c->get( MpdfCssCompiler::class ),
					trailingslashit( $uploads['basedir'] ) . 'ghostwriter/cache/mpdf-tmp'
				);
			},
			EpubExporter::class         => static fn( Plugin $c ): object => new EpubExporter(
				$c->get( BlockRenderer::class ),
				$c->get( EpubCssCompiler::class )
			),
			BookAssembler::class        => static fn( Plugin $c ): object => new BookAssembler(
				$c->get( ProjectRepository::class ),
				$c->get( ChapterRepository::class ),
				$c->get( SourceRegistry::class )
			),
			// Agent layer (fase 4): il router sceglie il provider per progetto
			// dalla config (anthropic|openai|mock); chiavi API da wp-config.php.
			PhaseSchemas::class         => static fn(): object => new PhaseSchemas( GHOSTWRITER_PLUGIN_DIR . 'schemas' ),
			SkillsManager::class        => static function (): object {
				$uploads = wp_upload_dir();
				return new SkillsManager( trailingslashit( $uploads['basedir'] ) . 'ghostwriter/skills' );
			},
			RagChunkRepository::class   => static fn(): object => new RagChunkRepository(),
			LocalRagService::class      => static fn( Plugin $c ): object => new LocalRagService( $c->get( RagChunkRepository::class ) ),
			RagServiceInterface::class  => static fn( Plugin $c ): object => $c->get( LocalRagService::class ),
			TextExtractor::class        => static fn(): object => new TextExtractor(),
			ImageService::class         => static fn(): object => new ImageService(),
			ContextComposer::class      => static fn( Plugin $c ): object => new ContextComposer(
				$c->get( SkillsManager::class ),
				$c->get( ProjectRepository::class ),
				$c->get( RagServiceInterface::class )
			),
			ProviderInterface::class    => static fn( Plugin $c ): object => new ProviderRouter(
				$c->get( ProjectRepository::class ),
				$c->get( ContextComposer::class ),
				$c->get( PhaseSchemas::class )
			),
			UsageMeter::class           => static fn( Plugin $c ): object => new UsageMeter(
				$c->get( UsageRepository::class ),
				$c->get( ProjectRepository::class ),
				$c->get( StateMachine::class )
			),
			Dispatcher::class           => static fn( Plugin $c ): object => new Dispatcher(
				static fn( string $job_class ): object => $c->make_job( $job_class ),
				$c->get( LogRepository::class )
			),
			ProjectsController::class   => static fn( Plugin $c ): object => new ProjectsController(
				$c->get( ProjectRepository::class ),
				$c->get( Dossier::class ),
				$c->get( SourceRegistry::class ),
				$c->get( StateMachine::class ),
				$c->get( Dispatcher::class ),
				$c->get( UsageMeter::class )
			),
			ChaptersController::class   => static fn( Plugin $c ): object => new ChaptersController(
				$c->get( ChapterRepository::class ),
				$c->get( BlockRevisionService::class ),
				$c->get( StateMachine::class )
			),
			RegistryController::class   => static fn( Plugin $c ): object => new RegistryController(
				$c->get( ThemeRegistry::class ),
				$c->get( SkillsManager::class )
			),
			PipelineRouter::class       => static fn( Plugin $c ): object => new PipelineRouter(
				$c->get( Dispatcher::class ),
				$c->get( StateMachine::class ),
				$c->get( ProjectRepository::class ),
				$c->get( ChapterRepository::class )
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

		Activator::maybe_upgrade();

		$this->register_queue();

		add_action(
			'rest_api_init',
			function (): void {
				$this->get( ProjectsController::class )->register_routes();
				$this->get( ChaptersController::class )->register_routes();
				$this->get( RegistryController::class )->register_routes();
			}
		);
	}

	/**
	 * Coda e pipeline: job registrati sul Dispatcher, router sugli eventi.
	 */
	private function register_queue(): void {
		$dispatcher = $this->get( Dispatcher::class );
		foreach ( self::JOBS as $job_class ) {
			$dispatcher->register_job( $job_class );
		}

		$this->get( PipelineRouter::class )->register();
	}

	/** @var array<int, class-string<\Ghostwriter\Queue\JobInterface>> */
	private const JOBS = array(
		ProposeOutlineJob::class,
		MaterializeChaptersJob::class,
		DraftChapterJob::class,
		SynopsisJob::class,
		ReviewChapterJob::class,
		RewriteBlockJob::class,
		GenerateImageJob::class,
		IngestSourcesJob::class,
		IndexChapterJob::class,
		ExportJob::class,
	);

	/**
	 * Costruisce un job con le sue dipendenze (usato dal Dispatcher).
	 *
	 * @param class-string $job_class Classe del job.
	 */
	public function make_job( string $job_class ): object {
		return match ( $job_class ) {
			ProposeOutlineJob::class     => new ProposeOutlineJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( Dossier::class ),
				$this->get( StateMachine::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class )
			),
			MaterializeChaptersJob::class => new MaterializeChaptersJob(
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( Dossier::class ),
				$this->get( StateMachine::class ),
				$this->get( LogRepository::class )
			),
			DraftChapterJob::class       => new DraftChapterJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( Dossier::class ),
				$this->get( StateMachine::class ),
				$this->get( SchemaValidator::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class )
			),
			SynopsisJob::class           => new SynopsisJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( Dossier::class ),
				$this->get( StateMachine::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class )
			),
			ReviewChapterJob::class      => new ReviewChapterJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( StateMachine::class ),
				$this->get( SchemaValidator::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class )
			),
			RewriteBlockJob::class       => new RewriteBlockJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( BlockRevisionService::class ),
				$this->get( SchemaValidator::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class ),
				fn( string $class, array $args ) => $this->get( Dispatcher::class )->dispatch( $class, $args )
			),
			GenerateImageJob::class      => new GenerateImageJob(
				$this->get( ProviderInterface::class ),
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( ImageService::class ),
				$this->get( StateMachine::class ),
				$this->get( UsageMeter::class ),
				$this->get( LogRepository::class )
			),
			IngestSourcesJob::class      => new IngestSourcesJob(
				$this->get( SourceRegistry::class ),
				$this->get( TextExtractor::class ),
				$this->get( LocalRagService::class ),
				$this->get( LogRepository::class )
			),
			IndexChapterJob::class       => new IndexChapterJob(
				$this->get( ProjectRepository::class ),
				$this->get( ChapterRepository::class ),
				$this->get( LocalRagService::class ),
				$this->get( LogRepository::class )
			),
			ExportJob::class             => new ExportJob(
				$this->get( ProjectRepository::class ),
				$this->get( BookAssembler::class ),
				$this->get( ThemeRegistry::class ),
				$this->get( PdfExporter::class ),
				$this->get( EpubExporter::class ),
				$this->get( StateMachine::class ),
				$this->get( LogRepository::class )
			),
			default                      => throw new \InvalidArgumentException( "Job sconosciuto: {$job_class}" ),
		};
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'ghostwriter', false, dirname( plugin_basename( GHOSTWRITER_PLUGIN_FILE ) ) . '/languages' );
	}
}

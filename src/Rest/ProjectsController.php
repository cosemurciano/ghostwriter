<?php
declare(strict_types=1);

namespace Ghostwriter\Rest;

use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Core\Capabilities;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\SourceRegistry;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Dispatcher;
use Ghostwriter\Queue\Jobs\ExportJob;
use Ghostwriter\Queue\Jobs\IngestSourcesJob;
use Ghostwriter\Queue\Jobs\ProposeOutlineJob;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Schema\SchemaValidationException;
use Ghostwriter\Translation\DerivedProjectFactory;
use Ghostwriter\Translation\GlossaryService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint progetto (namespace ghostwriter/v1, §9): creazione, stato,
 * fonti, outline, budget, export. Consumo interno dall'admin (cookie auth +
 * nonce REST); mutazioni protette da capability dedicate.
 */
final class ProjectsController {

	public const REST_NAMESPACE = 'ghostwriter/v1';

	public function __construct(
		private ProjectRepository $projects,
		private Dossier $dossier,
		private SourceRegistry $sources,
		private StateMachine $states,
		private Dispatcher $dispatcher,
		private UsageMeter $meter,
		private DerivedProjectFactory $derived,
		private GlossaryService $glossary
	) {
	}

	public function register_routes(): void {
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE_PROJECTS );

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/sources',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_source' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline/propose',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'propose_outline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_outline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_outline' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/derive',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'derive' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/glossary',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_glossary' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/glossary/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_glossary' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/advance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'advance' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/budget/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_budget' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/exports',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_exports' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/exports/(?P<file>[A-Za-z0-9._-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_export' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$title  = (string) $request->get_param( 'title' );
		$config = $request->get_param( 'config' );

		if ( '' === trim( $title ) || ! is_array( $config ) ) {
			return new WP_Error( 'gw_invalid_params', 'title e config sono obbligatori.', array( 'status' => 400 ) );
		}

		try {
			$project_id = $this->projects->create( $title, $config );
			$this->dossier->initialize( $project_id, $config );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_config', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		return new WP_REST_Response( $this->project_payload( $project_id ), 201 );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}
		return new WP_REST_Response( $this->project_payload( $project_id ) );
	}

	public function add_source( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$source = $request->get_param( 'source' );
		if ( ! is_array( $source ) || empty( $source['source_id'] ) ) {
			return new WP_Error( 'gw_invalid_params', 'source con source_id obbligatorio.', array( 'status' => 400 ) );
		}

		try {
			$this->sources->register( $project_id, $source );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_source', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		// Primo ingest: il progetto entra in sources_ingesting.
		$state = $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT );
		if ( StateMachine::can( StateMachine::TYPE_PROJECT, $state, 'sources_ingest_started' ) ) {
			$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'sources_ingest_started' );
		}

		$this->dispatcher->dispatch(
			IngestSourcesJob::class,
			array(
				'project_id' => $project_id,
				'source_id'  => (string) $source['source_id'],
				'force'      => (bool) $request->get_param( 'force' ),
			)
		);

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	public function propose_outline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$this->dispatcher->dispatch( ProposeOutlineJob::class, array( 'project_id' => $project_id ) );

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	public function update_outline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$outline = $request->get_param( 'outline' );
		if ( ! is_array( $outline ) ) {
			return new WP_Error( 'gw_invalid_params', 'outline (array) obbligatorio.', array( 'status' => 400 ) );
		}

		if ( 'outline_proposed' !== $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT ) ) {
			return new WP_Error( 'gw_invalid_state', 'L\'outline è modificabile solo nello stato outline_proposed.', array( 'status' => 409 ) );
		}

		try {
			$dossier = $this->dossier->update(
				$project_id,
				static function ( array $dossier ) use ( $outline ): array {
					$dossier['outline'] = $outline;
					return $dossier;
				}
			);
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_outline', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		return new WP_REST_Response( array( 'outline' => $dossier['outline'] ) );
	}

	public function approve_outline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->transition_endpoint( (int) $request['id'], 'outline_approved' );
	}

	/**
	 * Crea il progetto di traduzione e accoda subito la proposta di glossario
	 * (checkpoint obbligatorio prima di tradurre).
	 */
	public function derive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$language = strtolower( trim( (string) $request->get_param( 'language' ) ) );
		if ( ! preg_match( '/^[a-z]{2}(-[a-z0-9]{2,8})*$/i', $language ) ) {
			return new WP_Error( 'gw_invalid_params', 'language (BCP-47, es. "en", "de") obbligatoria.', array( 'status' => 400 ) );
		}

		try {
			$derived_id = $this->derived->derive( $project_id, $language );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'gw_derive_rejected', $e->getMessage(), array( 'status' => 409 ) );
		}

		$this->dispatcher->dispatch( \Ghostwriter\Queue\Jobs\ProposeGlossaryJob::class, array( 'project_id' => $derived_id ) );

		return new WP_REST_Response( $this->project_payload( $derived_id ), 201 );
	}

	public function update_glossary( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}
		if ( ! $this->projects->is_translation( $project_id ) ) {
			return new WP_Error( 'gw_not_translation', 'Il glossario esiste solo sui progetti di traduzione.', array( 'status' => 409 ) );
		}

		$glossary = $request->get_param( 'glossary' );
		if ( ! is_array( $glossary ) ) {
			return new WP_Error( 'gw_invalid_params', 'glossary (array) obbligatorio.', array( 'status' => 400 ) );
		}

		if ( 'glossary_proposed' !== $this->states->state_of( $project_id, StateMachine::TYPE_TRANSLATION ) ) {
			return new WP_Error( 'gw_invalid_state', 'Il glossario è modificabile solo nello stato glossary_proposed.', array( 'status' => 409 ) );
		}

		try {
			$this->glossary->put( $project_id, $glossary );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_glossary', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		return new WP_REST_Response( array( 'glossary' => $this->glossary->get( $project_id ) ) );
	}

	public function approve_glossary( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}
		if ( ! $this->projects->is_translation( $project_id ) ) {
			return new WP_Error( 'gw_not_translation', 'Il glossario esiste solo sui progetti di traduzione.', array( 'status' => 409 ) );
		}
		return $this->transition_endpoint( $project_id, 'glossary_approved' );
	}

	/**
	 * Avanzamento manuale dei checkpoint di revisione/copertina: applica il
	 * primo evento ammesso tra quelli approvabili dall'admin. (La pipeline
	 * copertina reale arriverà con la fase 6: fino ad allora questo sblocca
	 * il percorso verso l'export.)
	 */
	public function advance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$type  = $this->entity_type( $project_id );
		$state = $this->states->state_of( $project_id, $type );

		foreach ( array( 'review_completed', 'cover_approved' ) as $event ) {
			if ( StateMachine::can( $type, $state, $event ) ) {
				$new_state = $this->states->transition( $project_id, $type, $event, array( 'via' => 'rest_advance' ) );
				return new WP_REST_Response( array( 'state' => $new_state ) );
			}
		}

		return new WP_Error( 'gw_invalid_state', "Nessun avanzamento manuale ammesso dallo stato {$state}.", array( 'status' => 409 ) );
	}

	public function resume_budget( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}
		if ( ! $this->meter->within_budget( $project_id ) ) {
			return new WP_Error( 'gw_budget_still_exceeded', 'Il budget risulta ancora superato: alzare i limiti nella config prima di riprendere.', array( 'status' => 409 ) );
		}
		return $this->transition_endpoint( $project_id, 'budget_resumed' );
	}

	public function usage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}
		return new WP_REST_Response( $this->meter->report( $project_id ) );
	}

	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$theme_id = (string) $request->get_param( 'theme_id' );
		$target   = (string) $request->get_param( 'target' );
		if ( '' === $theme_id || ! in_array( $target, array( 'pdf', 'epub' ), true ) ) {
			return new WP_Error( 'gw_invalid_params', 'theme_id e target (pdf|epub) obbligatori.', array( 'status' => 400 ) );
		}

		$this->dispatcher->dispatch(
			ExportJob::class,
			array(
				'project_id'    => $project_id,
				'theme_id'      => $theme_id,
				'theme_version' => $request->get_param( 'theme_version' ),
				'target'        => $target,
			)
		);

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	public function list_exports( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$exports = get_post_meta( $project_id, ExportJob::META_EXPORTS, true );
		$exports = is_array( $exports ) ? $exports : array();

		foreach ( $exports as $i => $export ) {
			$exports[ $i ]['download_url'] = rest_url( self::REST_NAMESPACE . "/projects/{$project_id}/exports/" . rawurlencode( (string) $export['file'] ) );
		}

		return new WP_REST_Response( array( 'exports' => $exports ) );
	}

	/**
	 * Download autenticato: i file vivono in una cartella protetta
	 * (Deny from all) e vengono serviti solo con capability gw_export.
	 */
	public function download_export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		$file       = (string) $request['file'];

		// La route ammette solo [A-Za-z0-9._-]: niente traversal; doppia difesa.
		if ( basename( $file ) !== $file || str_contains( $file, '..' ) ) {
			return new WP_Error( 'gw_invalid_file', 'Nome file non valido.', array( 'status' => 400 ) );
		}

		$exports = get_post_meta( $project_id, ExportJob::META_EXPORTS, true );
		$exports = is_array( $exports ) ? array_column( $exports, 'file' ) : array();
		if ( ! in_array( $file, $exports, true ) ) {
			return self::not_found();
		}

		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . 'ghostwriter/' . $project_id . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return self::not_found();
		}

		$mime = str_ends_with( $file, '.epub' ) ? 'application/epub+zip' : 'application/pdf';

		// Streaming diretto: bypassa la serializzazione JSON del REST server.
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private function transition_endpoint( int $project_id, string $event ): WP_REST_Response|WP_Error {
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$type  = $this->entity_type( $project_id );
		$state = $this->states->state_of( $project_id, $type );
		if ( ! StateMachine::can( $type, $state, $event, $this->previous_state( $project_id ) ) ) {
			return new WP_Error( 'gw_invalid_state', "Evento {$event} non ammesso dallo stato {$state}.", array( 'status' => 409 ) );
		}

		$new_state = $this->states->transition( $project_id, $type, $event, array( 'via' => 'rest' ) );

		return new WP_REST_Response( array( 'state' => $new_state ) );
	}

	/**
	 * I progetti derivati seguono la macchina a stati della traduzione.
	 */
	private function entity_type( int $project_id ): string {
		return $this->projects->is_translation( $project_id )
			? StateMachine::TYPE_TRANSLATION
			: StateMachine::TYPE_PROJECT;
	}

	private function previous_state( int $project_id ): ?string {
		$previous = (string) get_post_meta( $project_id, StateMachine::META_PREVIOUS_STATE, true );
		return '' !== $previous ? $previous : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function project_payload( int $project_id ): array {
		$type = $this->entity_type( $project_id );

		return array(
			'id'      => $project_id,
			'type'    => $type,
			'title'   => get_the_title( $project_id ),
			'state'   => $this->states->state_of( $project_id, $type ),
			'config'  => $this->projects->get_config( $project_id ),
			'dossier' => $this->projects->get_dossier( $project_id ),
			'usage'   => $this->meter->report( $project_id ),
		);
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'gw_not_found', 'Progetto non trovato.', array( 'status' => 404 ) );
	}
}

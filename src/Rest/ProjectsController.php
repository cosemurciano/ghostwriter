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
use Ghostwriter\Queue\PipelineRouter;
use Ghostwriter\Queue\QueueStatus;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Schema\SchemaValidationException;
use Ghostwriter\Translation\DerivedProjectFactory;
use Ghostwriter\Sources\SourceTester;
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
		private \Ghostwriter\Repository\ChapterRepository $chapters,
		private Dossier $dossier,
		private SourceRegistry $sources,
		private StateMachine $states,
		private Dispatcher $dispatcher,
		private UsageMeter $meter,
		private DerivedProjectFactory $derived,
		private GlossaryService $glossary,
		private \Ghostwriter\Rendering\ThemeRegistry $themes,
		private \Ghostwriter\Rendering\Preflight $preflight_service,
		private SourceTester $tester,
		private QueueStatus $queue,
		private \Ghostwriter\Repository\LogRepository $log,
		private PipelineRouter $router
	) {
	}

	public function register_routes(): void {
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE_PROJECTS );

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'create' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'show' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/sources',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'add_source' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/settings',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'update_settings' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/sources/test',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'test_source' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/vectorstore',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'update_vector_store' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/vectorstore/test',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'test_vector_store' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/queue',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'queue_status' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/chapters',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'add_chapter' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/pipeline/stop',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'stop_pipeline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/pipeline/resume',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'resume_pipeline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline/propose',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'propose_outline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'regenerate_outline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'update_outline' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/outline/approve',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'approve_outline' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/derive',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'derive' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/glossary',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'update_glossary' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/glossary/approve',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'approve_glossary' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/preflight',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'preflight' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/cover',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'update_cover' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/cover/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'regenerate_cover' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/cover/approve',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'approve_cover' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/advance',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'advance' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/budget/resume',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'resume_budget' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/usage',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'usage' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/export',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'export' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/exports',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'list_exports' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::EXPORT ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/projects/(?P<id>\d+)/exports/(?P<file>[A-Za-z0-9._-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'download_export' ),
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

		unset( $config['ai']['budget'] ); // Il budget massimo non esiste più.

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

	/**
	 * Aggiorna le impostazioni del progetto (parziali). Il formato fisico e
	 * i blocchi ammessi sono modificabili solo PRIMA della generazione
	 * (vincolano copertina, immagini e temi); brief e motore AI
	 * sempre. La config risultante è validata contro lo schema.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$type   = $this->entity_type( $project_id );
		$state  = $this->states->state_of( $project_id, $type );
		$config = $this->projects->get_config( $project_id );

		$structure_unlocked = in_array( $state, array( 'setup', 'sources_ingesting', 'outline_proposed', 'glossary_proposed' ), true );

		$brief = $request->get_param( 'brief' );
		if ( is_array( $brief ) ) {
			$config['brief'] = array_merge( (array) ( $config['brief'] ?? array() ), array_intersect_key( $brief, array_flip( array( 'thesis', 'audience', 'genre', 'target_length' ) ) ) );
		}

		if ( null !== $request->get_param( 'title' ) && '' !== trim( (string) $request->get_param( 'title' ) ) ) {
			wp_update_post( array( 'ID' => $project_id, 'post_title' => sanitize_text_field( (string) $request->get_param( 'title' ) ) ) );
		}

		$format = $request->get_param( 'format' );
		if ( is_array( $format ) ) {
			if ( ! $structure_unlocked ) {
				return new WP_Error( 'gw_locked', "Il formato fisico è fissato: la generazione è già partita (stato {$state}).", array( 'status' => 409 ) );
			}
			$config['format'] = array_merge( (array) ( $config['format'] ?? array() ), array_intersect_key( $format, array_flip( array( 'trim_width_mm', 'trim_height_mm', 'print_ready', 'bleed_mm' ) ) ) );
		}

		$blocks = $request->get_param( 'allowed_blocks' );
		if ( is_array( $blocks ) ) {
			if ( ! $structure_unlocked ) {
				return new WP_Error( 'gw_locked', "I blocchi ammessi sono fissati: la generazione è già partita (stato {$state}).", array( 'status' => 409 ) );
			}
			$config['structural_profile']['allowed_blocks'] = array_values( array_map( 'strval', $blocks ) );
		}

		$ai = $request->get_param( 'ai' );
		if ( is_array( $ai ) ) {
			if ( array_key_exists( 'auto_advance', $ai ) ) {
				$config['ai']['auto_advance'] = (bool) $ai['auto_advance'];
			}
			foreach ( array( 'provider', 'model', 'image_provider', 'image_model' ) as $key ) {
				if ( ! array_key_exists( $key, $ai ) ) {
					continue;
				}
				if ( '' === (string) $ai[ $key ] ) {
					// provider e model sono obbligatori nello schema: un campo
					// svuotato per sbaglio non deve far fallire il salvataggio.
					if ( in_array( $key, array( 'image_provider', 'image_model' ), true ) ) {
						unset( $config['ai'][ $key ] );
					}
				} else {
					$config['ai'][ $key ] = (string) $ai[ $key ];
				}
			}
		}

		// Il budget massimo è stato rimosso dal prodotto: ogni salvataggio
		// ripulisce eventuali cap residui dalle config esistenti.
		unset( $config['ai']['budget'] );

		$skills = $request->get_param( 'skills' );
		if ( is_array( $skills ) ) {
			$config['skills'] = array();
			foreach ( $skills as $entry ) {
				if ( ! is_array( $entry ) || '' === (string) ( $entry['skill_id'] ?? '' ) ) {
					continue;
				}
				$config['skills'][] = array(
					'skill_id' => (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $entry['skill_id'] ),
					'version'  => (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $entry['version'] ?? 'latest' ) ),
					'phases'   => array_values( array_map( 'sanitize_key', (array) ( $entry['phases'] ?? array() ) ) ),
				);
			}
		}

		try {
			$this->projects->save_config( $project_id, $config );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_settings', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		return new WP_REST_Response( $this->project_payload( $project_id ) );
	}

	/**
	 * Test di raggiungibilità di una fonte: registrata (source_id) o
	 * ancora da registrare (source nel body).
	 */
	public function test_source( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$source = $request->get_param( 'source' );
		if ( ! is_array( $source ) ) {
			$source_id = (string) $request->get_param( 'source_id' );
			$source    = $this->sources->find( $project_id, $source_id );
			if ( null === $source ) {
				return new WP_Error( 'gw_not_found', 'Fonte non registrata.', array( 'status' => 404 ) );
			}
		}

		$result = $this->tester->test( $source );
		return new WP_REST_Response( array( 'ok' => $result['ok'], 'message' => ( $result['ok'] ? '✓ ' : '✗ ' ) . $result['message'] ), $result['ok'] ? 200 : 422 );
	}

	public function update_vector_store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$config = $this->projects->get_config( $project_id );
		$value  = trim( (string) $request->get_param( 'vector_store_id' ) );

		$config['sources']                    = (array) ( $config['sources'] ?? array() );
		$config['sources']['vector_store_id'] = '' !== $value ? $value : null;

		try {
			$this->projects->save_config( $project_id, $config );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( array( 'vector_store_id' => $config['sources']['vector_store_id'] ) );
	}

	public function test_vector_store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$config = $this->projects->get_config( $project_id );
		$id     = (string) ( $request->get_param( 'vector_store_id' ) ?: ( $config['sources']['vector_store_id'] ?? '' ) );
		$result = $this->tester->test_vector_store( $id, (string) ( $config['ai']['provider'] ?? 'mock' ) );

		return new WP_REST_Response( array( 'ok' => $result['ok'], 'message' => ( $result['ok'] ? '✓ ' : '✗ ' ) . $result['message'] ), $result['ok'] ? 200 : 422 );
	}

	/**
	 * Polling leggero per il widget "Lavori in corso": stato del progetto,
	 * job attivi su Action Scheduler e ultimo evento problematico del log.
	 */
	public function queue_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$jobs = $this->queue->for_project( $project_id );

		$last_issue = null;
		foreach ( $this->log->latest( $project_id, 5 ) as $entry ) {
			if ( in_array( (string) $entry['level'], array( 'warning', 'error' ), true ) ) {
				$context    = is_array( $entry['context'] ) ? $entry['context'] : array();
				$last_issue = array(
					'event'      => (string) $entry['event'],
					'level'      => (string) $entry['level'],
					'created_at' => (string) $entry['created_at'],
					'job'        => isset( $context['job'] ) ? (string) $context['job'] : null,
					'attempt'    => isset( $context['attempt'] ) ? (int) $context['attempt'] : null,
					'error'      => isset( $context['error'] ) ? (string) $context['error'] : null,
				);
				break;
			}
		}

		return new WP_REST_Response(
			array(
				'state'      => $this->states->state_of( $project_id, $this->entity_type( $project_id ) ),
				'jobs'       => $jobs,
				'last_issue' => $last_issue,
			)
		);
	}

	public function propose_outline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$this->dispatcher->dispatch( ProposeOutlineJob::class, array( 'project_id' => $project_id ) );

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	/**
	 * Capitolo aggiunto a mano, vuoto: l'admin lo scrive nell'editor o lo fa
	 * generare con "Scrivi (AI)". Nasce in stato planned, con la sua voce di
	 * outline nel dossier; la posizione è "dopo il capitolo X" o in fondo.
	 */
	public function add_chapter( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$ids = $this->projects->get_chapter_ids( $project_id );
		if ( empty( $ids ) ) {
			return new WP_Error( 'gw_invalid_state', 'I capitoli nascono con l\'approvazione dell\'indice: prima di allora aggiungi la voce direttamente nella tab Indice.', array( 'status' => 409 ) );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'gw_invalid_params', 'Il titolo del capitolo è obbligatorio.', array( 'status' => 400 ) );
		}
		$brief = sanitize_textarea_field( (string) $request->get_param( 'brief' ) );
		$after = (int) $request->get_param( 'after' ); // 0 = in fondo.

		$chapter_id = $this->chapters->create( $project_id, $title, $brief, 0, count( $ids ) );

		$sequence = \Ghostwriter\Repository\ChapterRepository::sequence_insert_after( $ids, $chapter_id, $after );
		$this->chapters->renumber( $sequence );

		// Voce di outline nel dossier: è la memoria che l'AI userà per scriverlo.
		$this->dossier->update_outline_entry(
			$project_id,
			$chapter_id,
			array(
				'parent_id'       => null,
				'title'           => $title,
				'brief'           => $brief,
				'planned_sources' => array(),
				'status'          => 'planned',
				'synopsis'        => null,
			)
		);
		$this->dossier->sync_outline_order( $project_id, $sequence );

		$this->log->log( $project_id, $chapter_id, \Ghostwriter\Repository\LogRepository::LEVEL_INFO, 'chapter_added', array( 'title' => $title ) );

		return new WP_REST_Response( array( 'chapter_id' => $chapter_id ), 201 );
	}

	/**
	 * Stop richiesto dall'utente: flag sul progetto (il router non accoda
	 * più nulla) + cancellazione dei job in coda. I job già in esecuzione
	 * terminano il passo corrente e si fermano lì.
	 */
	public function stop_pipeline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		update_post_meta( $project_id, PipelineRouter::META_STOPPED, '1' );
		$cancelled = $this->queue->cancel_for_project( $project_id );

		$this->log->log( $project_id, null, \Ghostwriter\Repository\LogRepository::LEVEL_INFO, 'pipeline_stopped', array( 'cancelled_jobs' => $cancelled ) );

		return new WP_REST_Response( array( 'stopped' => true, 'cancelled_jobs' => $cancelled ) );
	}

	/**
	 * Ripresa dopo lo stop: rimuove il flag e ri-innesca i passi rimasti a
	 * metà (i job sono idempotenti).
	 */
	public function resume_pipeline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		delete_post_meta( $project_id, PipelineRouter::META_STOPPED );
		$this->log->log( $project_id, null, \Ghostwriter\Repository\LogRepository::LEVEL_INFO, 'pipeline_resumed', array() );
		$this->router->kick( $project_id );

		return new WP_REST_Response( array( 'resumed' => true ) );
	}

	/**
	 * Rigenerazione dell'indice proposto: l'AI lo ricrea tenendo intatti i
	 * capitoli contrassegnati (keep = indici 0-based), guidata dal feedback.
	 */
	public function regenerate_outline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		if ( 'outline_proposed' !== $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT ) ) {
			return new WP_Error( 'gw_invalid_state', 'L\'indice si può rigenerare solo quando è proposto e non ancora approvato.', array( 'status' => 409 ) );
		}

		$this->dispatcher->dispatch(
			ProposeOutlineJob::class,
			array(
				'project_id' => $project_id,
				'regenerate' => true,
				'keep'       => array_values( array_map( 'intval', (array) $request->get_param( 'keep' ) ) ),
				'feedback'   => sanitize_textarea_field( (string) $request->get_param( 'feedback' ) ),
			)
		);

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
					// Il form invia solo i campi editabili (titolo, brief,
					// ordine, stato): quelli invisibili all'utente
					// (planned_sources, parent_id, synopsis, word_count)
					// si conservano dalla voce esistente, mai azzerati.
					$existing = array();
					foreach ( (array) ( $dossier['outline'] ?? array() ) as $entry ) {
						$existing[ (int) ( $entry['chapter_id'] ?? 0 ) ] = (array) $entry;
					}
					foreach ( $outline as $i => $entry ) {
						$chapter_id = (int) ( $entry['chapter_id'] ?? 0 );
						if ( isset( $existing[ $chapter_id ] ) ) {
							$outline[ $i ] = array_merge( $existing[ $chapter_id ], (array) $entry );
						}
					}
					$dossier['outline'] = array_values( $outline );
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
	 * Preflight on-demand per la UI (theme=id@versione, target=pdf|epub).
	 */
	public function preflight( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$theme_key = (string) $request->get_param( 'theme' );
		$target    = (string) $request->get_param( 'target' ) ?: 'pdf';
		$pair      = explode( '@', $theme_key );

		$theme = $this->themes->get( $pair[0] ?? '', $pair[1] ?? null );
		if ( null === $theme ) {
			return new WP_Error( 'gw_not_found', 'Tema non trovato.', array( 'status' => 404 ) );
		}

		$contents = array();
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			$content = $this->chapters->get_content( $chapter_id );
			if ( null !== $content ) {
				$contents[ $chapter_id ] = $content;
			}
		}

		return new WP_REST_Response(
			$this->preflight_service->run(
				$contents,
				$this->projects->get_config( $project_id ),
				$this->projects->get_dossier( $project_id ) ?? array(),
				$theme,
				$target
			)
		);
	}

	/**
	 * Aggiorna il pacchetto copertina (brief, modalità, artwork caricato)
	 * prima della composizione.
	 */
	public function update_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$cover_state = $this->states->state_of( $project_id, StateMachine::TYPE_COVER );
		if ( in_array( $cover_state, array( 'composed', 'approved' ), true ) ) {
			return new WP_Error( 'gw_invalid_state', 'Copertina già composta: usare "rigenera" per ripartire dal brief.', array( 'status' => 409 ) );
		}

		$config = $this->projects->get_config( $project_id );
		$cover  = (array) ( $config['cover'] ?? array() );

		if ( null !== $request->get_param( 'creative_brief' ) ) {
			$cover['creative_brief'] = sanitize_textarea_field( (string) $request->get_param( 'creative_brief' ) );
		}
		if ( null !== $request->get_param( 'mode' ) ) {
			$mode = (string) $request->get_param( 'mode' );
			if ( ! in_array( $mode, array( 'upload', 'ai_generated' ), true ) ) {
				return new WP_Error( 'gw_invalid_params', 'mode: upload|ai_generated.', array( 'status' => 400 ) );
			}
			$cover['mode'] = $mode;
		}
		if ( null !== $request->get_param( 'front_artwork_attachment_id' ) ) {
			$cover['front_artwork_attachment_id'] = (int) $request->get_param( 'front_artwork_attachment_id' ) ?: null;
		}

		$config['cover'] = $cover;
		try {
			$this->projects->save_config( $project_id, $config );
		} catch ( SchemaValidationException $e ) {
			return new WP_Error( 'gw_invalid_cover', $e->getMessage(), array( 'status' => 422, 'errors' => $e->get_errors() ) );
		}

		return new WP_REST_Response( array( 'cover' => $config['cover'] ) );
	}

	/**
	 * Rigenerazione copertina: da composed si torna al brief (rejected);
	 * da pending (ri)parte il brief job.
	 */
	public function regenerate_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$cover_state = $this->states->state_of( $project_id, StateMachine::TYPE_COVER );

		if ( 'composed' === $cover_state ) {
			$new_state = $this->states->transition( $project_id, StateMachine::TYPE_COVER, 'rejected', array( 'via' => 'rest' ) );
			return new WP_REST_Response( array( 'cover_state' => $new_state ) );
		}
		if ( 'artwork_ready' === $cover_state ) {
			$new_state = $this->states->transition( $project_id, StateMachine::TYPE_COVER, 'artwork_ready', array( 'via' => 'rest_regenerate' ) );
			return new WP_REST_Response( array( 'cover_state' => $new_state ) );
		}
		if ( 'pending' === $cover_state ) {
			$this->dispatcher->dispatch( \Ghostwriter\Queue\Jobs\CoverBriefJob::class, array( 'project_id' => $project_id ) );
			return new WP_REST_Response( array( 'queued' => true ), 202 );
		}
		if ( 'brief_ready' === $cover_state ) {
			// Artwork fallito dopo i retry: si rilancia dal brief esistente.
			$this->dispatcher->dispatch( \Ghostwriter\Queue\Jobs\CoverArtworkJob::class, array( 'project_id' => $project_id ) );
			return new WP_REST_Response( array( 'queued' => true ), 202 );
		}

		return new WP_Error( 'gw_invalid_state', "Rigenerazione non ammessa dallo stato copertina {$cover_state}.", array( 'status' => 409 ) );
	}

	public function approve_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$project_id = (int) $request['id'];
		if ( ! $this->projects->exists( $project_id ) ) {
			return self::not_found();
		}

		$cover_state = $this->states->state_of( $project_id, StateMachine::TYPE_COVER );
		if ( ! StateMachine::can( StateMachine::TYPE_COVER, $cover_state, 'approved' ) ) {
			return new WP_Error( 'gw_invalid_state', "Approvazione non ammessa dallo stato copertina {$cover_state}.", array( 'status' => 409 ) );
		}

		// Il router propaga: cover approved → progetto cover_approved.
		$new_state = $this->states->transition( $project_id, StateMachine::TYPE_COVER, 'approved', array( 'via' => 'rest' ) );

		return new WP_REST_Response( array( 'cover_state' => $new_state ) );
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

		// Il budget massimo non esiste più: la ripresa rimuove il cap
		// residuo dalla config, così il meter non rimette in pausa.
		$config = $this->projects->get_config( $project_id );
		if ( isset( $config['ai']['budget'] ) ) {
			unset( $config['ai']['budget'] );
			$this->projects->save_config( $project_id, $config );
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
			'id'          => $project_id,
			'type'        => $type,
			'title'       => get_the_title( $project_id ),
			'state'       => $this->states->state_of( $project_id, $type ),
			'cover_state' => $this->states->state_of( $project_id, StateMachine::TYPE_COVER ),
			'config'      => $this->projects->get_config( $project_id ),
			'dossier'     => $this->projects->get_dossier( $project_id ),
			'usage'       => $this->meter->report( $project_id ),
		);
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'gw_not_found', 'Progetto non trovato.', array( 'status' => 404 ) );
	}

	/**
	 * Avvolge un callback REST: qualunque Throwable (incluse le Error fatali,
	 * es. funzione non definita) diventa un errore JSON leggibile invece di
	 * una risposta vuota con 500, che il client non saprebbe spiegare.
	 */
	private function guarded( string $method ): callable {
		return function ( WP_REST_Request $request ) use ( $method ): WP_REST_Response|WP_Error {
			try {
				return $this->{$method}( $request );
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'gw_internal',
					sprintf( 'Errore interno (%s): %s', $method, $e->getMessage() ),
					array( 'status' => 500 )
				);
			}
		};
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Rest;

use Ghostwriter\Core\Capabilities;
use Ghostwriter\Domain\BlockRevisionService;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Dispatcher;
use Ghostwriter\Queue\Jobs\DraftChapterJob;
use Ghostwriter\Queue\Jobs\GenerateEditorImageJob;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Queue\Jobs\ReviseChapterJob;
use Ghostwriter\Queue\PipelineRouter;
use Ghostwriter\Repository\ChapterRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint capitolo (§9): contenuto, riscrittura blocchi con feedback,
 * cronologia versioni, ripristino, modifica manuale, retry dal passo fallito.
 */
final class ChaptersController {

	public function __construct(
		private ChapterRepository $chapters,
		private BlockRevisionService $revisions,
		private StateMachine $states,
		private Dispatcher $dispatcher,
		private \Ghostwriter\Repository\ProjectRepository $projects,
		private \Ghostwriter\Domain\Dossier $dossier
	) {
	}

	public function register_routes(): void {
		$manage  = static fn(): bool => current_user_can( Capabilities::MANAGE_PROJECTS );
		$approve = static fn(): bool => current_user_can( Capabilities::APPROVE_CONTENT );

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'show' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/draft',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'draft' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/image',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'generate_image' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/image/(?P<request_id>[A-Za-z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'image_status' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/move',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'move' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/revise',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'revise' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'complete' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'retry' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/image',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'generate_block_image' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'rewrite_block' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/versions',
			array(
				'methods'             => 'GET',
				'callback'            => $this->guarded( 'block_versions' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => $this->guarded( 'restore_block' ),
				'permission_callback' => $approve,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => $this->guarded( 'manual_edit_block' ),
				'permission_callback' => $manage,
			)
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		return new WP_REST_Response(
			array(
				'id'         => $chapter_id,
				'title'      => get_the_title( $chapter_id ),
				'project_id' => $this->chapters->get_project_id( $chapter_id ),
				'state'      => $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ),
				'brief'      => $this->chapters->get_brief( $chapter_id ),
				'content'    => $this->chapters->get_content( $chapter_id ),
			)
		);
	}

	/**
	 * Avvia la stesura AI di un singolo capitolo (generazione capitolo per
	 * capitolo: l'utente decide quando scrivere il prossimo).
	 */
	public function draft( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$project_id = $this->chapters->get_project_id( $chapter_id );
		if ( PipelineRouter::is_stopped( $project_id ) ) {
			return new WP_Error( 'gw_pipeline_stopped', 'Elaborazione ferma: riprendila prima di scrivere nuovi capitoli.', array( 'status' => 409 ) );
		}

		$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
		if ( ! in_array( $state, array( 'planned', 'drafting' ), true ) ) {
			return new WP_Error( 'gw_invalid_state', "Il capitolo è in stato {$state}: la stesura si avvia solo da planned.", array( 'status' => 409 ) );
		}

		$this->dispatcher->dispatch(
			DraftChapterJob::class,
			array( 'project_id' => $project_id, 'chapter_id' => $chapter_id )
		);

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	/**
	 * Immagine AI dall'editor del capitolo: prompt libero + dimensione nel
	 * libro. La generazione va in coda; l'editor interroga image_status.
	 */
	public function generate_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$project_id = $this->chapters->get_project_id( $chapter_id );
		$config     = $this->projects->get_config( $project_id );
		if ( '' === (string) ( $config['ai']['image_provider'] ?? '' ) ) {
			return new WP_Error( 'gw_no_image_provider', 'Nessun provider immagini configurato: impostalo nella tab Impostazioni del progetto (Motore AI → Immagini).', array( 'status' => 409 ) );
		}

		$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'gw_invalid_params', 'Descrivi l\'immagine da generare.', array( 'status' => 400 ) );
		}

		$size       = in_array( $request->get_param( 'size' ), array( 'small', 'medium', 'full' ), true ) ? (string) $request->get_param( 'size' ) : 'medium';
		$request_id = strtolower( wp_generate_password( 16, false ) );

		$this->dispatcher->dispatch(
			GenerateEditorImageJob::class,
			array(
				'project_id' => $project_id,
				'chapter_id' => $chapter_id,
				'prompt'     => $prompt,
				'size'       => $size,
				'request_id' => $request_id,
			)
		);

		return new WP_REST_Response( array( 'request_id' => $request_id ), 202 );
	}

	public function image_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = get_transient( GenerateEditorImageJob::TRANSIENT_PREFIX . (string) $request['request_id'] );

		if ( ! is_array( $result ) ) {
			return new WP_REST_Response( array( 'status' => 'pending' ) );
		}
		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => (string) $result['error'] ) );
		}

		$attachment_id = (int) ( $result['attachment_id'] ?? 0 );
		return new WP_REST_Response(
			array(
				'status'        => 'ready',
				'attachment_id' => $attachment_id,
				'url'           => (string) wp_get_attachment_url( $attachment_id ),
			)
		);
	}

	/**
	 * Sposta il capitolo di una posizione (su/giù): aggiorna menu_order di
	 * tutta la sequenza e riallinea l'outline del dossier. L'indice del
	 * libro all'export segue automaticamente.
	 */
	public function move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$direction = (string) $request->get_param( 'direction' );
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			return new WP_Error( 'gw_invalid_params', 'direction deve essere up o down.', array( 'status' => 400 ) );
		}

		$project_id = $this->chapters->get_project_id( $chapter_id );
		$ids        = $this->projects->get_chapter_ids( $project_id );
		$sequence   = ChapterRepository::sequence_move( $ids, $chapter_id, $direction );

		if ( $sequence === $ids ) {
			return new WP_REST_Response( array( 'moved' => false ) );
		}

		$this->chapters->renumber( $sequence );
		if ( null !== $this->dossier->get( $project_id ) ) {
			$this->dossier->sync_outline_order( $project_id, $sequence );
		}

		return new WP_REST_Response( array( 'moved' => true ) );
	}

	/**
	 * Riscrittura del capitolo (e opzionalmente del titolo) su istruzioni
	 * dell'utente: l'area prompt nell'editor del capitolo.
	 */
	public function revise( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$project_id = $this->chapters->get_project_id( $chapter_id );
		if ( PipelineRouter::is_stopped( $project_id ) ) {
			return new WP_Error( 'gw_pipeline_stopped', 'Elaborazione ferma: riprendila prima di chiedere riscritture.', array( 'status' => 409 ) );
		}
		if ( null === $this->chapters->get_content( $chapter_id ) ) {
			return new WP_Error( 'gw_no_content', 'Il capitolo non ha ancora contenuto: scrivilo prima con "Scrivi (AI)" o nell\'editor.', array( 'status' => 409 ) );
		}

		$feedback = trim( sanitize_textarea_field( (string) $request->get_param( 'feedback' ) ) );
		if ( '' === $feedback ) {
			return new WP_Error( 'gw_invalid_params', 'Scrivi le istruzioni per la riscrittura.', array( 'status' => 400 ) );
		}

		$this->dispatcher->dispatch(
			ReviseChapterJob::class,
			array(
				'project_id'  => $project_id,
				'chapter_id'  => $chapter_id,
				'feedback'    => $feedback,
				'allow_title' => (bool) $request->get_param( 'allow_title' ),
			)
		);

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	/**
	 * Capitolo scritto a mano dichiarato pronto: entra tra i completi
	 * (progressi, indicizzazione RAG, export). Richiede contenuto salvato.
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		if ( null === $this->chapters->get_content( $chapter_id ) ) {
			return new WP_Error( 'gw_no_content', 'Il capitolo è ancora vuoto: scrivilo nell\'editor e salva prima di segnarlo completo.', array( 'status' => 409 ) );
		}

		$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
		if ( ! StateMachine::can( StateMachine::TYPE_CHAPTER, $state, 'manual_completed' ) ) {
			return new WP_Error( 'gw_invalid_state', "Il capitolo è in stato {$state}: la chiusura manuale vale per i capitoli scritti a mano.", array( 'status' => 409 ) );
		}

		$new_state = $this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'manual_completed', array( 'via' => 'rest' ) );

		return new WP_REST_Response( array( 'state' => $new_state ) );
	}

	public function retry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$previous = (string) get_post_meta( $chapter_id, StateMachine::META_PREVIOUS_STATE, true ) ?: null;
		$state    = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );

		if ( ! StateMachine::can( StateMachine::TYPE_CHAPTER, $state, 'retry', $previous ) ) {
			return new WP_Error( 'gw_invalid_state', "Retry non ammesso dallo stato {$state}.", array( 'status' => 409 ) );
		}

		$new_state = $this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'retry', array( 'via' => 'rest' ) );

		return new WP_REST_Response( array( 'state' => $new_state ) );
	}

	public function rewrite_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		$block_id   = (string) $request['block_id'];
		$feedback   = trim( (string) $request->get_param( 'feedback' ) );

		if ( '' === $feedback ) {
			return new WP_Error( 'gw_invalid_params', 'Il feedback è obbligatorio: è la traccia del perché della riscrittura.', array( 'status' => 400 ) );
		}

		try {
			$this->revisions->request_rewrite(
				$chapter_id,
				$block_id,
				$feedback,
				get_current_user_id(),
				(bool) $request->get_param( 'refresh_synopsis' )
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'gw_rewrite_rejected', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	/**
	 * Genera l'immagine di un blocco figura ancora irrisolto (placeholder
	 * con image_brief ma senza attachment). Un prompt opzionale sostituisce
	 * o fornisce il brief prima dell'accodamento.
	 */
	public function generate_block_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		$block_id   = (string) $request['block_id'];
		if ( ! $this->chapters->exists( $chapter_id ) ) {
			return self::not_found( 'Capitolo' );
		}

		$project_id = $this->chapters->get_project_id( $chapter_id );
		if ( PipelineRouter::is_stopped( $project_id ) ) {
			return new WP_Error( 'gw_pipeline_stopped', 'Elaborazione ferma: riprendila prima di generare immagini.', array( 'status' => 409 ) );
		}

		$config = $this->projects->get_config( $project_id );
		if ( '' === (string) ( $config['ai']['image_provider'] ?? '' ) ) {
			return new WP_Error( 'gw_no_image_provider', 'Nessun provider immagini configurato: impostalo nella tab Impostazioni del progetto (Motore AI → Immagini).', array( 'status' => 409 ) );
		}

		$block = $this->chapters->find_block( $chapter_id, $block_id );
		if ( null === $block ) {
			return self::not_found( 'Blocco' );
		}
		if ( 'figura' !== ( $block['type'] ?? '' ) ) {
			return new WP_Error( 'gw_invalid_block', 'Il blocco non è una figura: la generazione immagini vale solo per le figure.', array( 'status' => 409 ) );
		}
		if ( ! empty( $block['props']['attachment_id'] ) ) {
			return new WP_Error( 'gw_image_exists', 'La figura ha già un\'immagine: per sostituirla rimuovila prima dall\'editor del capitolo.', array( 'status' => 409 ) );
		}

		$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
		if ( '' !== $prompt ) {
			$block['props']['image_brief'] = $prompt;
			$this->chapters->replace_block( $chapter_id, $block );
		} elseif ( '' === trim( (string) ( $block['props']['image_brief'] ?? '' ) ) ) {
			return new WP_Error( 'gw_invalid_params', 'La figura non ha una descrizione (image_brief): scrivi cosa deve rappresentare.', array( 'status' => 400 ) );
		}

		$this->dispatcher->dispatch(
			GenerateImageJob::class,
			array( 'project_id' => $project_id, 'chapter_id' => $chapter_id, 'block_id' => $block_id )
		);

		return new WP_REST_Response( array( 'queued' => true ), 202 );
	}

	public function block_versions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		$block_id   = (string) $request['block_id'];

		$current = $this->chapters->find_block( $chapter_id, $block_id );
		if ( null === $current ) {
			return self::not_found( 'Blocco' );
		}

		return new WP_REST_Response(
			array(
				'current'  => $current,
				'versions' => $this->revisions->history( $block_id ),
			)
		);
	}

	public function restore_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		$block_id   = (string) $request['block_id'];
		$version    = (int) $request->get_param( 'version' );

		if ( $version < 1 ) {
			return new WP_Error( 'gw_invalid_params', 'version (>=1) obbligatoria.', array( 'status' => 400 ) );
		}

		try {
			$block = $this->revisions->restore( $chapter_id, $block_id, $version, get_current_user_id() );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'gw_restore_rejected', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'block' => $block ) );
	}

	public function manual_edit_block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$chapter_id = (int) $request['id'];
		$block_id   = (string) $request['block_id'];
		$block      = $request->get_param( 'block' );

		if ( ! is_array( $block ) ) {
			return new WP_Error( 'gw_invalid_params', 'block (oggetto) obbligatorio.', array( 'status' => 400 ) );
		}

		try {
			$saved = $this->revisions->manual_edit( $chapter_id, $block_id, $block, get_current_user_id() );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'gw_edit_rejected', $e->getMessage(), array( 'status' => 409 ) );
		}

		return new WP_REST_Response( array( 'block' => $saved ) );
	}

	private static function not_found( string $what ): WP_Error {
		return new WP_Error( 'gw_not_found', "{$what} non trovato.", array( 'status' => 404 ) );
	}

	/**
	 * Avvolge un callback REST: qualunque Throwable diventa un errore JSON
	 * leggibile invece di una risposta vuota con 500.
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

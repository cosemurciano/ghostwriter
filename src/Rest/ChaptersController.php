<?php
declare(strict_types=1);

namespace Ghostwriter\Rest;

use Ghostwriter\Core\Capabilities;
use Ghostwriter\Domain\BlockRevisionService;
use Ghostwriter\Domain\StateMachine;
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
		private StateMachine $states
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
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rewrite_block' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/versions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'block_versions' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restore_block' ),
				'permission_callback' => $approve,
			)
		);

		register_rest_route(
			ProjectsController::REST_NAMESPACE,
			'/chapters/(?P<id>\d+)/blocks/(?P<block_id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'manual_edit_block' ),
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
}

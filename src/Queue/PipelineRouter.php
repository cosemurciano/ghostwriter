<?php
declare(strict_types=1);

namespace Ghostwriter\Queue;

use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Jobs\DraftChapterJob;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Queue\Jobs\IndexChapterJob;
use Ghostwriter\Queue\Jobs\MaterializeChaptersJob;
use Ghostwriter\Queue\Jobs\ReviewChapterJob;
use Ghostwriter\Queue\Jobs\RewriteBlockJob;
use Ghostwriter\Queue\Jobs\SynopsisJob;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * La pipeline avanza per eventi: questo router ascolta gw_state_changed e
 * accoda il job successivo (nessun orchestratore monolitico, §4).
 *
 * v1 (fase 3): generazione capitoli SEQUENZIALE — la continuità lo richiede,
 * il capitolo N legge la sinossi dell'N-1. La fase immagini è saltata
 * (arriva in fase 5): revised → complete.
 */
final class PipelineRouter {

	public function __construct(
		private Dispatcher $dispatcher,
		private StateMachine $states,
		private ProjectRepository $projects,
		private ChapterRepository $chapters
	) {
	}

	/**
	 * Aggancia il router agli eventi del plugin.
	 */
	public function register(): void {
		add_action( 'gw_state_changed', array( $this, 'on_state_changed' ), 10, 5 );
		add_action( 'gw_block_rewrite_requested', array( $this, 'on_rewrite_requested' ), 10, 5 );
	}

	public function on_state_changed( int $post_id, string $entity_type, string $from, string $to, string $event ): void {
		if ( StateMachine::TYPE_PROJECT === $entity_type ) {
			$this->on_project_changed( $post_id, $to );
			return;
		}
		if ( StateMachine::TYPE_CHAPTER === $entity_type ) {
			// Budget cap (§4): progetto in paused_budget → accodamento sospeso.
			// Alla ripresa (budget_resumed) la pipeline riparte perché ogni
			// job è idempotente e rilancia dallo stato persistito.
			if ( $this->is_paused( $this->chapters->get_project_id( $post_id ) ) ) {
				return;
			}
			$this->on_chapter_changed( $post_id, $to );
		}
	}

	private function is_paused( int $project_id ): bool {
		return 'paused_budget' === $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT );
	}

	public function on_rewrite_requested( int $chapter_id, string $block_id, string $feedback, int $user_id, bool $refresh_synopsis ): void {
		$block = $this->chapters->find_block( $chapter_id, $block_id );

		$this->dispatcher->dispatch(
			RewriteBlockJob::class,
			array(
				'project_id'       => $this->chapters->get_project_id( $chapter_id ),
				'chapter_id'       => $chapter_id,
				'block_id'         => $block_id,
				'feedback'         => $feedback,
				'user_id'          => $user_id,
				'refresh_synopsis' => $refresh_synopsis,
				'expected_version' => (int) ( $block['version'] ?? 1 ),
			)
		);
	}

	private function on_project_changed( int $project_id, string $to ): void {
		switch ( $to ) {
			case 'outline_approved':
				$this->dispatcher->dispatch( MaterializeChaptersJob::class, array( 'project_id' => $project_id ) );
				break;

			case 'generating':
				$this->dispatch_next_chapter( $project_id );
				break;
		}
	}

	private function on_chapter_changed( int $chapter_id, string $to ): void {
		$project_id = $this->chapters->get_project_id( $chapter_id );

		switch ( $to ) {
			case 'draft_ready':
				$this->dispatcher->dispatch( SynopsisJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
				break;

			case 'in_review':
				$this->dispatcher->dispatch( ReviewChapterJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
				break;

			case 'revised':
				// Figure irrisolte → fase immagini (l'unica parallelizzabile);
				// nessuna figura → il capitolo è completo.
				$pending = GenerateImageJob::unresolved_figure_ids(
					$this->chapters->get_content( $chapter_id )['blocks'] ?? array()
				);
				if ( empty( $pending ) ) {
					$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'completed', array( 'router' => 'no_figures' ) );
					break;
				}
				$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'images_requested', array( 'figures' => count( $pending ) ) );
				break;

			case 'images_pending':
				foreach ( GenerateImageJob::unresolved_figure_ids( $this->chapters->get_content( $chapter_id )['blocks'] ?? array() ) as $block_id ) {
					$this->dispatcher->dispatch(
						GenerateImageJob::class,
						array(
							'project_id' => $project_id,
							'chapter_id' => $chapter_id,
							'block_id'   => $block_id,
						)
					);
				}
				break;

			case 'complete':
				// Capitolo completato nel vector store (se attivo), poi il successivo.
				$this->dispatcher->dispatch( IndexChapterJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
				if ( ! $this->dispatch_next_chapter( $project_id ) ) {
					// Ultimo capitolo: il progetto passa in revisione complessiva.
					$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'generation_completed', array( 'router' => 'all_chapters_complete' ) );
				}
				break;
		}
	}

	/**
	 * Accoda la stesura del primo capitolo ancora in stato planned
	 * (generazione sequenziale). False se non ce ne sono più.
	 */
	private function dispatch_next_chapter( int $project_id ): bool {
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			if ( 'planned' === $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
				$this->dispatcher->dispatch(
					DraftChapterJob::class,
					array( 'project_id' => $project_id, 'chapter_id' => $chapter_id )
				);
				return true;
			}
		}
		return false;
	}
}

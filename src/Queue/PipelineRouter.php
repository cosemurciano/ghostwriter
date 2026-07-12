<?php
declare(strict_types=1);

namespace Ghostwriter\Queue;

use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Jobs\CoverArtworkJob;
use Ghostwriter\Queue\Jobs\CoverBriefJob;
use Ghostwriter\Queue\Jobs\CoverComposeJob;
use Ghostwriter\Queue\Jobs\DraftChapterJob;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Queue\Jobs\IndexChapterJob;
use Ghostwriter\Queue\Jobs\MaterializeChaptersJob;
use Ghostwriter\Queue\Jobs\ReviewChapterJob;
use Ghostwriter\Queue\Jobs\RewriteBlockJob;
use Ghostwriter\Queue\Jobs\SynopsisJob;
use Ghostwriter\Queue\Jobs\TranslateChapterJob;
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

	/**
	 * Flag di stop richiesto dall'utente: finché presente il router non
	 * accoda nulla per il progetto (i job già in esecuzione terminano il
	 * proprio passo, quelli in coda vengono cancellati dall'endpoint di stop).
	 */
	public const META_STOPPED = '_gw_pipeline_stopped';

	public function __construct(
		private Dispatcher $dispatcher,
		private StateMachine $states,
		private ProjectRepository $projects,
		private ChapterRepository $chapters
	) {
	}

	public static function is_stopped( int $project_id ): bool {
		return '1' === (string) get_post_meta( $project_id, self::META_STOPPED, true );
	}

	/**
	 * Aggancia il router agli eventi del plugin.
	 */
	public function register(): void {
		add_action( 'gw_state_changed', array( $this, 'on_state_changed' ), 10, 5 );
		add_action( 'gw_block_rewrite_requested', array( $this, 'on_rewrite_requested' ), 10, 5 );
	}

	public function on_state_changed( int $post_id, string $entity_type, string $from, string $to, string $event ): void {
		$project_id = StateMachine::TYPE_CHAPTER === $entity_type ? $this->chapters->get_project_id( $post_id ) : $post_id;
		if ( self::is_stopped( $project_id ) ) {
			return;
		}
		if ( StateMachine::TYPE_PROJECT === $entity_type ) {
			$this->on_project_changed( $post_id, $to );
			return;
		}
		if ( StateMachine::TYPE_TRANSLATION === $entity_type ) {
			$this->on_translation_changed( $post_id, $to );
			return;
		}
		if ( StateMachine::TYPE_COVER === $entity_type ) {
			$this->on_cover_changed( $post_id, $to );
			return;
		}
		if ( StateMachine::TYPE_CHAPTER === $entity_type ) {
			$project_id = $this->chapters->get_project_id( $post_id );
			// Budget cap (§4): progetto in paused_budget → accodamento sospeso.
			// Alla ripresa (budget_resumed) la pipeline riparte perché ogni
			// job è idempotente e rilancia dallo stato persistito.
			if ( $this->is_paused( $project_id ) ) {
				return;
			}
			if ( $this->projects->is_translation( $project_id ) ) {
				$this->on_translated_chapter_changed( $post_id, $to, $project_id );
				return;
			}
			$this->on_chapter_changed( $post_id, $to );
		}
	}

	/**
	 * Pipeline del progetto di traduzione: glossario approvato → traduzione
	 * sequenziale capitolo per capitolo → revisione complessiva.
	 */
	private function on_translation_changed( int $project_id, string $to ): void {
		switch ( $to ) {
			case 'glossary_approved':
				$this->states->transition( $project_id, StateMachine::TYPE_TRANSLATION, 'translation_started', array( 'router' => 'glossary_approved' ) );
				break;

			case 'translating':
				$this->dispatch_next_translation( $project_id );
				break;
		}
	}

	/**
	 * I capitoli derivati NON ripassano dalla pipeline di stesura
	 * (sinossi/revisione/immagini): conta solo il completamento.
	 */
	private function on_translated_chapter_changed( int $chapter_id, string $to, int $project_id ): void {
		if ( 'complete' !== $to ) {
			return;
		}
		if ( ! $this->dispatch_next_translation( $project_id ) ) {
			$this->states->transition( $project_id, StateMachine::TYPE_TRANSLATION, 'translation_completed', array( 'router' => 'all_chapters_translated' ) );
		}
	}

	private function dispatch_next_translation( int $project_id ): bool {
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			if ( 'planned' === $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
				$this->dispatcher->dispatch(
					TranslateChapterJob::class,
					array( 'project_id' => $project_id, 'chapter_id' => $chapter_id )
				);
				return true;
			}
		}
		return false;
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

			case 'cover_pending':
				// Il progetto entra nella fase copertina: parte il brief.
				$this->dispatcher->dispatch( CoverBriefJob::class, array( 'project_id' => $project_id ) );
				break;
		}
	}

	/**
	 * Pipeline copertina: brief → artwork → composizione locale →
	 * approvazione umana; l'approvazione fa avanzare il progetto.
	 */
	private function on_cover_changed( int $project_id, string $to ): void {
		switch ( $to ) {
			case 'brief_ready':
				$this->dispatcher->dispatch( CoverArtworkJob::class, array( 'project_id' => $project_id ) );
				break;

			case 'artwork_ready':
				$this->dispatcher->dispatch( CoverComposeJob::class, array( 'project_id' => $project_id ) );
				break;

			case 'approved':
				$state = $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT );
				if ( StateMachine::can( StateMachine::TYPE_PROJECT, $state, 'cover_approved' ) ) {
					$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'cover_approved', array( 'router' => 'cover_approved' ) );
				}
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
				// Capitolo completato nel vector store (se attivo).
				$this->dispatcher->dispatch( IndexChapterJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
				if ( ! $this->has_planned_chapters( $project_id ) ) {
					// Ultimo capitolo: il progetto passa in revisione complessiva.
					$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'generation_completed', array( 'router' => 'all_chapters_complete' ) );
				} elseif ( $this->auto_advance( $project_id ) ) {
					// Sequenza automatica solo se richiesta in config: di default
					// il capitolo successivo si avvia dal pulsante "Scrivi (AI)".
					$this->dispatch_next_chapter( $project_id );
				}
				break;
		}
	}

	private function has_planned_chapters( int $project_id ): bool {
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			if ( 'planned' === $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sequenza automatica tra capitoli: opt-in dalla config (ai.auto_advance).
	 * Default: capitolo per capitolo, avviato dall'utente.
	 */
	private function auto_advance( int $project_id ): bool {
		$config = $this->projects->get_config( $project_id );
		return (bool) ( $config['ai']['auto_advance'] ?? false );
	}

	/**
	 * Ri-innesca la pipeline dopo una ripresa: i capitoli fermi a metà passo
	 * ripartono dal loro stato (i job sono idempotenti); se non c'è nulla in
	 * corso e la sequenza automatica è attiva, parte il prossimo planned.
	 */
	public function kick( int $project_id ): void {
		if ( $this->projects->is_translation( $project_id ) ) {
			if ( 'translating' === $this->states->state_of( $project_id, StateMachine::TYPE_TRANSLATION ) ) {
				$this->dispatch_next_translation( $project_id );
			}
			return;
		}

		$busy = false;
		foreach ( $this->projects->get_chapter_ids( $project_id ) as $chapter_id ) {
			switch ( $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
				case 'drafting':
					$this->dispatcher->dispatch( DraftChapterJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
					$busy = true;
					break;
				case 'draft_ready':
					$this->dispatcher->dispatch( SynopsisJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
					$busy = true;
					break;
				case 'in_review':
					$this->dispatcher->dispatch( ReviewChapterJob::class, array( 'project_id' => $project_id, 'chapter_id' => $chapter_id ) );
					$busy = true;
					break;
				case 'images_pending':
					$this->on_chapter_changed( $chapter_id, 'images_pending' );
					$busy = true;
					break;
			}
		}

		if ( ! $busy
			&& $this->auto_advance( $project_id )
			&& 'generating' === $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT ) ) {
			$this->dispatch_next_chapter( $project_id );
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

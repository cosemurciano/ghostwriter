<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Repository\UsageRepository;

/**
 * Ultimo passo di draft_ready: sinossi 100-200 parole + aggiornamenti di
 * continuità nel dossier (lock ottimistico via Dossier). Poi il capitolo
 * passa in revisione.
 */
final class SynopsisJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private Dossier $dossier,
		private StateMachine $states,
		private UsageRepository $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'synopsis';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );
		$refresh    = ! empty( $args['refresh'] );

		$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );

		// Idempotenza (pipeline): se il capitolo è già oltre, esce.
		// In modalità refresh (dopo riscritture sostanziali) la sinossi si
		// rigenera senza toccare lo stato.
		if ( ! $refresh && 'draft_ready' !== $state ) {
			return;
		}

		$content = $this->chapters->get_content( $chapter_id );
		if ( null === $content ) {
			throw new \RuntimeException( "Capitolo {$chapter_id} senza contenuto: impossibile la sinossi." );
		}

		$config = $this->projects->get_config( $project_id );
		$result = $this->provider->complete(
			new AiRequest(
				AiRequest::PHASE_SYNOPSIS,
				array(
					'chapter_title' => (string) ( $content['meta']['title'] ?? '' ),
					'content'       => $content,
				),
				$project_id,
				$chapter_id
			)
		);

		$synopsis = (string) ( $result->content['synopsis'] ?? '' );
		if ( '' === $synopsis ) {
			throw new \RuntimeException( 'Il provider non ha prodotto una sinossi.' );
		}

		$this->dossier->record_synopsis(
			$project_id,
			$chapter_id,
			$synopsis,
			(array) ( $result->content['continuity'] ?? array() )
		);
		$this->dossier->update_outline_entry(
			$project_id,
			$chapter_id,
			array( 'word_count' => (int) ( $content['meta']['word_count'] ?? 0 ) )
		);

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		if ( ! $refresh ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'review_started', array( 'job' => self::name() ) );
		}
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ), 'failed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'failed', array( 'job' => self::name(), 'error' => $e->getMessage() ) );
		}
	}
}

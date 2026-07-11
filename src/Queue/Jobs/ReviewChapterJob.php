<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Fase review: skills di revisione (fase 4) producono il contenuto
 * revisionato, validato e salvato; stato → revised.
 */
final class ReviewChapterJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private StateMachine $states,
		private SchemaValidator $validator,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'review_chapter';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );

		// Idempotenza: solo capitoli in revisione.
		if ( 'in_review' !== $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
			return;
		}

		$content = $this->chapters->get_content( $chapter_id );
		if ( null === $content ) {
			throw new \RuntimeException( "Capitolo {$chapter_id} senza contenuto: impossibile la revisione." );
		}

		$config = $this->projects->get_config( $project_id );
		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_REVIEW, array( 'content' => $content ), $project_id, $chapter_id )
		);

		$revised               = $result->content;
		$revised['chapter_id'] = $chapter_id;

		$errors = $this->validator->get_validation_errors( $revised, SchemaValidator::CHAPTER_CONTENT );
		if ( ! empty( $errors ) ) {
			throw new \RuntimeException( 'Contenuto revisionato non conforme allo schema: ' . wp_json_encode( $errors ) );
		}

		$this->chapters->save_content( $chapter_id, $revised );

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'review_completed', array( 'job' => self::name() ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ), 'failed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'failed', array( 'job' => self::name(), 'error' => $e->getMessage() ) );
		}
	}
}

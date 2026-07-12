<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Riscrittura del capitolo su istruzioni dell'utente (l'area prompt
 * nell'editor): fase review con il feedback come guida, contenuto intero
 * rivalidato e risalvato. Lo stato del capitolo NON cambia: è un ritocco
 * su richiesta, non un passo di pipeline.
 *
 * Se allow_title è true e l'AI propone un meta.title diverso, anche il
 * titolo del post viene aggiornato.
 */
final class ReviseChapterJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private SchemaValidator $validator,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'revise_chapter';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );
		if ( 0 === $project_id ) {
			throw new \RuntimeException( "Capitolo {$chapter_id} senza progetto." );
		}

		$content = $this->chapters->get_content( $chapter_id );
		if ( null === $content ) {
			throw new \RuntimeException( "Capitolo {$chapter_id} senza contenuto: scrivilo prima con l'AI o nell'editor." );
		}

		$feedback    = trim( (string) ( $args['feedback'] ?? '' ) );
		$allow_title = ! empty( $args['allow_title'] );
		if ( '' === $feedback ) {
			throw new \RuntimeException( 'Istruzioni vuote: nulla da riscrivere.' );
		}

		$config = $this->projects->get_config( $project_id );

		$context = array(
			'content'  => $content,
			'feedback' => $feedback
				. ( $allow_title ? ' (Puoi riscrivere anche il titolo del capitolo in meta.title, se le istruzioni lo richiedono.)' : ' (NON cambiare il titolo del capitolo.)' ),
		);

		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_REVIEW, $context, $project_id, $chapter_id )
		);

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		$revised               = ChapterRepository::normalize_content( $result->content );
		$revised['chapter_id'] = $chapter_id;

		$errors = $this->validator->get_validation_errors( $revised, SchemaValidator::CHAPTER_CONTENT );
		if ( ! empty( $errors ) ) {
			throw new \RuntimeException( 'Capitolo riscritto non conforme allo schema: ' . wp_json_encode( $errors ) );
		}

		$new_title = trim( (string) ( ( (array) ( $revised['meta'] ?? array() ) )['title'] ?? '' ) );
		if ( ! $allow_title || '' === $new_title ) {
			// Titolo blindato: si conserva quello corrente anche nel meta.
			$revised['meta']          = (array) ( $revised['meta'] ?? array() );
			$revised['meta']['title'] = get_the_title( $chapter_id );
		} elseif ( $new_title !== get_the_title( $chapter_id ) ) {
			wp_update_post( array( 'ID' => $chapter_id, 'post_title' => $new_title ) );
		}

		$this->chapters->save_content( $chapter_id, $revised );

		$this->log->log( $project_id, $chapter_id, LogRepository::LEVEL_INFO, 'chapter_revised', array( 'feedback' => mb_substr( $feedback, 0, 200 ) ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$this->log->log(
			$this->chapters->get_project_id( $chapter_id ),
			$chapter_id,
			LogRepository::LEVEL_ERROR,
			'chapter_revise_failed',
			array( 'error' => $e->getMessage() )
		);
	}
}

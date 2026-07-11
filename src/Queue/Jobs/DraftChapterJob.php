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
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Stesura di un capitolo: contesto (dossier + brief + capitolo precedente)
 * → contenuto strutturato validato → _gw_content; stato → draft_ready.
 *
 * Se l'output non valida: UN solo retry con l'errore di validazione nel
 * contesto, poi il job fallisce (contratto §6).
 */
final class DraftChapterJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private Dossier $dossier,
		private StateMachine $states,
		private SchemaValidator $validator,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'draft_chapter';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );
		if ( 0 === $project_id ) {
			throw new \RuntimeException( "Capitolo {$chapter_id} senza progetto." );
		}

		// Idempotenza: bozza già pronta (o oltre).
		$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
		if ( ! in_array( $state, array( 'planned', 'drafting' ), true ) ) {
			return;
		}
		if ( 'planned' === $state ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'draft_started', array( 'job' => self::name() ) );
		}

		$config  = $this->projects->get_config( $project_id );
		$dossier = $this->dossier->get( $project_id ) ?? array();

		$context = array(
			'dossier'          => $dossier,
			'chapter_brief'    => $this->outline_entry( $dossier, $chapter_id ),
			'previous_chapter' => $this->previous_chapter_content( $dossier, $chapter_id ),
		);

		$content = $this->complete_validated( $project_id, $chapter_id, $config, $context );

		$this->chapters->save_content( $chapter_id, $content );
		$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'draft_ready', array( 'job' => self::name() ) );
	}

	/**
	 * Chiamata AI + validazione, con un solo retry mirato sull'errore di schema.
	 *
	 * @param array<string, mixed> $config  Config progetto.
	 * @param array<string, mixed> $context Contesto di fase.
	 * @return array<string, mixed> Formato intermedio valido.
	 */
	private function complete_validated( int $project_id, int $chapter_id, array $config, array $context ): array {
		$attempts = 0;
		do {
			$result = $this->provider->complete(
				new AiRequest( AiRequest::PHASE_DRAFT, $context, $project_id, $chapter_id )
			);

			$this->usage->record(
				$project_id,
				self::name(),
				(string) ( $config['ai']['provider'] ?? 'mock' ),
				$result->model,
				$result->input_tokens,
				$result->output_tokens
			);

			$content               = $result->content;
			$content['chapter_id'] = $chapter_id; // L'ID lo decide il sistema, mai il modello.

			$errors = $this->validator->get_validation_errors( $content, SchemaValidator::CHAPTER_CONTENT );
			if ( empty( $errors ) ) {
				return $content;
			}

			$context['validation_errors'] = $errors;
			++$attempts;
		} while ( $attempts < 2 );

		throw new \RuntimeException( 'Output AI non conforme allo schema dopo il retry: ' . wp_json_encode( $errors ) );
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 * @return array<string, mixed>|null Voce di outline del capitolo.
	 */
	private function outline_entry( array $dossier, int $chapter_id ): ?array {
		foreach ( $dossier['outline'] ?? array() as $entry ) {
			if ( (int) $entry['chapter_id'] === $chapter_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Testo integrale del SOLO capitolo precedente (per la transizione).
	 *
	 * @param array<string, mixed> $dossier Dossier corrente.
	 * @return array<string, mixed>|null
	 */
	private function previous_chapter_content( array $dossier, int $chapter_id ): ?array {
		$previous = null;
		foreach ( $dossier['outline'] ?? array() as $entry ) {
			if ( (int) $entry['chapter_id'] === $chapter_id ) {
				break;
			}
			$previous = (int) $entry['chapter_id'];
		}
		return null !== $previous && $previous > 0 ? $this->chapters->get_content( $previous ) : null;
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ), 'failed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'failed', array( 'job' => self::name(), 'error' => $e->getMessage() ) );
		}
	}
}

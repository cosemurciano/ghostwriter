<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Domain\BlockRevisionService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Riscrittura puntuale di un blocco con feedback utente (§5.1): contesto
 * locale (blocchi adiacenti + dossier brief) → il SOLO blocco riscritto,
 * stesso id e stesso type → nuova versione via BlockRevisionService.
 * Con refresh_synopsis, a valle si rigenera la sinossi nel dossier.
 */
final class RewriteBlockJob implements JobInterface {

	/** @var callable(class-string, array<string, mixed>): void */
	private $dispatch;

	/**
	 * @param callable(class-string, array<string, mixed>): void $dispatch Accoda un job successivo (chiusura sul Dispatcher).
	 */
	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private BlockRevisionService $revisions,
		private SchemaValidator $validator,
		private UsageMeter $usage,
		private LogRepository $log,
		callable $dispatch
	) {
		$this->dispatch = $dispatch;
	}

	public static function name(): string {
		return 'rewrite_block';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$block_id   = (string) ( $args['block_id'] ?? '' );
		$feedback   = (string) ( $args['feedback'] ?? '' );
		$user_id    = (int) ( $args['user_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );

		$current = $this->chapters->find_block( $chapter_id, $block_id );
		if ( null === $current ) {
			throw new \RuntimeException( "Blocco {$block_id} non trovato nel capitolo {$chapter_id}." );
		}

		// Idempotenza: se questa richiesta è già stata applicata (l'expected
		// version è passata dagli args alla prima esecuzione), esce.
		$expected_version = (int) ( $args['expected_version'] ?? 0 );
		if ( $expected_version > 0 && (int) ( $current['version'] ?? 1 ) > $expected_version ) {
			return;
		}

		$context = array(
			'block'    => $current,
			'feedback' => $feedback,
			'adjacent' => $this->adjacent_blocks( $chapter_id, $block_id ),
		);

		$config = $this->projects->get_config( $project_id );
		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_REWRITE, $context, $project_id, $chapter_id )
		);

		$new_block = ChapterRepository::normalize_block( $result->content );

		$errors = $this->validator->get_block_validation_errors( $new_block );
		if ( ! empty( $errors ) ) {
			throw new \RuntimeException( 'Blocco riscritto non conforme allo schema: ' . wp_json_encode( $errors ) );
		}

		// Il servizio impone stesso type, archivia la versione corrente con
		// il feedback e incrementa version.
		$this->revisions->write_new_version(
			$chapter_id,
			$block_id,
			$new_block,
			BlockRevisionService::ORIGIN_AI_REWRITE,
			$feedback,
			array( 'model' => $result->model, 'skills' => array() ),
			$user_id ?: null
		);

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		if ( ! empty( $args['refresh_synopsis'] ) ) {
			( $this->dispatch )( SynopsisJob::class, array( 'chapter_id' => $chapter_id, 'refresh' => true ) );
		}
	}

	/**
	 * Blocchi adiacenti (prev/next, per il raccordo) a livello radice.
	 *
	 * @return array{prev: array<string, mixed>|null, next: array<string, mixed>|null}
	 */
	private function adjacent_blocks( int $chapter_id, string $block_id ): array {
		$content = $this->chapters->get_content( $chapter_id ) ?? array();
		$blocks  = array_values( (array) ( $content['blocks'] ?? array() ) );

		foreach ( $blocks as $i => $block ) {
			if ( ( $block['id'] ?? '' ) === $block_id ) {
				return array(
					'prev' => $blocks[ $i - 1 ] ?? null,
					'next' => $blocks[ $i + 1 ] ?? null,
				);
			}
		}
		return array( 'prev' => null, 'next' => null );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		// La riscrittura non regredisce lo stato del capitolo: si logga soltanto.
		$this->log->log(
			$this->chapters->get_project_id( (int) ( $args['chapter_id'] ?? 0 ) ),
			(int) ( $args['chapter_id'] ?? 0 ),
			LogRepository::LEVEL_ERROR,
			'block_rewrite_failed',
			array( 'block_id' => $args['block_id'] ?? '', 'error' => $e->getMessage() )
		);
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\LocalRagService;
use Ghostwriter\Domain\SourceRegistry;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Sources\TextExtractor;

/**
 * Ingestione di una fonte: estrazione testo (PDF/testo/URL) → chunking →
 * vector store del progetto → stato ingest sul registry.
 */
final class IngestSourcesJob implements JobInterface {

	public function __construct(
		private SourceRegistry $sources,
		private TextExtractor $extractor,
		private LocalRagService $rag,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'ingest_source';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );
		$source_id  = (string) ( $args['source_id'] ?? '' );

		$source = $this->sources->find( $project_id, $source_id );
		if ( null === $source ) {
			throw new \RuntimeException( "Fonte {$source_id} non registrata nel progetto {$project_id}." );
		}

		// Idempotenza: fonte già ingerita (la reingestione esplicita passa
		// dal flag force).
		if ( 'ingested' === ( $source['ingest_status'] ?? '' ) && empty( $args['force'] ) ) {
			return;
		}

		$text = $this->extractor->extract( $source );
		if ( '' === $text ) {
			throw new \RuntimeException( "La fonte {$source_id} non ha prodotto testo." );
		}

		$chunk_count = $this->rag->ingest_source( $project_id, $source_id, $text );

		$this->sources->register(
			$project_id,
			array(
				'source_id'     => $source_id,
				'ingest_status' => 'ingested',
				'chunk_count'   => $chunk_count,
			) + $source
		);

		$this->log->log( $project_id, null, LogRepository::LEVEL_INFO, 'source_ingested', array( 'source_id' => $source_id, 'chunks' => $chunk_count ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );
		$source_id  = (string) ( $args['source_id'] ?? '' );

		$source = $this->sources->find( $project_id, $source_id );
		if ( null !== $source ) {
			$this->sources->register( $project_id, array( 'ingest_status' => 'failed' ) + $source );
		}

		$this->log->log( $project_id, null, LogRepository::LEVEL_ERROR, 'source_ingest_failed', array( 'source_id' => $source_id, 'error' => $e->getMessage() ) );
	}
}

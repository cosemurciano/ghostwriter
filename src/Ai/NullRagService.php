<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * RAG spento: nessun passaggio. Usato finché il vector store per progetto
 * non viene implementato (fase 5, insieme a IngestSourcesJob).
 */
final class NullRagService implements RagServiceInterface {

	public function query( int $project_id, string $query, int $k = 5 ): array {
		return array();
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Recupero passaggi rilevanti dal vector store del progetto (fonti +
 * capitoli indicizzati). L'implementazione reale arriva in fase 5 con
 * IngestSourcesJob; fino ad allora la pipeline gira con NullRagService.
 */
interface RagServiceInterface {

	/**
	 * @return array<int, array{text: string, source_id: string|null}> Passaggi rilevanti (testo NON fidato).
	 */
	public function query( int $project_id, string $query, int $k = 5 ): array;
}

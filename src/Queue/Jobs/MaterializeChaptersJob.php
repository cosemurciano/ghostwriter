<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Dopo l'approvazione dell'outline: crea i gw_chapter gerarchici con brief
 * nei meta, sostituisce i chapter_id temporanei del dossier con gli ID reali
 * e avvia la generazione. Nessuna chiamata AI.
 */
final class MaterializeChaptersJob implements JobInterface {

	public function __construct(
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private Dossier $dossier,
		private StateMachine $states,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'materialize_chapters';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );

		// Idempotenza: capitoli già materializzati.
		if ( ! empty( $this->projects->get_chapter_ids( $project_id ) ) ) {
			return;
		}

		$dossier = $this->dossier->get( $project_id );
		if ( null === $dossier || empty( $dossier['outline'] ) ) {
			throw new \RuntimeException( "Dossier senza outline per il progetto {$project_id}: nulla da materializzare." );
		}

		// Creazione capitoli nell'ordine dell'outline (v1: gerarchia piatta;
		// parti/sottocapitoli quando l'outline proposto dichiarerà parent).
		$id_map = array();
		foreach ( $dossier['outline'] as $i => $entry ) {
			$real_id = $this->chapters->create(
				$project_id,
				(string) ( $entry['title'] ?? 'Senza titolo' ),
				(string) ( $entry['brief'] ?? '' ),
				0,
				(int) ( $entry['order'] ?? $i )
			);

			$id_map[ (int) $entry['chapter_id'] ] = $real_id;
		}

		$this->dossier->update(
			$project_id,
			static function ( array $dossier ) use ( $id_map ): array {
				foreach ( $dossier['outline'] as $i => $entry ) {
					$temp_id = (int) $entry['chapter_id'];
					if ( isset( $id_map[ $temp_id ] ) ) {
						$dossier['outline'][ $i ]['chapter_id'] = $id_map[ $temp_id ];
					}
				}
				return $dossier;
			}
		);

		$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'generation_started', array( 'job' => self::name() ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'materialization_failed', array( 'error' => $e->getMessage() ) );
	}
}

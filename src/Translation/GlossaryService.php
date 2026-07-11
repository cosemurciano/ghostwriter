<?php
declare(strict_types=1);

namespace Ghostwriter\Translation;

use Ghostwriter\Domain\Dossier;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Glossario dei progetti di traduzione: estratto dal dossier sorgente PRIMA
 * di tradurre i capitoli, approvato al checkpoint, passato a ogni job.
 * È ciò che garantisce coerenza terminologica dal capitolo 1 al 20.
 */
final class GlossaryService {

	public function __construct(
		private ProjectRepository $projects,
		private Dossier $dossier
	) {
	}

	/**
	 * Termini candidati dal dossier del progetto sorgente: terminologia
	 * introdotta + concetti coperti (deduplicati, ordine di apparizione).
	 *
	 * @param array<string, mixed> $source_dossier Dossier del sorgente.
	 * @return array<int, array{term: string, definition: string}>
	 */
	public static function candidate_terms( array $source_dossier ): array {
		$seen  = array();
		$terms = array();

		foreach ( (array) ( $source_dossier['continuity']['terminology'] ?? array() ) as $entry ) {
			$term = trim( (string) ( $entry['term'] ?? '' ) );
			if ( '' === $term || isset( $seen[ mb_strtolower( $term ) ] ) ) {
				continue;
			}
			$seen[ mb_strtolower( $term ) ] = true;
			$terms[]                        = array(
				'term'       => $term,
				'definition' => (string) ( $entry['definition'] ?? '' ),
			);
		}

		foreach ( (array) ( $source_dossier['continuity']['concepts_covered'] ?? array() ) as $entry ) {
			$term = trim( (string) ( $entry['concept'] ?? '' ) );
			if ( '' === $term || isset( $seen[ mb_strtolower( $term ) ] ) ) {
				continue;
			}
			$seen[ mb_strtolower( $term ) ] = true;
			$terms[]                        = array(
				'term'       => $term,
				'definition' => '',
			);
		}

		return $terms;
	}

	/**
	 * Il glossario corrente del progetto derivato.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get( int $derived_project_id ): array {
		$dossier = $this->projects->get_dossier( $derived_project_id );
		return (array) ( $dossier['glossary'] ?? array() );
	}

	/**
	 * Scrive il glossario proposto/modificato nel dossier del derivato
	 * (validato contro lo schema in salvataggio).
	 *
	 * @param array<int, array<string, mixed>> $glossary Voci {source_term, target_term, note?}.
	 */
	public function put( int $derived_project_id, array $glossary ): void {
		$this->dossier->update(
			$derived_project_id,
			static function ( array $dossier ) use ( $glossary ): array {
				$dossier['glossary'] = array_values( $glossary );
				return $dossier;
			}
		);
	}

	/**
	 * Rappresentazione compatta per il contesto AI dei job di traduzione.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_context( int $derived_project_id ): array {
		return $this->get( $derived_project_id );
	}
}

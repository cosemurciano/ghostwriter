<?php
declare(strict_types=1);

namespace Ghostwriter\Domain;

use Ghostwriter\Repository\ProjectRepository;

/**
 * Lettura e aggiornamento atomico del dossier di progetto.
 *
 * Il dossier è scritto da più job (SynopsisJob di capitoli diversi): si usa
 * un lock ottimistico su updated_at — si legge, si applica la mutazione, si
 * riscrive solo se updated_at è invariato, altrimenti si rilegge e riapplica.
 * Le sezioni sono per-capitolo, quindi il merge è naturale.
 */
final class Dossier {

	private const MAX_RETRIES = 3;

	public function __construct( private ProjectRepository $projects ) {
	}

	/**
	 * @return array<string, mixed>|null Il dossier o null se assente.
	 */
	public function get( int $project_id ): ?array {
		return $this->projects->get_dossier( $project_id );
	}

	/**
	 * Inizializza il dossier dalla config di progetto (parte statica del brief).
	 *
	 * @param array<string, mixed> $config Config validata del progetto.
	 * @return array<string, mixed> Il dossier iniziale.
	 */
	public function initialize( int $project_id, array $config ): array {
		$dossier = array(
			'schema_version' => '1.0',
			'project_id'     => $project_id,
			'updated_at'     => gmdate( 'c' ),
			'brief'          => array(
				'thesis'   => $config['brief']['thesis'] ?? '',
				'audience' => $config['brief']['audience'] ?? '',
				'genre'    => $config['brief']['genre'] ?? '',
				'language' => $config['language'] ?? '',
			),
			'outline'        => array(),
			'continuity'     => array(
				'terminology'      => array(),
				'concepts_covered' => array(),
				'promises'         => array(),
				'style_decisions'  => array(),
			),
		);

		$this->projects->save_dossier( $project_id, $dossier );

		return $dossier;
	}

	/**
	 * Applica una mutazione al dossier con lock ottimistico.
	 *
	 * @param callable(array<string,mixed>): array<string,mixed> $mutator Riceve il dossier corrente, restituisce quello mutato.
	 * @return array<string, mixed> Il dossier salvato.
	 * @throws \RuntimeException Se il dossier non esiste o il lock fallisce oltre i retry.
	 */
	public function update( int $project_id, callable $mutator ): array {
		for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
			$current = $this->projects->get_dossier( $project_id );
			if ( null === $current ) {
				throw new \RuntimeException( "Dossier assente per il progetto {$project_id}: inizializzarlo prima di aggiornarlo." );
			}

			$expected_updated_at = $current['updated_at'] ?? '';

			$mutated               = $mutator( $current );
			$mutated['updated_at'] = gmdate( 'c' );

			// Rilettura di verifica: se un altro job ha scritto nel frattempo, si riapplica.
			$fresh = $this->projects->get_dossier( $project_id );
			if ( ( $fresh['updated_at'] ?? '' ) !== $expected_updated_at ) {
				continue;
			}

			$this->projects->save_dossier( $project_id, $mutated );
			return $mutated;
		}

		throw new \RuntimeException( "Lock ottimistico fallito sul dossier del progetto {$project_id} dopo " . self::MAX_RETRIES . ' tentativi.' );
	}

	/**
	 * Aggiorna la voce di outline di un capitolo (stato, sinossi, word count).
	 *
	 * @param array<string, mixed> $fields Campi da sovrascrivere nella voce.
	 */
	public function update_outline_entry( int $project_id, int $chapter_id, array $fields ): array {
		return $this->update(
			$project_id,
			static function ( array $dossier ) use ( $chapter_id, $fields ): array {
				foreach ( $dossier['outline'] as $i => $entry ) {
					if ( (int) $entry['chapter_id'] === $chapter_id ) {
						$dossier['outline'][ $i ] = array_merge( $entry, $fields );
						return $dossier;
					}
				}
				// Voce nuova: capitolo aggiunto in corsa.
				$fields['chapter_id'] = $chapter_id;
				$dossier['outline'][] = $fields;
				return $dossier;
			}
		);
	}

	/**
	 * Registra la sinossi di un capitolo completato e gli aggiornamenti di continuità.
	 *
	 * @param array<string, mixed> $continuity Chiavi opzionali: terminology, concepts_covered, promises, style_decisions.
	 */
	public function record_synopsis( int $project_id, int $chapter_id, string $synopsis, array $continuity = array() ): array {
		return $this->update(
			$project_id,
			static function ( array $dossier ) use ( $chapter_id, $synopsis, $continuity ): array {
				foreach ( $dossier['outline'] as $i => $entry ) {
					if ( (int) $entry['chapter_id'] === $chapter_id ) {
						$dossier['outline'][ $i ]['synopsis'] = $synopsis;
						break;
					}
				}

				foreach ( array( 'terminology', 'concepts_covered', 'promises', 'style_decisions' ) as $key ) {
					if ( ! empty( $continuity[ $key ] ) ) {
						$dossier['continuity'][ $key ] = array_merge(
							$dossier['continuity'][ $key ] ?? array(),
							$continuity[ $key ]
						);
					}
				}

				return $dossier;
			}
		);
	}
}

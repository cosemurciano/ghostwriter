<?php
declare(strict_types=1);

namespace Ghostwriter\Domain;

use Ghostwriter\Repository\ProjectRepository;

/**
 * Registro fonti del progetto: metadati, licenze, citazioni.
 *
 * La bibliografia si genera dal registry, mai dalla memoria del modello.
 * Il registry vive nella config di progetto (sources.registry).
 */
final class SourceRegistry {

	public function __construct( private ProjectRepository $projects ) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $project_id ): array {
		$config = $this->projects->get_config( $project_id );
		return $config['sources']['registry'] ?? array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $project_id, string $source_id ): ?array {
		foreach ( $this->all( $project_id ) as $source ) {
			if ( ( $source['source_id'] ?? '' ) === $source_id ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Registra o aggiorna una fonte (chiave: source_id). La config risultante
	 * viene rivalidata contro lo schema in salvataggio.
	 *
	 * @param array<string, mixed> $source Fonte conforme a project-config.schema.json (sources.registry.items).
	 */
	public function register( int $project_id, array $source ): void {
		if ( empty( $source['source_id'] ) ) {
			throw new \InvalidArgumentException( 'source_id obbligatorio.' );
		}

		$config   = $this->projects->get_config( $project_id );
		$registry = $config['sources']['registry'] ?? array();

		$replaced = false;
		foreach ( $registry as $i => $existing ) {
			if ( ( $existing['source_id'] ?? '' ) === $source['source_id'] ) {
				$registry[ $i ] = array_merge( $existing, $source );
				$replaced       = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$registry[] = $source;
		}

		$config['sources']['registry'] = $registry;
		$this->projects->save_config( $project_id, $config );
	}

	/**
	 * Fonti con obbligo di attribuzione (per il preflight: devono comparire in bibliografia).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function requiring_attribution( int $project_id ): array {
		return array_values(
			array_filter(
				$this->all( $project_id ),
				static fn( array $s ): bool => ! empty( $s['attribution_required'] )
			)
		);
	}

	/**
	 * Bibliografia: elenco citazioni formattate, generate dai metadati.
	 *
	 * @return string[]
	 */
	public function bibliography( int $project_id ): array {
		$entries = array();
		foreach ( $this->all( $project_id ) as $source ) {
			$entries[] = ! empty( $source['citation'] )
				? (string) $source['citation']
				: self::format_citation( $source );
		}
		sort( $entries );
		return $entries;
	}

	/**
	 * Citazione di fallback dai metadati (autori, titolo, url, licenza).
	 *
	 * @param array<string, mixed> $source Fonte dal registry.
	 */
	public static function format_citation( array $source ): string {
		$parts = array();

		if ( ! empty( $source['authors'] ) ) {
			$parts[] = implode( ', ', (array) $source['authors'] );
		}
		$parts[] = '*' . ( $source['title'] ?? '' ) . '*';
		if ( ! empty( $source['url'] ) ) {
			$parts[] = $source['url'];
		}
		if ( ! empty( $source['license'] ) && ! in_array( $source['license'], array( 'proprietaria' ), true ) ) {
			$parts[] = 'Licenza: ' . $source['license'];
		}

		return implode( '. ', array_filter( $parts ) ) . '.';
	}
}

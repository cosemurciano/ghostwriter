<?php
declare(strict_types=1);

namespace Ghostwriter\Repository;

/**
 * Tabella gw_rag_chunks: i frammenti indicizzati del vector store locale
 * (fonti ingerite + capitoli completati).
 */
class RagChunkRepository {

	/**
	 * @param string[] $chunks Frammenti di testo.
	 */
	public function insert_chunks( int $project_id, ?string $source_id, ?int $chapter_id, array $chunks ): void {
		global $wpdb;

		foreach ( $chunks as $i => $chunk ) {
			$wpdb->insert(
				$wpdb->prefix . 'gw_rag_chunks',
				array(
					'project_id' => $project_id,
					'source_id'  => $source_id,
					'chapter_id' => $chapter_id,
					'position'   => $i,
					'chunk'      => $chunk,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Reingestione idempotente: prima si azzera la fonte (o il capitolo).
	 */
	public function delete_for_source( int $project_id, string $source_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'gw_rag_chunks',
			array(
				'project_id' => $project_id,
				'source_id'  => $source_id,
			),
			array( '%d', '%s' )
		);
	}

	public function delete_for_chapter( int $project_id, int $chapter_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'gw_rag_chunks',
			array(
				'project_id' => $project_id,
				'chapter_id' => $chapter_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Tutti i frammenti del progetto (lo scoring avviene in memoria:
	 * dimensioni da libro singolo, non da corpus).
	 *
	 * @return array<int, array{chunk: string, source_id: string|null, chapter_id: int|null}>
	 */
	public function all_for_project( int $project_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chunk, source_id, chapter_id FROM {$wpdb->prefix}gw_rag_chunks WHERE project_id = %d",
				$project_id
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'chunk'      => (string) $row['chunk'],
				'source_id'  => $row['source_id'] ?: null,
				'chapter_id' => $row['chapter_id'] ? (int) $row['chapter_id'] : null,
			),
			$rows ?: array()
		);
	}
}

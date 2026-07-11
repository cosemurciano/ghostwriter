<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

use Ghostwriter\Domain\SourceRegistry;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Assembla il BookData da WordPress (CPT, meta, Media Library): l'unico
 * punto in cui il rendering tocca WP. Gli exporter restano puri e testabili.
 */
final class BookAssembler {

	public function __construct(
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private SourceRegistry $sources
	) {
	}

	/**
	 * @throws \RuntimeException Se il progetto non esiste o non ha capitoli con contenuto.
	 */
	public function assemble( int $project_id ): BookData {
		if ( ! $this->projects->exists( $project_id ) ) {
			throw new \RuntimeException( "Progetto {$project_id} inesistente." );
		}

		$config  = $this->projects->get_config( $project_id );
		$project = get_post( $project_id );

		$chapters = array();
		$images   = array();

		foreach ( $this->ordered_chapters( $project_id ) as [$chapter_id, $depth] ) {
			$content = $this->chapters->get_content( $chapter_id );
			if ( null === $content ) {
				continue; // Capitolo non ancora generato.
			}

			$chapters[] = array(
				'id'      => $chapter_id,
				'title'   => (string) get_the_title( $chapter_id ),
				'depth'   => $depth,
				'content' => $content,
			);

			$this->collect_images( $content['blocks'] ?? array(), $images );
		}

		if ( empty( $chapters ) ) {
			throw new \RuntimeException( "Il progetto {$project_id} non ha capitoli con contenuto: nulla da esportare." );
		}

		$author = '';
		if ( $project && $project->post_author ) {
			$author = (string) get_the_author_meta( 'display_name', (int) $project->post_author );
		}

		return new BookData(
			title: $project ? (string) $project->post_title : '',
			subtitle: null,
			author: $author,
			language: (string) ( $config['language'] ?? 'it' ),
			trim_width_mm: (float) ( $config['format']['trim_width_mm'] ?? 150 ),
			trim_height_mm: (float) ( $config['format']['trim_height_mm'] ?? 230 ),
			chapters: $chapters,
			images: $images,
			bibliography: $this->sources->bibliography( $project_id ),
			year: (string) gmdate( 'Y' ),
			identifier: $this->book_identifier( $project_id ),
			cover_path: $this->cover_path( $config )
		);
	}

	/**
	 * Capitoli in ordine di lettura con profondità (albero post_parent
	 * visitato depth-first, fratelli per menu_order).
	 *
	 * @return array<int, array{0: int, 1: int}> Coppie [chapter_id, depth].
	 */
	private function ordered_chapters( int $project_id ): array {
		$ids = $this->projects->get_chapter_ids( $project_id );

		$by_parent = array();
		foreach ( $ids as $id ) {
			$parent                = (int) get_post_field( 'post_parent', $id );
			$by_parent[ $parent ][] = $id;
		}

		$ordered = array();
		$visit   = function ( int $parent, int $depth ) use ( &$visit, &$ordered, $by_parent ): void {
			foreach ( $by_parent[ $parent ] ?? array() as $id ) {
				$ordered[] = array( $id, $depth );
				$visit( $id, $depth + 1 );
			}
		};
		$visit( 0, 0 );

		// I capitoli con parent fuori progetto non devono sparire.
		$seen = array_column( $ordered, 0 );
		foreach ( array_diff( $ids, $seen ) as $orphan ) {
			$ordered[] = array( $orphan, 0 );
		}

		return $ordered;
	}

	/**
	 * Raccoglie i path locali delle figure risolte (attachment_id → file).
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocchi (anche annidati).
	 * @param array<int, string>               $images Mappa accumulata.
	 */
	private function collect_images( array $blocks, array &$images ): void {
		foreach ( $blocks as $block ) {
			if ( 'figura' === ( $block['type'] ?? '' ) ) {
				$attachment_id = (int) ( $block['props']['attachment_id'] ?? 0 );
				if ( $attachment_id > 0 && ! isset( $images[ $attachment_id ] ) ) {
					$path = get_attached_file( $attachment_id );
					if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
						$images[ $attachment_id ] = $path;
					}
				}
			}
			if ( ! empty( $block['props']['blocks'] ) && is_array( $block['props']['blocks'] ) ) {
				$this->collect_images( $block['props']['blocks'], $images );
			}
		}
	}

	/**
	 * Path locale della copertina composta (se la pipeline copertina è arrivata lì).
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function cover_path( array $config ): ?string {
		$attachment_id = (int) ( $config['cover']['composed_attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$path = get_attached_file( $attachment_id );
		return is_string( $path ) && file_exists( $path ) ? $path : null;
	}

	/**
	 * Identificatore stabile del libro (per il dc:identifier ePub).
	 */
	private function book_identifier( int $project_id ): string {
		$uuid = (string) get_post_meta( $project_id, '_gw_book_uuid', true );
		if ( '' === $uuid ) {
			$uuid = 'urn:uuid:' . wp_generate_uuid4();
			update_post_meta( $project_id, '_gw_book_uuid', $uuid );
		}
		return $uuid;
	}
}

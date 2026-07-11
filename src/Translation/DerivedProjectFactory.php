<?php
declare(strict_types=1);

namespace Ghostwriter\Translation;

use Ghostwriter\Core\PostTypes;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Crea un progetto di traduzione: nuovo gw_project con derived_from e
 * lingua target, gerarchia capitoli clonata 1:1 (il mapping col sorgente
 * vive nel meta _gw_source_chapter_id; i blocchi manterranno gli stessi id,
 * che sono la chiave di traduzione).
 *
 * Il contenuto NON viene copiato: TranslateChapterJob legge il sorgente e
 * scrive il tradotto. Anche il progetto derivato ha il proprio dossier,
 * nella lingua target.
 */
final class DerivedProjectFactory {

	public const META_SOURCE_CHAPTER = '_gw_source_chapter_id';

	public function __construct(
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private Dossier $dossier,
		private StateMachine $states
	) {
	}

	/**
	 * @return int L'ID del progetto derivato.
	 * @throws \RuntimeException Se il sorgente non è traducibile.
	 */
	public function derive( int $source_project_id, string $target_language ): int {
		if ( ! $this->projects->exists( $source_project_id ) ) {
			throw new \RuntimeException( "Progetto sorgente {$source_project_id} inesistente." );
		}

		$source_config = $this->projects->get_config( $source_project_id );

		if ( ! empty( $source_config['derived_from'] ) ) {
			throw new \RuntimeException( 'Non si deriva da una traduzione: usare il progetto originale.' );
		}
		if ( strtolower( $target_language ) === strtolower( (string) ( $source_config['language'] ?? '' ) ) ) {
			throw new \RuntimeException( "Il progetto sorgente è già in \"{$target_language}\"." );
		}

		$source_chapter_ids = $this->projects->get_chapter_ids( $source_project_id );
		$translatable       = array_values(
			array_filter(
				$source_chapter_ids,
				fn( int $id ): bool => null !== $this->chapters->get_content( $id )
			)
		);
		if ( empty( $translatable ) ) {
			throw new \RuntimeException( 'Il progetto sorgente non ha capitoli con contenuto: nulla da tradurre.' );
		}

		// Config derivata: lingua target, derived_from, stesso resto
		// (formato, profilo, skills con fase translation, provider).
		$config                   = $source_config;
		$config['language']       = $target_language;
		$config['derived_from']   = $source_project_id;
		// L'artwork di copertina è senza testo: riutilizzabile; la
		// composizione tipografica si rifà da sola col titolo tradotto.

		$title      = get_the_title( $source_project_id ) . ' [' . strtoupper( $target_language ) . ']';
		$project_id = $this->projects->create( $title, $config );

		// Clona la gerarchia 1:1 (post_parent rimappato, menu_order conservato).
		$id_map = array();
		foreach ( $source_chapter_ids as $source_chapter_id ) {
			$source_post = get_post( $source_chapter_id );
			if ( null === $source_post || PostTypes::CHAPTER !== $source_post->post_type ) {
				continue;
			}

			$derived_id = $this->chapters->create(
				$project_id,
				(string) $source_post->post_title,
				$this->chapters->get_brief( $source_chapter_id ),
				$id_map[ (int) $source_post->post_parent ] ?? 0,
				(int) $source_post->menu_order
			);
			update_post_meta( $derived_id, self::META_SOURCE_CHAPTER, $source_chapter_id );

			$id_map[ $source_chapter_id ] = $derived_id;
		}

		$this->initialize_dossier( $project_id, $config, $source_project_id, $id_map, $translatable );

		return $project_id;
	}

	public function source_chapter_id( int $derived_chapter_id ): int {
		return (int) get_post_meta( $derived_chapter_id, self::META_SOURCE_CHAPTER, true );
	}

	/**
	 * Dossier del derivato: outline che rispecchia il sorgente (titoli da
	 * tradurre), capitoli senza contenuto sorgente marcati complete (non
	 * verranno tradotti).
	 *
	 * @param array<string, mixed> $config       Config derivata.
	 * @param array<int, int>      $id_map       source_chapter_id → derived_chapter_id.
	 * @param int[]                $translatable Capitoli sorgente con contenuto.
	 */
	private function initialize_dossier( int $project_id, array $config, int $source_project_id, array $id_map, array $translatable ): void {
		$this->dossier->initialize( $project_id, $config );

		$source_dossier = $this->projects->get_dossier( $source_project_id ) ?? array();
		$source_outline = array();
		foreach ( (array) ( $source_dossier['outline'] ?? array() ) as $entry ) {
			$source_outline[ (int) $entry['chapter_id'] ] = $entry;
		}

		$this->dossier->update(
			$project_id,
			function ( array $dossier ) use ( $id_map, $source_outline, $translatable ): array {
				$order = 0;
				foreach ( $id_map as $source_id => $derived_id ) {
					$source_entry         = $source_outline[ $source_id ] ?? array();
					$dossier['outline'][] = array(
						'chapter_id' => $derived_id,
						'parent_id'  => null,
						'order'      => $order++,
						'title'      => (string) get_the_title( $derived_id ),
						'brief'      => (string) ( $source_entry['brief'] ?? '' ),
						'status'     => in_array( $source_id, $translatable, true ) ? 'planned' : 'complete',
						'synopsis'   => null,
					);
				}
				return $dossier;
			}
		);
	}
}

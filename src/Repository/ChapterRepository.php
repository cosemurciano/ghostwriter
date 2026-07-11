<?php
declare(strict_types=1);

namespace Ghostwriter\Repository;

use Ghostwriter\Core\PostTypes;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Unico punto di I/O tra il CPT gw_chapter (+ meta) e il dominio.
 * Il contenuto strutturato (_gw_content) è validato a ogni scrittura.
 */
class ChapterRepository {

	public const META_CONTENT    = '_gw_content';
	public const META_PROJECT_ID = '_gw_project_id';
	public const META_BRIEF      = '_gw_brief';

	public function __construct( protected SchemaValidator $validator ) {
	}

	/**
	 * Crea un capitolo nella gerarchia del progetto. Restituisce l'ID del post.
	 */
	public function create( int $project_id, string $title, string $brief = '', int $parent_id = 0, int $order = 0 ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => PostTypes::CHAPTER,
				'post_title'  => $title,
				'post_status' => 'private',
				'post_parent' => $parent_id,
				'menu_order'  => $order,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Creazione capitolo fallita: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, self::META_PROJECT_ID, $project_id );
		if ( '' !== $brief ) {
			update_post_meta( $post_id, self::META_BRIEF, $brief );
		}

		return $post_id;
	}

	public function exists( int $chapter_id ): bool {
		return get_post_type( $chapter_id ) === PostTypes::CHAPTER;
	}

	public function get_project_id( int $chapter_id ): int {
		return (int) get_post_meta( $chapter_id, self::META_PROJECT_ID, true );
	}

	public function get_brief( int $chapter_id ): string {
		return (string) get_post_meta( $chapter_id, self::META_BRIEF, true );
	}

	/**
	 * Il formato intermedio del capitolo (contratto centrale del sistema).
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_content( int $chapter_id ): ?array {
		$content = get_post_meta( $chapter_id, self::META_CONTENT, true );
		return is_array( $content ) ? $content : null;
	}

	/**
	 * Salva il formato intermedio, validato contro chapter-content.schema.json.
	 * Il rendering dell'anteprima in post_content è compito del BlockRenderer
	 * (fase 2), agganciato all'azione gw_chapter_content_saved.
	 *
	 * @param array<string, mixed> $content Formato intermedio completo.
	 */
	public function save_content( int $chapter_id, array $content ): void {
		$this->validator->validate( $content, SchemaValidator::CHAPTER_CONTENT );
		update_post_meta( $chapter_id, self::META_CONTENT, $content );

		do_action( 'gw_chapter_content_saved', $chapter_id, $content );
	}

	/**
	 * Cerca un blocco per id, anche tra i blocchi annidati nei box.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_block( int $chapter_id, string $block_id ): ?array {
		$content = $this->get_content( $chapter_id );
		if ( null === $content ) {
			return null;
		}
		return self::find_block_in( $content['blocks'] ?? array(), $block_id );
	}

	/**
	 * Sostituisce un blocco (per id) nel formato intermedio e risalva.
	 * Da usare SOLO tramite BlockRevisionService, che archivia la versione precedente.
	 *
	 * @param array<string, mixed> $new_block Blocco sostitutivo (stesso id).
	 */
	public function replace_block( int $chapter_id, array $new_block ): void {
		$content = $this->get_content( $chapter_id );
		if ( null === $content ) {
			throw new \RuntimeException( "Contenuto assente per il capitolo {$chapter_id}." );
		}

		$replaced          = false;
		$content['blocks'] = self::replace_block_in( $content['blocks'] ?? array(), $new_block, $replaced );

		if ( ! $replaced ) {
			throw new \RuntimeException( "Blocco {$new_block['id']} non trovato nel capitolo {$chapter_id}." );
		}

		$this->save_content( $chapter_id, $content );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi (eventualmente annidati).
	 * @return array<string, mixed>|null
	 */
	private static function find_block_in( array $blocks, string $block_id ): ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['id'] ?? '' ) === $block_id ) {
				return $block;
			}
			if ( ! empty( $block['props']['blocks'] ) && is_array( $block['props']['blocks'] ) ) {
				$found = self::find_block_in( $block['props']['blocks'], $block_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks    Blocchi (eventualmente annidati).
	 * @param array<string, mixed>             $new_block Blocco sostitutivo.
	 * @param bool                             $replaced  Flag di uscita.
	 * @return array<int, array<string, mixed>>
	 */
	private static function replace_block_in( array $blocks, array $new_block, bool &$replaced ): array {
		foreach ( $blocks as $i => $block ) {
			if ( ( $block['id'] ?? '' ) === $new_block['id'] ) {
				$blocks[ $i ] = $new_block;
				$replaced     = true;
				return $blocks;
			}
			if ( ! empty( $block['props']['blocks'] ) && is_array( $block['props']['blocks'] ) ) {
				$blocks[ $i ]['props']['blocks'] = self::replace_block_in( $block['props']['blocks'], $new_block, $replaced );
				if ( $replaced ) {
					return $blocks;
				}
			}
		}
		return $blocks;
	}
}

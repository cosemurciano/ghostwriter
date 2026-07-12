<?php
declare(strict_types=1);

namespace Ghostwriter\Repository;

use Ghostwriter\Core\PostTypes;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Unico punto di I/O tra il CPT gw_project (+ meta) e il dominio.
 * Ogni scrittura di config e dossier è validata contro lo schema.
 */
class ProjectRepository {

	public const META_CONFIG  = '_gw_config';
	public const META_DOSSIER = '_gw_dossier';

	public function __construct( protected SchemaValidator $validator ) {
	}

	/**
	 * Crea un progetto con config validata. Restituisce l'ID del post.
	 *
	 * @param array<string, mixed> $config Config conforme a project-config.schema.json.
	 */
	public function create( string $title, array $config, int $author_id = 0 ): int {
		$this->validator->validate( $config, SchemaValidator::PROJECT_CONFIG );

		$post_id = wp_insert_post(
			array(
				'post_type'   => PostTypes::PROJECT,
				'post_title'  => $title,
				'post_status' => 'private',
				'post_author' => $author_id ?: get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Creazione progetto fallita: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, self::META_CONFIG, $config );

		return $post_id;
	}

	public function exists( int $project_id ): bool {
		return get_post_type( $project_id ) === PostTypes::PROJECT;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_config( int $project_id ): array {
		$config = get_post_meta( $project_id, self::META_CONFIG, true );
		if ( ! is_array( $config ) ) {
			throw new \RuntimeException( "Config assente per il progetto {$project_id}." );
		}
		return $config;
	}

	/**
	 * @param array<string, mixed> $config Config completa (validata in scrittura).
	 */
	public function save_config( int $project_id, array $config ): void {
		$this->validator->validate( $config, SchemaValidator::PROJECT_CONFIG );
		update_post_meta( $project_id, self::META_CONFIG, $config );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_dossier( int $project_id ): ?array {
		$dossier = get_post_meta( $project_id, self::META_DOSSIER, true );
		return is_array( $dossier ) ? $dossier : null;
	}

	/**
	 * @param array<string, mixed> $dossier Dossier completo (validato in scrittura).
	 */
	public function save_dossier( int $project_id, array $dossier ): void {
		$this->validator->validate( $dossier, SchemaValidator::DOSSIER );
		update_post_meta( $project_id, self::META_DOSSIER, $dossier );
	}

	/**
	 * True se il progetto è una traduzione (derivato da un altro progetto).
	 */
	public function is_translation( int $project_id ): bool {
		$config = $this->get_config( $project_id );
		return ! empty( $config['derived_from'] );
	}

	/**
	 * True se il libro è scritto interamente a mano (nessuna chiamata AI).
	 */
	public function is_manual( int $project_id ): bool {
		try {
			$config = $this->get_config( $project_id );
		} catch ( \Throwable ) {
			return false; // Progetto assente o config corrotta: mai manuale.
		}
		return ! empty( $config['ai']['manual'] );
	}

	/**
	 * ID dei capitoli del progetto, ordinati per gerarchia e menu_order.
	 *
	 * @return int[]
	 */
	public function get_chapter_ids( int $project_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::CHAPTER,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'meta_key'       => ChapterRepository::META_PROJECT_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $project_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		return array_map( 'intval', $ids );
	}
}

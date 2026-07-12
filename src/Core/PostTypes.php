<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

/**
 * Registrazione dei custom post type gw_project e gw_chapter.
 *
 * Entrambi non pubblici: l'interazione passa dalle pagine admin del plugin
 * e dalla REST API ghostwriter/v1, mai dal front-end o dall'editor standard.
 */
final class PostTypes {

	public const PROJECT = 'gw_project';
	public const CHAPTER = 'gw_chapter';

	public static function register(): void {
		register_post_type(
			self::PROJECT,
			array(
				'labels'          => array(
					'name'          => __( 'Progetti Ghostwriter', 'ghostwriter' ),
					'singular_name' => __( 'Progetto', 'ghostwriter' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
				'capability_type' => array( 'gw_project', 'gw_projects' ),
				'map_meta_cap'    => true,
			)
		);

		register_post_type(
			self::CHAPTER,
			array(
				'labels'          => array(
					'name'          => __( 'Capitoli Ghostwriter', 'ghostwriter' ),
					'singular_name' => __( 'Capitolo', 'ghostwriter' ),
					'edit_item'     => __( 'Modifica capitolo', 'ghostwriter' ),
				),
				'public'          => false,
				// Editor classico di WordPress (titolo, contenuto, media): il
				// salvataggio riconverte l'HTML nel formato intermedio a blocchi.
				// show_in_rest=false → niente Gutenberg: l'HTML del classico è
				// la proiezione affidabile per il round-trip.
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_admin_bar'  => false,
				'show_in_rest'       => false,
				// Gerarchia parte/capitolo/sottocapitolo via post_parent, ordine via menu_order.
				'hierarchical'    => true,
				'supports'        => array( 'title', 'editor', 'page-attributes' ),
				'capability_type' => array( 'gw_chapter', 'gw_chapters' ),
				'map_meta_cap'    => true,
			)
		);
	}
}

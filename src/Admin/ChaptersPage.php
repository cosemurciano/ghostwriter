<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Core\PostTypes;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ChapterRepository;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Gestione capitoli: elenco in stile Articoli con filtro per progetto,
 * viste per stato, ricerca e azioni riga (apri nel progetto, riprova).
 */
final class ChaptersPage extends \WP_List_Table {

	public function __construct(
		private ChapterRepository $chapters,
		private StateMachine $states
	) {
		parent::__construct(
			array(
				'singular' => 'gw_chapter',
				'plural'   => 'gw_chapters',
				'ajax'     => false,
			)
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="gw-notice" class="notice"></div>';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Capitoli', 'ghostwriter' ) . '</h1><hr class="wp-header-end"/>';

		$this->prepare_items();
		$this->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( Menu::SLUG_CHAPTERS ) . '"/>';
		$this->search_box( __( 'Cerca capitoli', 'ghostwriter' ), 'gw-chapters' );
		$this->display();
		echo '</form></div>';
	}

	public function get_columns(): array {
		return array(
			'title'   => __( 'Titolo', 'ghostwriter' ),
			'project' => __( 'Progetto', 'ghostwriter' ),
			'state'   => __( 'Stato', 'ghostwriter' ),
			'words'   => __( 'Parole', 'ghostwriter' ),
			'version' => __( 'Blocchi', 'ghostwriter' ),
			'date'    => __( 'Aggiornato', 'ghostwriter' ),
		);
	}

	protected function get_views(): array {
		$current = sanitize_key( $_GET['gw_state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$base    = admin_url( 'admin.php?page=' . Menu::SLUG_CHAPTERS );
		$project = (int) ( $_GET['gw_project'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $project > 0 ) {
			$base .= '&gw_project=' . $project;
		}

		$views = array(
			'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'Tutti', 'ghostwriter' ) . '</a>',
		);
		foreach ( array( 'planned', 'drafting', 'in_review', 'images_pending', 'complete', 'failed' ) as $state ) {
			$views[ $state ] = '<a href="' . esc_url( $base . '&gw_state=' . $state ) . '"' . ( $current === $state ? ' class="current"' : '' ) . '>' . esc_html( $state ) . '</a>';
		}
		return $views;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$selected = (int) ( $_GET['gw_project'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		$projects = get_posts(
			array(
				'post_type'      => PostTypes::PROJECT,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		echo '<div class="alignleft actions"><select name="gw_project"><option value="">' . esc_html__( 'Tutti i progetti', 'ghostwriter' ) . '</option>';
		foreach ( $projects as $project ) {
			echo '<option value="' . (int) $project->ID . '"' . selected( $selected, (int) $project->ID, false ) . '>' . esc_html( $project->post_title ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filtra', 'ghostwriter' ), '', 'filter_action', false );
		echo '</div>';
	}

	public function prepare_items(): void {
		$per_page = 20;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$project  = (int) ( $_GET['gw_project'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$state    = sanitize_key( $_GET['gw_state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'post_type'      => PostTypes::CHAPTER,
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => array( 'post_parent' => 'ASC', 'menu_order' => 'ASC', 'ID' => 'ASC' ),
		);

		$meta_query = array();
		if ( $project > 0 ) {
			$meta_query[] = array(
				'key'   => ChapterRepository::META_PROJECT_ID,
				'value' => $project,
			);
		}
		if ( '' !== $state ) {
			if ( 'planned' === $state ) {
				// planned = stato esplicito O meta assente (mai transitato).
				$meta_query[] = array(
					'relation' => 'OR',
					array( 'key' => StateMachine::META_STATE, 'value' => 'planned' ),
					array( 'key' => StateMachine::META_STATE, 'compare' => 'NOT EXISTS' ),
				);
			} else {
				$meta_query[] = array(
					'key'   => StateMachine::META_STATE,
					'value' => $state,
				);
			}
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$query       = new \WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
	}

	/**
	 * @param \WP_Post $item Capitolo.
	 */
	public function column_title( $item ): string {
		$chapter_id = (int) $item->ID;
		$project_id = $this->chapters->get_project_id( $chapter_id );
		$project_url = admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . $project_id );
		$state       = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );

		$actions = array(
			'open' => '<a href="' . esc_url( $project_url ) . '">' . esc_html__( 'Apri nel progetto', 'ghostwriter' ) . '</a>',
			'id'   => '<span class="gw-muted">ID ' . $chapter_id . '</span>',
		);
		if ( 'failed' === $state ) {
			$actions['retry'] = '<a href="#" data-gw-action="POST /chapters/' . $chapter_id . '/retry" data-gw-confirm>' . esc_html__( 'Riprova', 'ghostwriter' ) . '</a>';
		}

		$depth  = 0;
		$parent = (int) $item->post_parent;
		while ( $parent > 0 && $depth < 3 ) {
			++$depth;
			$parent = (int) get_post_field( 'post_parent', $parent );
		}

		return '<strong>' . str_repeat( '— ', $depth ) . '<a class="row-title" href="' . esc_url( $project_url ) . '">'
			. esc_html( $item->post_title ?: __( '(senza titolo)', 'ghostwriter' ) ) . '</a></strong>'
			. $this->row_actions( $actions );
	}

	/**
	 * @param \WP_Post $item Capitolo.
	 */
	protected function column_default( $item, $column_name ): string {
		$chapter_id = (int) $item->ID;

		switch ( $column_name ) {
			case 'project':
				$project_id = $this->chapters->get_project_id( $chapter_id );
				if ( 0 === $project_id ) {
					return '—';
				}
				return '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . $project_id ) ) . '">'
					. esc_html( get_the_title( $project_id ) ) . '</a>';
			case 'state':
				$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
				return '<span class="gw-state gw-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
			case 'words':
				$content = $this->chapters->get_content( $chapter_id );
				$words   = (int) ( $content['meta']['word_count'] ?? 0 );
				return $words > 0 ? esc_html( number_format_i18n( $words ) ) : '—';
			case 'version':
				$content = $this->chapters->get_content( $chapter_id );
				return null === $content ? '—' : (string) count( (array) ( $content['blocks'] ?? array() ) );
			case 'date':
				return esc_html( get_the_modified_date( '', $item ) ?: '' );
		}
		return '';
	}

	public function no_items(): void {
		esc_html_e( 'Nessun capitolo: i capitoli nascono approvando l\'indice di un progetto.', 'ghostwriter' );
	}
}

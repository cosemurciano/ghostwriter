<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Core\PostTypes;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ProjectRepository;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Elenco progetti in stile Articoli: colonne, azioni riga, viste per tipo,
 * ricerca e paginazione native.
 */
final class ProjectsListTable extends \WP_List_Table {

	public function __construct(
		private ProjectRepository $projects,
		private StateMachine $states,
		private UsageMeter $meter
	) {
		parent::__construct(
			array(
				'singular' => 'gw_project',
				'plural'   => 'gw_projects',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'title'    => __( 'Titolo', 'ghostwriter' ),
			'type'     => __( 'Tipo', 'ghostwriter' ),
			'state'    => __( 'Stato', 'ghostwriter' ),
			'chapters' => __( 'Capitoli', 'ghostwriter' ),
			'cost'     => __( 'Costo stimato', 'ghostwriter' ),
			'date'     => __( 'Data', 'ghostwriter' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'date', true ),
		);
	}

	protected function get_views(): array {
		$counts  = $this->type_counts();
		$current = sanitize_key( $_GET['gw_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$base    = admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS );

		$link = static function ( string $url, string $label, int $count, bool $active ): string {
			return '<a href="' . esc_url( $url ) . '"' . ( $active ? ' class="current"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . $count . ')</span></a>';
		};

		return array(
			'all'         => $link( $base, __( 'Tutti', 'ghostwriter' ), $counts['all'], '' === $current ),
			'book'        => $link( $base . '&gw_type=book', __( 'Libri', 'ghostwriter' ), $counts['book'], 'book' === $current ),
			'translation' => $link( $base . '&gw_type=translation', __( 'Traduzioni', 'ghostwriter' ), $counts['translation'], 'translation' === $current ),
		);
	}

	public function prepare_items(): void {
		$per_page = 20;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$orderby  = 'title' === ( $_GET['orderby'] ?? '' ) ? 'title' : 'date'; // phpcs:ignore WordPress.Security.NonceVerification
		$order    = 'asc' === strtolower( (string) ( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		$query = new \WP_Query(
			array(
				'post_type'      => PostTypes::PROJECT,
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				's'              => $search,
				'orderby'        => $orderby,
				'order'          => $order,
			)
		);

		$type_filter = sanitize_key( $_GET['gw_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$items       = array();
		foreach ( $query->posts as $post ) {
			$is_translation = $this->safe_is_translation( (int) $post->ID );
			if ( 'book' === $type_filter && $is_translation ) {
				continue;
			}
			if ( 'translation' === $type_filter && ! $is_translation ) {
				continue;
			}
			$items[] = array(
				'post'           => $post,
				'is_translation' => $is_translation,
			);
		}
		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * @param array{post: \WP_Post, is_translation: bool} $item Riga.
	 */
	public function column_title( $item ): string {
		$post = $item['post'];
		$url  = admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . (int) $post->ID );

		$actions = array(
			'open'     => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Apri', 'ghostwriter' ) . '</a>',
			'chapters' => '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_CHAPTERS . '&gw_project=' . (int) $post->ID ) ) . '">' . esc_html__( 'Capitoli', 'ghostwriter' ) . '</a>',
			'id'       => '<span class="gw-muted">ID ' . (int) $post->ID . '</span>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $post->post_title ?: __( '(senza titolo)', 'ghostwriter' ) ) . '</a></strong>'
			. $this->row_actions( $actions );
	}

	/**
	 * @param array{post: \WP_Post, is_translation: bool} $item Riga.
	 */
	protected function column_default( $item, $column_name ): string {
		$post = $item['post'];
		$id   = (int) $post->ID;
		$type = $item['is_translation'] ? StateMachine::TYPE_TRANSLATION : StateMachine::TYPE_PROJECT;

		switch ( $column_name ) {
			case 'type':
				return $item['is_translation']
					? '<span class="dashicons dashicons-translation" style="color:#8c8f94"></span> ' . esc_html__( 'Traduzione', 'ghostwriter' )
					: '<span class="dashicons dashicons-book-alt" style="color:#8c8f94"></span> ' . esc_html__( 'Libro', 'ghostwriter' );
			case 'state':
				$state = $this->states->state_of( $id, $type );
				return '<span class="gw-state gw-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
			case 'chapters':
				return (string) count( $this->projects->get_chapter_ids( $id ) );
			case 'cost':
				try {
					$report = $this->meter->report( $id );
				} catch ( \Throwable ) {
					return '—';
				}
				$cost = (float) ( $report['totals']['cost_estimate'] ?? 0 );
				$pct  = $report['cost_pct'];
				return esc_html( number_format_i18n( $cost, 2 ) ) . ' €'
					. ( null !== $pct ? ' <span class="gw-muted">(' . (int) $pct . '%)</span>' : '' );
			case 'date':
				return esc_html( get_the_date( '', $post ) );
		}
		return '';
	}

	public function no_items(): void {
		esc_html_e( 'Nessun progetto. Creane uno con "Aggiungi progetto".', 'ghostwriter' );
	}

	/**
	 * @return array{all: int, book: int, translation: int}
	 */
	private function type_counts(): array {
		$ids    = get_posts(
			array(
				'post_type'      => PostTypes::PROJECT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$counts = array( 'all' => count( $ids ), 'book' => 0, 'translation' => 0 );
		foreach ( $ids as $id ) {
			++$counts[ $this->safe_is_translation( (int) $id ) ? 'translation' : 'book' ];
		}
		return $counts;
	}

	private function safe_is_translation( int $project_id ): bool {
		try {
			return $this->projects->is_translation( $project_id );
		} catch ( \Throwable ) {
			return false;
		}
	}
}

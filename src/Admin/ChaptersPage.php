<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ChapterRepository;

/**
 * Gestione capitoli: pagina sottile che istanzia la list table SOLO al
 * render (WP_List_Table non è costruibile prima degli include admin).
 */
final class ChaptersPage {

	public function __construct(
		private ChapterRepository $chapters,
		private StateMachine $states
	) {
	}

	public function render(): void {
		$table = new ChaptersListTable( $this->chapters, $this->states );

		echo '<div class="wrap"><div id="gw-notice" class="notice"></div>';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Capitoli', 'ghostwriter' ) . '</h1><hr class="wp-header-end"/>';

		$table->prepare_items();
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( Menu::SLUG_CHAPTERS ) . '"/>';
		$table->search_box( __( 'Cerca capitoli', 'ghostwriter' ), 'gw-chapters' );
		$table->display();
		echo '</form></div>';
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Jobs\ExportJob;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Rest\ProjectsController;

/**
 * Progetti: elenco in stile Articoli (WP_List_Table) e dettaglio a due
 * colonne con stepper di pipeline. Letture server-side, scritture via REST.
 */
final class ProjectsPage {

	public function __construct(
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private StateMachine $states,
		private UsageMeter $meter,
		private ThemeRegistry $themes,
		private LogRepository $log
	) {
	}

	public function render(): void {
		echo '<div class="wrap"><div id="gw-notice" class="notice"></div>';

		$project_id = isset( $_GET['project'] ) ? (int) $_GET['project'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $project_id > 0 && $this->projects->exists( $project_id ) ) {
			$this->render_detail( $project_id );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------ //
	// Elenco (stile Articoli)
	// ------------------------------------------------------------------ //

	private function render_list(): void {
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Progetti', 'ghostwriter' ) . '</h1> '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_NEW ) ) . '" class="page-title-action">' . esc_html__( 'Aggiungi progetto', 'ghostwriter' ) . '</a>'
			. '<hr class="wp-header-end"/>';

		$table = new ProjectsListTable( $this->projects, $this->states, $this->meter );
		$table->prepare_items();
		$table->views();

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( Menu::SLUG_PROJECTS ) . '"/>';
		$table->search_box( __( 'Cerca progetti', 'ghostwriter' ), 'gw-projects' );
		$table->display();
		echo '</form>';
	}

	// ------------------------------------------------------------------ //
	// Dettaglio
	// ------------------------------------------------------------------ //

	private function render_detail( int $project_id ): void {
		$is_translation = $this->safe_is_translation( $project_id );
		$type           = $is_translation ? StateMachine::TYPE_TRANSLATION : StateMachine::TYPE_PROJECT;
		$state          = $this->states->state_of( $project_id, $type );
		$config         = $this->projects->get_config( $project_id );
		$dossier        = $this->projects->get_dossier( $project_id ) ?? array();

		echo '<h1 class="wp-heading-inline">' . esc_html( get_the_title( $project_id ) ) . '</h1> '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS ) ) . '" class="page-title-action">' . esc_html__( 'Tutti i progetti', 'ghostwriter' ) . '</a> '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_CHAPTERS . '&gw_project=' . $project_id ) ) . '" class="page-title-action">' . esc_html__( 'Gestione capitoli', 'ghostwriter' ) . '</a>'
			. '<hr class="wp-header-end"/>';

		echo '<p class="gw-subtitle">'
			. ( $is_translation ? '<span class="dashicons dashicons-translation"></span> ' . esc_html__( 'Traduzione', 'ghostwriter' ) : '<span class="dashicons dashicons-book-alt"></span> ' . esc_html__( 'Libro', 'ghostwriter' ) )
			. ' · ' . esc_html( strtoupper( (string) ( $config['language'] ?? '' ) ) )
			. ' · ' . esc_html( (string) ( $config['ai']['provider'] ?? '' ) . ' / ' . (string) ( $config['ai']['model'] ?? '' ) )
			. ' · ' . esc_html( (string) ( $config['format']['trim_width_mm'] ?? '' ) . '×' . (string) ( $config['format']['trim_height_mm'] ?? '' ) . ' mm' )
			. '</p>';

		$this->render_stepper( $state, $is_translation );

		if ( 'paused_budget' === $state ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Budget superato: pipeline in pausa.', 'ghostwriter' ) . '</strong> '
				. esc_html__( 'Alza il budget nella config o riprendi dopo verifica.', 'ghostwriter' )
				. ' <button class="button button-small" data-gw-action="POST /projects/' . $project_id . '/budget/resume" data-gw-confirm>' . esc_html__( 'Riprendi', 'ghostwriter' ) . '</button></p></div>';
		}

		$this->render_pipeline_actions( $project_id, $state, $is_translation );

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';

		echo '<div id="post-body-content">';
		if ( $is_translation ) {
			$this->render_glossary_box( $project_id, $state, $dossier );
		} else {
			$this->render_outline_box( $project_id, $state, $dossier );
		}
		$this->render_chapters_box( $project_id, $dossier );
		if ( ! $is_translation ) {
			$this->render_sources_box( $project_id, $config );
		}
		echo '</div>';

		echo '<div id="postbox-container-1" class="postbox-container">';
		$this->render_cover_box( $project_id, $config );
		$this->render_export_box( $project_id, $config );
		$this->render_usage_box( $project_id );
		$this->render_log_box( $project_id );
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Stepper della pipeline: dove si trova il progetto e cosa manca.
	 */
	private function render_stepper( string $state, bool $is_translation ): void {
		$steps = $is_translation
			? array(
				__( 'Setup', 'ghostwriter' )      => array( 'setup' ),
				__( 'Glossario', 'ghostwriter' )  => array( 'glossary_proposed', 'glossary_approved' ),
				__( 'Traduzione', 'ghostwriter' ) => array( 'translating' ),
				__( 'Revisione', 'ghostwriter' )  => array( 'review' ),
				__( 'Export', 'ghostwriter' )     => array( 'ready_to_export', 'exported' ),
			)
			: array(
				__( 'Setup', 'ghostwriter' )       => array( 'setup', 'sources_ingesting' ),
				__( 'Indice', 'ghostwriter' )      => array( 'outline_proposed', 'outline_approved' ),
				__( 'Generazione', 'ghostwriter' ) => array( 'generating' ),
				__( 'Revisione', 'ghostwriter' )   => array( 'review' ),
				__( 'Copertina', 'ghostwriter' )   => array( 'cover_pending' ),
				__( 'Export', 'ghostwriter' )      => array( 'ready_to_export', 'exported' ),
			);

		$current = 0;
		$index   = 0;
		foreach ( $steps as $states ) {
			if ( in_array( $state, $states, true ) ) {
				$current = $index;
			}
			++$index;
		}
		if ( 'exported' === $state ) {
			$current = count( $steps ); // Tutto completato.
		}

		echo '<ol class="gw-stepper">';
		$index = 0;
		foreach ( $steps as $label => $states ) {
			$class = $index < $current ? 'done' : ( $index === $current && 'exported' !== $state ? 'current' : 'todo' );
			if ( 'paused_budget' === $state ) {
				$class = 'todo';
			}
			echo '<li class="' . esc_attr( $class ) . '"><span class="gw-step-dot"></span>' . esc_html( $label ) . '</li>';
			++$index;
		}
		echo '</ol>';
	}

	private function render_pipeline_actions( int $project_id, string $state, bool $is_translation ): void {
		$button = static function ( string $label, string $action, string $class = 'button button-primary', string $hint = '' ): void {
			echo '<button class="' . esc_attr( $class ) . '" data-gw-action="' . esc_attr( $action ) . '" data-gw-confirm>' . esc_html( $label ) . '</button>';
			if ( '' !== $hint ) {
				echo ' <span class="gw-muted">' . esc_html( $hint ) . '</span>';
			}
		};

		echo '<div class="gw-actionbar">';

		if ( $is_translation ) {
			switch ( $state ) {
				case 'setup':
					echo '<span class="spinner is-active"></span> ' . esc_html__( 'Proposta di glossario in coda…', 'ghostwriter' );
					break;
				case 'glossary_proposed':
					$button( __( 'Approva glossario e avvia la traduzione', 'ghostwriter' ), "POST /projects/{$project_id}/glossary/approve", 'button button-primary', __( 'Rivedi prima le voci qui sotto.', 'ghostwriter' ) );
					break;
				case 'translating':
					echo '<span class="spinner is-active"></span> ' . esc_html__( 'Traduzione capitolo per capitolo in corso…', 'ghostwriter' );
					break;
				case 'review':
					$button( __( 'Chiudi revisione: pronto per l\'export', 'ghostwriter' ), "POST /projects/{$project_id}/advance" );
					break;
			}
		} else {
			switch ( $state ) {
				case 'setup':
				case 'sources_ingesting':
					$button( __( 'Proponi indice (AI)', 'ghostwriter' ), "POST /projects/{$project_id}/outline/propose", 'button button-primary', __( 'Puoi prima registrare le fonti qui sotto.', 'ghostwriter' ) );
					break;
				case 'outline_proposed':
					$button( __( 'Approva indice e genera il libro', 'ghostwriter' ), "POST /projects/{$project_id}/outline/approve", 'button button-primary', __( 'L\'approvazione governa tutta la spesa a valle.', 'ghostwriter' ) );
					break;
				case 'generating':
					echo '<span class="spinner is-active"></span> ' . esc_html__( 'Generazione capitoli in corso: la pagina riflette lo stato della coda.', 'ghostwriter' );
					break;
				case 'review':
					$button( __( 'Chiudi revisione', 'ghostwriter' ), "POST /projects/{$project_id}/advance", 'button button-primary', __( 'Rivedi i capitoli, riscrivi i blocchi che non convincono, poi chiudi.', 'ghostwriter' ) );
					break;
				case 'cover_pending':
					echo esc_html__( 'Fase copertina: gestiscila dal riquadro Copertina.', 'ghostwriter' );
					break;
			}

			if ( in_array( $state, array( 'review', 'cover_pending', 'ready_to_export', 'exported' ), true ) ) {
				echo '<form class="gw-inline-form" data-gw-form="POST /projects/' . $project_id . '/derive" data-gw-transform="derive" data-gw-goto-project>'
					. '<input type="text" name="language" size="4" placeholder="en" required pattern="[A-Za-z]{2}.*" aria-label="' . esc_attr__( 'Lingua di destinazione', 'ghostwriter' ) . '"/>'
					. '<button class="button">' . esc_html__( 'Crea traduzione', 'ghostwriter' ) . '</button></form>';
			}
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------ //
	// Riquadri
	// ------------------------------------------------------------------ //

	private function box_open( string $title_html, string $extra_class = '' ): void {
		echo '<div class="postbox gw-postbox ' . esc_attr( $extra_class ) . '"><div class="postbox-header"><h2 class="hndle">' . $title_html . '</h2></div><div class="inside">'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function box_close(): void {
		echo '</div></div>';
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_outline_box( int $project_id, string $state, array $dossier ): void {
		$outline  = (array) ( $dossier['outline'] ?? array() );
		$editable = 'outline_proposed' === $state;

		$this->box_open( esc_html__( 'Indice', 'ghostwriter' ) . ( $editable ? ' <span class="gw-state gw-state-outline_proposed">' . esc_html__( 'da approvare', 'ghostwriter' ) . '</span>' : '' ) );

		if ( empty( $outline ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Ancora nessun indice: usa "Proponi indice (AI)" qui sopra.', 'ghostwriter' ) . '</p>';
			$this->box_close();
			return;
		}

		if ( $editable ) {
			echo '<form data-gw-form="PUT /projects/' . $project_id . '/outline" data-gw-transform="outline">';
			echo '<p class="gw-muted">' . esc_html__( 'Modifica titoli e brief prima di approvare: dopo, i capitoli vengono materializzati.', 'ghostwriter' ) . '</p>';
		}
		echo '<table class="widefat striped gw-clean-table"><tbody>';
		$n = 0;
		foreach ( $outline as $entry ) {
			$chapter_id = (int) ( $entry['chapter_id'] ?? 0 );
			++$n;
			if ( $editable ) {
				echo '<tr class="gw-outline-row" data-chapter-id="' . $chapter_id . '" data-status="' . esc_attr( (string) ( $entry['status'] ?? 'planned' ) ) . '">'
					. '<td class="gw-outline-n">' . $n . '</td><td>'
					. '<input type="text" name="title" value="' . esc_attr( (string) ( $entry['title'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Titolo del capitolo', 'ghostwriter' ) . '" />'
					. '<textarea name="brief" rows="2" placeholder="' . esc_attr__( 'Obiettivo del capitolo (2-3 frasi)', 'ghostwriter' ) . '">' . esc_textarea( (string) ( $entry['brief'] ?? '' ) ) . '</textarea>'
					. '</td></tr>';
			} else {
				echo '<tr><td class="gw-outline-n">' . $n . '</td><td><strong>' . esc_html( (string) ( $entry['title'] ?? '' ) ) . '</strong> ' . $this->state_badge( (string) ( $entry['status'] ?? '' ) )
					. '<br/><span class="gw-muted">' . esc_html( (string) ( $entry['brief'] ?? '' ) ) . '</span>'
					. ( ! empty( $entry['synopsis'] ) ? '<details class="gw-synopsis"><summary>' . esc_html__( 'Sinossi', 'ghostwriter' ) . '</summary>' . esc_html( (string) $entry['synopsis'] ) . '</details>' : '' )
					. '</td></tr>';
			}
		}
		echo '</tbody></table>';
		if ( $editable ) {
			echo '<p><button class="button button-primary">' . esc_html__( 'Salva modifiche all\'indice', 'ghostwriter' ) . '</button></p></form>';
		}
		$this->box_close();
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_glossary_box( int $project_id, string $state, array $dossier ): void {
		$glossary = (array) ( $dossier['glossary'] ?? array() );
		$editable = 'glossary_proposed' === $state;

		$this->box_open( esc_html__( 'Glossario di traduzione', 'ghostwriter' ) . ( $editable ? ' <span class="gw-state gw-state-glossary_proposed">' . esc_html__( 'da approvare', 'ghostwriter' ) . '</span>' : '' ) );

		if ( empty( $glossary ) && ! $editable ) {
			echo '<p class="gw-muted">' . esc_html__( 'Glossario non ancora proposto.', 'ghostwriter' ) . '</p>';
			$this->box_close();
			return;
		}

		if ( $editable ) {
			echo '<form data-gw-form="PUT /projects/' . $project_id . '/glossary" data-gw-transform="glossary">';
			echo '<p class="gw-muted">' . esc_html__( 'Il glossario è vincolante per tutta la traduzione: sistemalo ora, dal capitolo 1 all\'ultimo varrà questo.', 'ghostwriter' ) . '</p>';
		}
		echo '<table class="widefat striped gw-clean-table"><thead><tr><th>' . esc_html__( 'Termine sorgente', 'ghostwriter' ) . '</th><th>' . esc_html__( 'Resa', 'ghostwriter' ) . '</th><th>' . esc_html__( 'Nota', 'ghostwriter' ) . '</th></tr></thead><tbody class="gw-glossary-rows">';
		$rows = $editable && empty( $glossary ) ? array( array() ) : $glossary;
		foreach ( $rows as $entry ) {
			if ( $editable ) {
				echo '<tr class="gw-glossary-row">'
					. '<td><input type="text" name="source_term" value="' . esc_attr( (string) ( $entry['source_term'] ?? '' ) ) . '" /></td>'
					. '<td><input type="text" name="target_term" value="' . esc_attr( (string) ( $entry['target_term'] ?? '' ) ) . '" /></td>'
					. '<td><input type="text" name="note" value="' . esc_attr( (string) ( $entry['note'] ?? '' ) ) . '" /></td></tr>';
			} else {
				echo '<tr><td>' . esc_html( (string) ( $entry['source_term'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['target_term'] ?? '' ) ) . '</td><td class="gw-muted">' . esc_html( (string) ( $entry['note'] ?? '' ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
		if ( $editable ) {
			echo '<p><button type="button" class="button" data-gw-add-glossary-row>+ ' . esc_html__( 'Aggiungi voce', 'ghostwriter' ) . '</button> '
				. '<button class="button button-primary">' . esc_html__( 'Salva glossario', 'ghostwriter' ) . '</button></p></form>';
		}
		$this->box_close();
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_chapters_box( int $project_id, array $dossier ): void {
		$ids = $this->projects->get_chapter_ids( $project_id );

		$this->box_open(
			esc_html__( 'Capitoli', 'ghostwriter' )
			. ' <a class="gw-header-link" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_CHAPTERS . '&gw_project=' . $project_id ) ) . '">' . esc_html__( 'gestione capitoli →', 'ghostwriter' ) . '</a>'
		);

		if ( empty( $ids ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'I capitoli nascono con l\'approvazione dell\'indice.', 'ghostwriter' ) . '</p>';
			$this->box_close();
			return;
		}

		$words = array();
		foreach ( (array) ( $dossier['outline'] ?? array() ) as $entry ) {
			$words[ (int) ( $entry['chapter_id'] ?? 0 ) ] = $entry['word_count'] ?? null;
		}

		$done = 0;
		foreach ( $ids as $chapter_id ) {
			if ( 'complete' === $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
				++$done;
			}
		}
		$pct = count( $ids ) > 0 ? (int) round( 100 * $done / count( $ids ) ) : 0;
		echo '<div class="gw-progress"><span style="width:' . $pct . '%"></span></div>'
			. '<p class="gw-muted">' . esc_html( sprintf( /* translators: 1: completati, 2: totale */ __( '%1$d capitoli completati su %2$d', 'ghostwriter' ), $done, count( $ids ) ) ) . '</p>';

		echo '<table class="widefat striped gw-clean-table"><tbody>';
		foreach ( $ids as $chapter_id ) {
			$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
			echo '<tr><td><strong>' . esc_html( get_the_title( $chapter_id ) ) . '</strong></td>'
				. '<td>' . $this->state_badge( $state ) . '</td>'
				. '<td class="gw-muted">' . ( ! empty( $words[ $chapter_id ] ) ? esc_html( number_format_i18n( (int) $words[ $chapter_id ] ) ) . ' ' . esc_html__( 'parole', 'ghostwriter' ) : '' ) . '</td>'
				. '<td class="gw-row-actions">'
				. ( 'failed' === $state ? '<button class="button button-small" data-gw-action="POST /chapters/' . $chapter_id . '/retry" data-gw-confirm>' . esc_html__( 'Riprova', 'ghostwriter' ) . '</button> ' : '' )
				. '<button class="button button-small" data-gw-chapter-blocks="' . $chapter_id . '">' . esc_html__( 'Blocchi', 'ghostwriter' ) . '</button>'
				. '</td></tr>'
				. '<tr class="gw-blocks-row" data-chapter="' . $chapter_id . '" style="display:none"><td colspan="4"><div class="gw-blocks-target gw-muted">…</div></td></tr>';
		}
		echo '</tbody></table>';
		$this->box_close();
	}

	/**
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_sources_box( int $project_id, array $config ): void {
		$registry = (array) ( $config['sources']['registry'] ?? array() );

		$this->box_open( esc_html__( 'Fonti', 'ghostwriter' ) );

		if ( ! empty( $registry ) ) {
			echo '<table class="widefat striped gw-clean-table"><tbody>';
			foreach ( $registry as $source ) {
				echo '<tr><td><strong>' . esc_html( (string) ( $source['title'] ?? $source['source_id'] ?? '' ) ) . '</strong>'
					. '<br/><span class="gw-muted">' . esc_html( (string) ( $source['type'] ?? '' ) . ' · ' . (string) ( $source['license'] ?? '' ) ) . '</span></td>'
					. '<td>' . $this->state_badge( (string) ( $source['ingest_status'] ?? 'registrata' ) )
					. ( ! empty( $source['chunk_count'] ) ? ' <span class="gw-muted">' . (int) $source['chunk_count'] . ' ' . esc_html__( 'frammenti', 'ghostwriter' ) . '</span>' : '' )
					. '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="gw-muted">' . esc_html__( 'Le fonti alimentano il RAG del progetto: i capitoli citano con provenienza, mai a memoria.', 'ghostwriter' ) . '</p>';
		}

		echo '<form data-gw-form="POST /projects/' . $project_id . '/sources" data-gw-transform="addSource" class="gw-source-form">'
			. '<input type="text" name="title" placeholder="' . esc_attr__( 'Titolo della fonte', 'ghostwriter' ) . '" required />'
			. '<select name="type"><option value="url">URL</option><option value="pdf">PDF (path server)</option><option value="open_data">open data</option></select>'
			. '<input type="text" name="location" placeholder="https://… o /percorso/file.pdf" required />'
			. '<select name="license"><option value="CC-BY-4.0">CC-BY-4.0</option><option value="CC0">CC0</option><option value="pubblico dominio">' . esc_html__( 'pubblico dominio', 'ghostwriter' ) . '</option><option value="proprietaria">' . esc_html__( 'proprietaria', 'ghostwriter' ) . '</option></select>'
			. '<label class="gw-inline-label"><input type="checkbox" name="attribution_required" value="1" /> ' . esc_html__( 'attribuzione', 'ghostwriter' ) . '</label>'
			. '<button class="button">' . esc_html__( 'Registra e ingerisci', 'ghostwriter' ) . '</button>'
			. '</form>';
		$this->box_close();
	}

	/**
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_cover_box( int $project_id, array $config ): void {
		$cover_state = $this->states->state_of( $project_id, StateMachine::TYPE_COVER );
		$cover       = (array) ( $config['cover'] ?? array() );

		$this->box_open( esc_html__( 'Copertina', 'ghostwriter' ) . ' ' . $this->state_badge( $cover_state ) );

		$editable_brief = in_array( $cover_state, array( 'pending', 'brief_ready', 'artwork_ready' ), true );
		if ( $editable_brief ) {
			echo '<form data-gw-form="PUT /projects/' . $project_id . '/cover">'
				. '<textarea name="creative_brief" rows="3" class="large-text" placeholder="' . esc_attr__( 'Brief creativo (l\'artwork è sempre senza testo)', 'ghostwriter' ) . '">' . esc_textarea( (string) ( $cover['creative_brief'] ?? '' ) ) . '</textarea>'
				. '<p><select name="mode">'
				. '<option value="ai_generated"' . selected( ( $cover['mode'] ?? 'ai_generated' ), 'ai_generated', false ) . '>' . esc_html__( 'Artwork AI', 'ghostwriter' ) . '</option>'
				. '<option value="upload"' . selected( ( $cover['mode'] ?? '' ), 'upload', false ) . '>' . esc_html__( 'Artwork caricato', 'ghostwriter' ) . '</option>'
				. '</select> '
				. '<input type="number" name="front_artwork_attachment_id" value="' . esc_attr( (string) ( $cover['front_artwork_attachment_id'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'ID media', 'ghostwriter' ) . '" style="width:90px" /> '
				. '<button class="button">' . esc_html__( 'Salva', 'ghostwriter' ) . '</button></p></form>';
		} elseif ( ! empty( $cover['creative_brief'] ) ) {
			echo '<p class="gw-muted">' . esc_html( (string) $cover['creative_brief'] ) . '</p>';
		}

		echo '<div class="gw-cover-previews">';
		foreach ( array(
			'front_artwork_attachment_id' => __( 'Artwork', 'ghostwriter' ),
			'composed_attachment_id'      => __( 'Composizione', 'ghostwriter' ),
		) as $key => $label ) {
			if ( ! empty( $cover[ $key ] ) ) {
				$thumbnail = wp_get_attachment_image( (int) $cover[ $key ], array( 130, 195 ) );
				if ( $thumbnail ) {
					echo '<figure>' . $thumbnail . '<figcaption class="gw-muted">' . esc_html( $label ) . '</figcaption></figure>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
		}
		echo '</div>';

		echo '<p class="gw-actions">';
		if ( 'pending' === $cover_state ) {
			echo '<button class="button" data-gw-action="POST /projects/' . $project_id . '/cover/regenerate">' . esc_html__( 'Avvia pipeline copertina', 'ghostwriter' ) . '</button>';
		}
		if ( 'composed' === $cover_state ) {
			echo '<button class="button button-primary" data-gw-action="POST /projects/' . $project_id . '/cover/approve" data-gw-confirm>' . esc_html__( 'Approva', 'ghostwriter' ) . '</button> '
				. '<button class="button" data-gw-action="POST /projects/' . $project_id . '/cover/regenerate" data-gw-confirm>' . esc_html__( 'Rigenera', 'ghostwriter' ) . '</button>';
		}
		if ( 'approved' === $cover_state ) {
			echo '<span class="gw-muted">✓ ' . esc_html__( 'Approvata: entra in PDF ed ePub.', 'ghostwriter' ) . '</span>';
		}
		echo '</p>';
		$this->box_close();
	}

	/**
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_export_box( int $project_id, array $config ): void {
		$this->box_open( esc_html__( 'Export', 'ghostwriter' ) );

		$width  = (float) ( $config['format']['trim_width_mm'] ?? 0 );
		$height = (float) ( $config['format']['trim_height_mm'] ?? 0 );

		echo '<form data-gw-form="POST /projects/' . $project_id . '/export" data-gw-transform="exportBook">';
		echo '<select name="theme" class="widefat">';
		foreach ( $this->themes->all() as $key => $theme ) {
			$compatible = $theme->supports_format( $width, $height );
			echo '<option value="' . esc_attr( $key ) . '"' . ( $compatible ? '' : ' disabled' ) . '>'
				. esc_html( $theme->name() . ' ' . $theme->version() . ( $compatible ? '' : ' — ' . __( 'formato non supportato', 'ghostwriter' ) ) )
				. '</option>';
		}
		echo '</select>';
		echo '<p><select name="target"><option value="pdf">PDF</option><option value="epub">ePub</option></select> '
			. '<button type="button" class="button" data-gw-preflight="' . $project_id . '">' . esc_html__( 'Preflight', 'ghostwriter' ) . '</button> '
			. '<button class="button button-primary">' . esc_html__( 'Esporta', 'ghostwriter' ) . '</button></p>';
		echo '</form>';
		echo '<div class="gw-preflight-report" style="display:none"></div>';

		$exports = get_post_meta( $project_id, ExportJob::META_EXPORTS, true );
		$exports = is_array( $exports ) ? array_reverse( $exports ) : array();
		if ( ! empty( $exports ) ) {
			echo '<ul class="gw-exports">';
			foreach ( array_slice( $exports, 0, 6 ) as $export ) {
				$file = (string) ( $export['file'] ?? '' );
				$url  = wp_nonce_url( rest_url( ProjectsController::REST_NAMESPACE . "/projects/{$project_id}/exports/" . rawurlencode( $file ) ), 'wp_rest', '_wpnonce' );
				$icon = 'epub' === ( $export['target'] ?? '' ) ? 'dashicons-book' : 'dashicons-pdf';
				echo '<li><span class="dashicons ' . esc_attr( $icon ) . '"></span> <a href="' . esc_url( $url ) . '">' . esc_html( $file ) . '</a>'
					. '<br/><span class="gw-muted">' . esc_html( trim( size_format( (int) ( $export['size'] ?? 0 ) ) . ' · ' . (string) ( $export['created_at'] ?? '' ) ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		$this->box_close();
	}

	private function render_usage_box( int $project_id ): void {
		$report = $this->safe_usage( $project_id );
		$totals = (array) ( $report['totals'] ?? array() );
		$pct    = $report['cost_pct'] ?? null;

		$this->box_open( esc_html__( 'Costi', 'ghostwriter' ) );
		echo '<p class="gw-cost">' . esc_html( number_format_i18n( (float) ( $totals['cost_estimate'] ?? 0 ), 2 ) ) . ' €';
		if ( null !== $pct ) {
			echo ' <span class="gw-muted">/ ' . esc_html( number_format_i18n( (float) ( $report['budget']['max_cost_eur'] ?? 0 ), 0 ) ) . ' € (' . (int) $pct . '%)</span>';
		}
		echo '</p>';
		if ( null !== $pct ) {
			echo '<div class="gw-usage-bar' . ( ! empty( $report['alert'] ) ? ' gw-alert' : '' ) . '"><span style="width:' . min( 100, (int) $pct ) . '%"></span></div>';
		}
		echo '<p class="gw-muted">'
			. esc_html( number_format_i18n( (int) ( $totals['input_tokens'] ?? 0 ) ) ) . ' in · '
			. esc_html( number_format_i18n( (int) ( $totals['output_tokens'] ?? 0 ) ) ) . ' out · '
			. esc_html( number_format_i18n( (int) ( $totals['images'] ?? 0 ) ) ) . ' img</p>';
		$this->box_close();
	}

	private function render_log_box( int $project_id ): void {
		$entries = $this->log->latest( $project_id, 10 );

		$this->box_open( esc_html__( 'Attività recente', 'ghostwriter' ) );
		if ( empty( $entries ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessuna attività.', 'ghostwriter' ) . '</p>';
		} else {
			echo '<ul class="gw-log">';
			foreach ( $entries as $entry ) {
				echo '<li class="gw-log-' . esc_attr( (string) $entry['level'] ) . '"><code>' . esc_html( (string) $entry['event'] ) . '</code>'
					. '<span class="gw-muted"> · ' . esc_html( (string) $entry['created_at'] ) . '</span></li>';
			}
			echo '</ul>';
		}
		$this->box_close();
	}

	// ------------------------------------------------------------------ //

	private function state_badge( string $state ): string {
		return '<span class="gw-state gw-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>';
	}

	private function safe_is_translation( int $project_id ): bool {
		try {
			return $this->projects->is_translation( $project_id );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function safe_usage( int $project_id ): array {
		try {
			return $this->meter->report( $project_id );
		} catch ( \Throwable ) {
			return array( 'totals' => array() );
		}
	}
}

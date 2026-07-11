<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Core\PostTypes;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Jobs\ExportJob;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Rest\ProjectsController;

/**
 * Pagina Progetti: elenco + nuovo progetto + dettaglio con le azioni di
 * pipeline contestuali allo stato. Letture server-side, scritture via REST.
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
			$this->render_new_form();
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------ //
	// Elenco
	// ------------------------------------------------------------------ //

	private function render_list(): void {
		echo '<h1>' . esc_html__( 'Progetti Ghostwriter', 'ghostwriter' ) . '</h1>';

		$ids = get_posts(
			array(
				'post_type'      => PostTypes::PROJECT,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $ids ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessun progetto: crea il primo qui sotto.', 'ghostwriter' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'Titolo', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Tipo', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Stato', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Capitoli', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Costo stimato', 'ghostwriter' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $ids as $id ) {
			$id             = (int) $id;
			$is_translation = $this->safe_is_translation( $id );
			$type           = $is_translation ? StateMachine::TYPE_TRANSLATION : StateMachine::TYPE_PROJECT;
			$state          = $this->states->state_of( $id, $type );
			$usage          = $this->safe_usage( $id );
			$url            = admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . $id );

			echo '<tr>'
				. '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( get_the_title( $id ) ) . '</strong></a></td>'
				. '<td>' . ( $is_translation ? esc_html__( 'Traduzione', 'ghostwriter' ) : esc_html__( 'Libro', 'ghostwriter' ) ) . '</td>'
				. '<td>' . $this->state_badge( $state ) . '</td>'
				. '<td>' . count( $this->projects->get_chapter_ids( $id ) ) . '</td>'
				. '<td>' . esc_html( number_format_i18n( (float) ( $usage['totals']['cost_estimate'] ?? 0 ), 2 ) ) . ' €</td>'
				. '</tr>';
		}

		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------ //
	// Nuovo progetto
	// ------------------------------------------------------------------ //

	private function render_new_form(): void {
		$blocks = array( 'paragrafo', 'heading', 'citazione', 'box_approfondimento', 'figura', 'tabella', 'elenco', 'esercizio', 'codice', 'separatore' );

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Nuovo progetto', 'ghostwriter' ) . '</h2>';
		echo '<form class="gw-box" style="max-width:920px" data-gw-form="POST /projects" data-gw-transform="newProject" data-gw-goto-project>';
		echo '<div class="gw-grid">';

		echo '<div>';
		echo '<p><label><strong>' . esc_html__( 'Titolo del libro', 'ghostwriter' ) . '</strong><br/><input type="text" name="title" class="regular-text" required /></label></p>';
		echo '<p><label>' . esc_html__( 'Tesi/obiettivo (2-4 frasi)', 'ghostwriter' ) . '<br/><textarea name="thesis" rows="3" class="large-text" required></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Pubblico', 'ghostwriter' ) . '<br/><input type="text" name="audience" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Genere', 'ghostwriter' ) . '<br/><select name="genre">';
		foreach ( array( 'divulgazione', 'saggistica', 'manualistica', 'guida', 'narrativa', 'altro' ) as $genre ) {
			echo '<option value="' . esc_attr( $genre ) . '">' . esc_html( $genre ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Lingua', 'ghostwriter' ) . ' <input type="text" name="language" value="it" size="4" /></label> ';
		echo '<label>' . esc_html__( 'Obiettivo parole', 'ghostwriter' ) . ' <input type="number" name="target_words" min="1000" step="1000" placeholder="40000" /></label></p>';
		echo '<p><label>' . esc_html__( 'Formato', 'ghostwriter' ) . '<br/><select name="format_preset" onchange="const [w,h]=this.value.split(\'x\');this.form.trim_width_mm.value=w;this.form.trim_height_mm.value=h;">'
			. '<option value="150x230">15×23 cm</option><option value="148x210">A5 (14,8×21)</option><option value="170x240">17×24 cm</option></select>'
			. '<input type="hidden" name="trim_width_mm" value="150" /><input type="hidden" name="trim_height_mm" value="230" /></label></p>';
		echo '</div>';

		echo '<div>';
		echo '<p><strong>' . esc_html__( 'Blocchi ammessi', 'ghostwriter' ) . '</strong></p><p>';
		foreach ( $blocks as $block ) {
			$checked = in_array( $block, array( 'paragrafo', 'heading', 'citazione', 'box_approfondimento', 'figura', 'elenco' ), true ) ? ' checked' : '';
			echo '<label style="display:inline-block;margin:0 12px 6px 0"><input type="checkbox" name="allowed_blocks[]" value="' . esc_attr( $block ) . '"' . $checked . ' /> ' . esc_html( $block ) . '</label>';
		}
		echo '</p>';
		echo '<p><label><strong>' . esc_html__( 'Provider AI', 'ghostwriter' ) . '</strong><br/><select name="provider">'
			. '<option value="anthropic">Anthropic (Claude)</option>'
			. '<option value="openai">OpenAI</option>'
			. '<option value="mock">' . esc_html__( 'Mock (prova senza AI)', 'ghostwriter' ) . '</option>'
			. '</select></label> '
			. '<label>' . esc_html__( 'Modello', 'ghostwriter' ) . ' <input type="text" name="model" value="claude-sonnet-4-5" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Provider immagini (opzionale)', 'ghostwriter' ) . ' <select name="image_provider"><option value=""></option><option value="openai">OpenAI</option><option value="mock">mock</option></select></label> '
			. '<label>' . esc_html__( 'Modello immagini', 'ghostwriter' ) . ' <input type="text" name="image_model" placeholder="gpt-image-1" /></label></p>';
		echo '<p><label>' . esc_html__( 'Budget massimo (EUR)', 'ghostwriter' ) . ' <input type="number" name="max_cost_eur" min="1" step="1" placeholder="50" /></label></p>';
		echo '</div>';

		echo '</div>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Crea progetto', 'ghostwriter' ) . '</button></p>';
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

		$back = admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Tutti i progetti', 'ghostwriter' ) . '</a></p>';
		echo '<h1>' . esc_html( get_the_title( $project_id ) ) . ' ' . $this->state_badge( $state ) . '</h1>';
		echo '<p class="gw-muted">' . esc_html(
			sprintf(
				/* translators: 1: tipo, 2: lingua, 3: provider, 4: modello */
				__( '%1$s · lingua %2$s · %3$s / %4$s', 'ghostwriter' ),
				$is_translation ? __( 'Traduzione', 'ghostwriter' ) : __( 'Libro', 'ghostwriter' ),
				(string) ( $config['language'] ?? '' ),
				(string) ( $config['ai']['provider'] ?? '' ),
				(string) ( $config['ai']['model'] ?? '' )
			)
		) . '</p>';

		$this->render_pipeline_actions( $project_id, $state, $is_translation );

		echo '<div class="gw-grid">';
		if ( $is_translation ) {
			$this->render_glossary_box( $project_id, $state, $dossier );
		} else {
			$this->render_outline_box( $project_id, $state, $dossier );
			$this->render_sources_box( $project_id, $config );
		}
		$this->render_chapters_box( $project_id, $dossier );
		$this->render_cover_box( $project_id, $config );
		$this->render_export_box( $project_id, $config );
		$this->render_usage_box( $project_id );
		$this->render_log_box( $project_id );
		echo '</div>';
	}

	private function render_pipeline_actions( int $project_id, string $state, bool $is_translation ): void {
		echo '<div class="gw-box gw-actions"><h2>' . esc_html__( 'Pipeline', 'ghostwriter' ) . '</h2><p>';

		$button = static function ( string $label, string $action, bool $confirm = true, string $class = 'button button-primary' ): void {
			echo '<button class="' . esc_attr( $class ) . '" data-gw-action="' . esc_attr( $action ) . '"' . ( $confirm ? ' data-gw-confirm' : '' ) . '>' . esc_html( $label ) . '</button>';
		};

		if ( $is_translation ) {
			switch ( $state ) {
				case 'setup':
					echo '<span class="gw-muted">' . esc_html__( 'Proposta di glossario in corso (coda)…', 'ghostwriter' ) . '</span>';
					break;
				case 'glossary_proposed':
					$button( __( 'Approva glossario e avvia traduzione', 'ghostwriter' ), "POST /projects/{$project_id}/glossary/approve" );
					break;
				case 'translating':
					echo '<span class="gw-muted">' . esc_html__( 'Traduzione in corso…', 'ghostwriter' ) . '</span>';
					break;
				case 'review':
					$button( __( 'Chiudi revisione (pronto per l\'export)', 'ghostwriter' ), "POST /projects/{$project_id}/advance", true );
					break;
				case 'paused_budget':
					$button( __( 'Riprendi (budget)', 'ghostwriter' ), "POST /projects/{$project_id}/budget/resume" );
					break;
			}
		} else {
			switch ( $state ) {
				case 'setup':
				case 'sources_ingesting':
					$button( __( 'Proponi indice (AI)', 'ghostwriter' ), "POST /projects/{$project_id}/outline/propose" );
					break;
				case 'outline_proposed':
					$button( __( 'Approva indice e genera il libro', 'ghostwriter' ), "POST /projects/{$project_id}/outline/approve" );
					break;
				case 'generating':
					echo '<span class="gw-muted">' . esc_html__( 'Generazione capitoli in corso…', 'ghostwriter' ) . '</span>';
					break;
				case 'review':
					$button( __( 'Chiudi revisione', 'ghostwriter' ), "POST /projects/{$project_id}/advance" );
					break;
				case 'cover_pending':
					echo '<span class="gw-muted">' . esc_html__( 'Fase copertina in corso: gestiscila dal box Copertina qui sotto.', 'ghostwriter' ) . '</span>';
					break;
				case 'paused_budget':
					$button( __( 'Riprendi (budget)', 'ghostwriter' ), "POST /projects/{$project_id}/budget/resume" );
					break;
			}

			// Derivazione traduzione: da quando il libro è generato.
			if ( in_array( $state, array( 'review', 'cover_pending', 'ready_to_export', 'exported' ), true ) ) {
				echo '</p><form style="display:inline" data-gw-form="POST /projects/' . (int) $project_id . '/derive" data-gw-transform="derive" data-gw-goto-project>'
					. '<label>' . esc_html__( 'Traduci in', 'ghostwriter' ) . ' <input type="text" name="language" size="4" placeholder="en" required pattern="[A-Za-z]{2}.*" /></label> '
					. '<button class="button">' . esc_html__( 'Crea traduzione', 'ghostwriter' ) . '</button></form><p>';
			}
		}

		echo '</p></div>';
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_outline_box( int $project_id, string $state, array $dossier ): void {
		$outline  = (array) ( $dossier['outline'] ?? array() );
		$editable = 'outline_proposed' === $state;

		echo '<div class="gw-box"><h2>' . esc_html__( 'Indice', 'ghostwriter' ) . '</h2>';

		if ( empty( $outline ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Ancora nessun indice: usa "Proponi indice".', 'ghostwriter' ) . '</p></div>';
			return;
		}

		if ( $editable ) {
			echo '<form data-gw-form="PUT /projects/' . (int) $project_id . '/outline" data-gw-transform="outline">';
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $outline as $entry ) {
			$chapter_id = (int) ( $entry['chapter_id'] ?? 0 );
			if ( $editable ) {
				echo '<tr class="gw-outline-row" data-chapter-id="' . $chapter_id . '" data-status="' . esc_attr( (string) ( $entry['status'] ?? 'planned' ) ) . '"><td>'
					. '<input type="text" name="title" value="' . esc_attr( (string) ( $entry['title'] ?? '' ) ) . '" />'
					. '<textarea name="brief" rows="2">' . esc_textarea( (string) ( $entry['brief'] ?? '' ) ) . '</textarea>'
					. '</td></tr>';
			} else {
				echo '<tr><td><strong>' . esc_html( (string) ( $entry['title'] ?? '' ) ) . '</strong> ' . $this->state_badge( (string) ( $entry['status'] ?? '' ) )
					. '<br/><span class="gw-muted">' . esc_html( (string) ( $entry['brief'] ?? '' ) ) . '</span>'
					. ( ! empty( $entry['synopsis'] ) ? '<br/><em>' . esc_html( (string) $entry['synopsis'] ) . '</em>' : '' )
					. '</td></tr>';
			}
		}
		echo '</tbody></table>';
		if ( $editable ) {
			echo '<p><button class="button">' . esc_html__( 'Salva modifiche all\'indice', 'ghostwriter' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_glossary_box( int $project_id, string $state, array $dossier ): void {
		$glossary = (array) ( $dossier['glossary'] ?? array() );
		$editable = 'glossary_proposed' === $state;

		echo '<div class="gw-box"><h2>' . esc_html__( 'Glossario', 'ghostwriter' ) . '</h2>';

		if ( empty( $glossary ) && ! $editable ) {
			echo '<p class="gw-muted">' . esc_html__( 'Glossario non ancora proposto.', 'ghostwriter' ) . '</p></div>';
			return;
		}

		if ( $editable ) {
			echo '<form data-gw-form="PUT /projects/' . (int) $project_id . '/glossary" data-gw-transform="glossary">';
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Termine sorgente', 'ghostwriter' ) . '</th><th>' . esc_html__( 'Resa', 'ghostwriter' ) . '</th><th>' . esc_html__( 'Nota', 'ghostwriter' ) . '</th></tr></thead><tbody class="gw-glossary-rows">';
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
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $dossier Dossier corrente.
	 */
	private function render_chapters_box( int $project_id, array $dossier ): void {
		$ids = $this->projects->get_chapter_ids( $project_id );

		echo '<div class="gw-box"><h2>' . esc_html__( 'Capitoli', 'ghostwriter' ) . '</h2>';
		if ( empty( $ids ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'I capitoli nascono con l\'approvazione dell\'indice.', 'ghostwriter' ) . '</p></div>';
			return;
		}

		$words = array();
		foreach ( (array) ( $dossier['outline'] ?? array() ) as $entry ) {
			$words[ (int) ( $entry['chapter_id'] ?? 0 ) ] = $entry['word_count'] ?? null;
		}

		echo '<table class="widefat striped"><tbody>';
		foreach ( $ids as $chapter_id ) {
			$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
			echo '<tr><td><strong>' . esc_html( get_the_title( $chapter_id ) ) . '</strong></td>'
				. '<td>' . $this->state_badge( $state ) . '</td>'
				. '<td>' . ( ! empty( $words[ $chapter_id ] ) ? esc_html( number_format_i18n( (int) $words[ $chapter_id ] ) ) . ' ' . esc_html__( 'parole', 'ghostwriter' ) : '' ) . '</td>'
				. '<td>'
				. ( 'failed' === $state ? '<button class="button" data-gw-action="POST /chapters/' . (int) $chapter_id . '/retry" data-gw-confirm>' . esc_html__( 'Riprova', 'ghostwriter' ) . '</button> ' : '' )
				. '<button class="button button-small" data-gw-chapter-blocks="' . (int) $chapter_id . '">' . esc_html__( 'Blocchi', 'ghostwriter' ) . '</button>'
				. '</td>'
				. '</tr>'
				. '<tr class="gw-blocks-row" data-chapter="' . (int) $chapter_id . '" style="display:none"><td colspan="4"><div class="gw-blocks-target gw-muted">…</div></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Fonti del progetto: registry con stato ingest + registrazione.
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_sources_box( int $project_id, array $config ): void {
		$registry = (array) ( $config['sources']['registry'] ?? array() );

		echo '<div class="gw-box"><h2>' . esc_html__( 'Fonti', 'ghostwriter' ) . '</h2>';

		if ( ! empty( $registry ) ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $registry as $source ) {
				echo '<tr><td><strong>' . esc_html( (string) ( $source['title'] ?? $source['source_id'] ?? '' ) ) . '</strong>'
					. '<br/><span class="gw-muted">' . esc_html( (string) ( $source['type'] ?? '' ) . ' · ' . (string) ( $source['license'] ?? '' ) ) . '</span></td>'
					. '<td>' . $this->state_badge( (string) ( $source['ingest_status'] ?? 'registrata' ) )
					. ( ! empty( $source['chunk_count'] ) ? ' <span class="gw-muted">' . (int) $source['chunk_count'] . ' ' . esc_html__( 'frammenti', 'ghostwriter' ) . '</span>' : '' )
					. '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<form data-gw-form="POST /projects/' . (int) $project_id . '/sources" data-gw-transform="addSource" style="margin-top:10px">'
			. '<p><input type="text" name="title" placeholder="' . esc_attr__( 'Titolo della fonte', 'ghostwriter' ) . '" required class="regular-text" /></p>'
			. '<p><select name="type"><option value="url">URL</option><option value="pdf">PDF (path sul server)</option><option value="open_data">open data (URL)</option></select> '
			. '<input type="text" name="location" placeholder="https://… o /percorso/file.pdf" class="regular-text" required /></p>'
			. '<p><select name="license"><option value="CC-BY-4.0">CC-BY-4.0</option><option value="CC0">CC0</option><option value="pubblico dominio">' . esc_html__( 'pubblico dominio', 'ghostwriter' ) . '</option><option value="proprietaria">' . esc_html__( 'proprietaria', 'ghostwriter' ) . '</option></select> '
			. '<label><input type="checkbox" name="attribution_required" value="1" /> ' . esc_html__( 'attribuzione richiesta', 'ghostwriter' ) . '</label> '
			. '<button class="button">' . esc_html__( 'Registra e ingerisci', 'ghostwriter' ) . '</button></p>'
			. '</form></div>';
	}

	/**
	 * Copertina: stato, brief, anteprime, azioni.
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_cover_box( int $project_id, array $config ): void {
		$cover_state = $this->states->state_of( $project_id, StateMachine::TYPE_COVER );
		$cover       = (array) ( $config['cover'] ?? array() );

		echo '<div class="gw-box"><h2>' . esc_html__( 'Copertina', 'ghostwriter' ) . ' ' . $this->state_badge( $cover_state ) . '</h2>';

		// Brief creativo (editabile prima della composizione).
		$editable_brief = in_array( $cover_state, array( 'pending', 'brief_ready', 'artwork_ready' ), true );
		if ( $editable_brief ) {
			echo '<form data-gw-form="PUT /projects/' . (int) $project_id . '/cover">'
				. '<p><label>' . esc_html__( 'Brief creativo (l\'artwork è SEMPRE senza testo)', 'ghostwriter' ) . '<br/>'
				. '<textarea name="creative_brief" rows="3" class="large-text">' . esc_textarea( (string) ( $cover['creative_brief'] ?? '' ) ) . '</textarea></label></p>'
				. '<p><label>' . esc_html__( 'Modalità', 'ghostwriter' ) . ' <select name="mode">'
				. '<option value="ai_generated"' . selected( ( $cover['mode'] ?? 'ai_generated' ), 'ai_generated', false ) . '>AI</option>'
				. '<option value="upload"' . selected( ( $cover['mode'] ?? '' ), 'upload', false ) . '>' . esc_html__( 'artwork caricato', 'ghostwriter' ) . '</option>'
				. '</select></label> '
				. '<label>' . esc_html__( 'ID media artwork (per upload)', 'ghostwriter' ) . ' <input type="number" name="front_artwork_attachment_id" value="' . esc_attr( (string) ( $cover['front_artwork_attachment_id'] ?? '' ) ) . '" style="width:90px" /></label> '
				. '<button class="button">' . esc_html__( 'Salva', 'ghostwriter' ) . '</button></p></form>';
		} elseif ( ! empty( $cover['creative_brief'] ) ) {
			echo '<p class="gw-muted">' . esc_html( (string) $cover['creative_brief'] ) . '</p>';
		}

		// Anteprime.
		foreach ( array(
			'front_artwork_attachment_id' => __( 'Artwork (senza testo)', 'ghostwriter' ),
			'composed_attachment_id'      => __( 'Composizione', 'ghostwriter' ),
		) as $key => $label ) {
			if ( ! empty( $cover[ $key ] ) ) {
				$thumbnail = wp_get_attachment_image( (int) $cover[ $key ], array( 150, 220 ) );
				if ( $thumbnail ) {
					echo '<div style="display:inline-block;margin:0 10px 10px 0;text-align:center"><div>' . $thumbnail . '</div><span class="gw-muted">' . esc_html( $label ) . '</span></div>';
				}
			}
		}

		// Azioni per stato.
		echo '<p class="gw-actions">';
		if ( 'pending' === $cover_state ) {
			echo '<button class="button" data-gw-action="POST /projects/' . (int) $project_id . '/cover/regenerate">' . esc_html__( 'Avvia pipeline copertina', 'ghostwriter' ) . '</button>';
		}
		if ( 'composed' === $cover_state ) {
			echo '<button class="button button-primary" data-gw-action="POST /projects/' . (int) $project_id . '/cover/approve" data-gw-confirm>' . esc_html__( 'Approva copertina', 'ghostwriter' ) . '</button> ';
			echo '<button class="button" data-gw-action="POST /projects/' . (int) $project_id . '/cover/regenerate" data-gw-confirm>' . esc_html__( 'Rigenera (nuovo artwork)', 'ghostwriter' ) . '</button>';
		}
		if ( 'approved' === $cover_state ) {
			echo '<span class="gw-muted">' . esc_html__( 'Copertina approvata: entra nel PDF e nell\'ePub.', 'ghostwriter' ) . '</span>';
		}
		echo '</p></div>';
	}

	/**
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_export_box( int $project_id, array $config ): void {
		echo '<div class="gw-box"><h2>' . esc_html__( 'Export', 'ghostwriter' ) . '</h2>';

		$width  = (float) ( $config['format']['trim_width_mm'] ?? 0 );
		$height = (float) ( $config['format']['trim_height_mm'] ?? 0 );

		echo '<form data-gw-form="POST /projects/' . (int) $project_id . '/export" data-gw-transform="exportBook"><p>';
		echo '<select name="theme">';
		foreach ( $this->themes->all() as $key => $theme ) {
			$compatible = $theme->supports_format( $width, $height );
			echo '<option value="' . esc_attr( $key ) . '"' . ( $compatible ? '' : ' disabled' ) . '>'
				. esc_html( $theme->name() . ' ' . $theme->version() . ( $compatible ? '' : ' — ' . __( 'formato non supportato', 'ghostwriter' ) ) )
				. '</option>';
		}
		echo '</select> ';
		echo '<select name="target"><option value="pdf">PDF</option><option value="epub">ePub</option></select> ';
		echo '<button type="button" class="button" data-gw-preflight="' . (int) $project_id . '">' . esc_html__( 'Preflight', 'ghostwriter' ) . '</button> ';
		echo '<button class="button button-primary">' . esc_html__( 'Esporta', 'ghostwriter' ) . '</button>';
		echo '</p></form>';
		echo '<div class="gw-preflight-report" style="display:none"></div>';

		$exports = get_post_meta( $project_id, ExportJob::META_EXPORTS, true );
		$exports = is_array( $exports ) ? array_reverse( $exports ) : array();
		if ( ! empty( $exports ) ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( array_slice( $exports, 0, 8 ) as $export ) {
				$file = (string) ( $export['file'] ?? '' );
				$url  = rest_url( ProjectsController::REST_NAMESPACE . "/projects/{$project_id}/exports/" . rawurlencode( $file ) );
				$url  = wp_nonce_url( $url, 'wp_rest', '_wpnonce' );
				echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $file ) . '</a></td>'
					. '<td class="gw-muted">' . esc_html( size_format( (int) ( $export['size'] ?? 0 ) ) ?: '' ) . '</td>'
					. '<td class="gw-muted">' . esc_html( (string) ( $export['created_at'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function render_usage_box( int $project_id ): void {
		$report = $this->safe_usage( $project_id );
		$totals = (array) ( $report['totals'] ?? array() );
		$pct    = $report['cost_pct'] ?? null;

		echo '<div class="gw-box"><h2>' . esc_html__( 'Costi', 'ghostwriter' ) . '</h2>';
		echo '<p><strong>' . esc_html( number_format_i18n( (float) ( $totals['cost_estimate'] ?? 0 ), 2 ) ) . ' €</strong>';
		if ( null !== $pct ) {
			echo ' <span class="gw-muted">(' . (int) $pct . '% ' . esc_html__( 'del budget', 'ghostwriter' ) . ')</span>';
			echo '<div class="gw-usage-bar' . ( ! empty( $report['alert'] ) ? ' gw-alert' : '' ) . '"><span style="width:' . min( 100, (int) $pct ) . '%"></span></div>';
		}
		echo '</p><p class="gw-muted">'
			. esc_html( number_format_i18n( (int) ( $totals['input_tokens'] ?? 0 ) ) ) . ' token in · '
			. esc_html( number_format_i18n( (int) ( $totals['output_tokens'] ?? 0 ) ) ) . ' token out · '
			. esc_html( number_format_i18n( (int) ( $totals['images'] ?? 0 ) ) ) . ' ' . esc_html__( 'immagini', 'ghostwriter' )
			. '</p></div>';
	}

	private function render_log_box( int $project_id ): void {
		$entries = $this->log->latest( $project_id, 12 );

		echo '<div class="gw-box"><h2>' . esc_html__( 'Attività recente', 'ghostwriter' ) . '</h2>';
		if ( empty( $entries ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessuna attività.', 'ghostwriter' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $entries as $entry ) {
			echo '<tr><td class="gw-muted">' . esc_html( (string) $entry['created_at'] ) . '</td>'
				. '<td>' . esc_html( (string) $entry['event'] ) . '</td>'
				. '<td class="gw-muted">' . esc_html( (string) $entry['level'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
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

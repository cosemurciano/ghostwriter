<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Ai\SkillsManager;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Jobs\ExportJob;
use Ghostwriter\Queue\QueueStatus;
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
		private LogRepository $log,
		private SkillsManager $skills,
		private QueueStatus $queue
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

		if ( 'paused_budget' === $state ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Budget superato: pipeline in pausa.', 'ghostwriter' ) . '</strong> '
				. esc_html__( 'Alza il budget nella config o riprendi dopo verifica.', 'ghostwriter' )
				. ' <button class="button button-small" data-gw-action="POST /projects/' . $project_id . '/budget/resume" data-gw-confirm>' . esc_html__( 'Riprendi', 'ghostwriter' ) . '</button></p></div>';
		}

		$this->render_pipeline_actions( $project_id, $state, $is_translation );

		// Tab sempre cliccabili: nessun wizard, ogni sezione è accessibile
		// in qualunque stato (le azioni restano contestuali nella barra sopra).
		$tabs = $is_translation
			? array(
				'impostazioni' => __( 'Impostazioni', 'ghostwriter' ),
				'skills'    => __( 'Skills', 'ghostwriter' ),
				'glossario' => __( 'Glossario', 'ghostwriter' ),
				'capitoli'  => __( 'Capitoli', 'ghostwriter' ),
				'copertina' => __( 'Copertina', 'ghostwriter' ),
				'export'    => __( 'Export', 'ghostwriter' ),
				'attivita'  => __( 'Costi e attività', 'ghostwriter' ),
			)
			: array(
				'impostazioni' => __( 'Impostazioni', 'ghostwriter' ),
				'skills'    => __( 'Skills', 'ghostwriter' ),
				'fonti'     => __( 'Fonti', 'ghostwriter' ),
				'indice'    => __( 'Indice', 'ghostwriter' ),
				'capitoli'  => __( 'Capitoli', 'ghostwriter' ),
				'copertina' => __( 'Copertina', 'ghostwriter' ),
				'export'    => __( 'Export', 'ghostwriter' ),
				'attivita'  => __( 'Costi e attività', 'ghostwriter' ),
			);

		$default_tab = match ( $state ) {
			'setup', 'sources_ingesting' => $is_translation ? 'glossario' : 'impostazioni',
			'outline_proposed', 'outline_approved' => 'indice',
			'glossary_proposed', 'glossary_approved' => 'glossario',
			'generating', 'translating', 'review' => 'capitoli',
			'cover_pending' => 'copertina',
			'ready_to_export', 'exported' => 'export',
			default => array_key_first( $tabs ),
		};

		echo '<nav class="nav-tab-wrapper gw-tabs">';
		foreach ( $tabs as $key => $label ) {
			echo '<a href="#' . esc_attr( $key ) . '" class="nav-tab' . ( $key === $default_tab ? ' nav-tab-active' : '' ) . '" data-gw-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		$panel = function ( string $key, callable $content ) use ( $default_tab ): void {
			echo '<div class="gw-panel" data-gw-panel="' . esc_attr( $key ) . '"' . ( $key === $default_tab ? '' : ' style="display:none"' ) . '>';
			$content();
			echo '</div>';
		};

		$panel( 'impostazioni', fn() => $this->render_settings_box( $project_id, $state, $config, $is_translation ) );
		$panel( 'skills', fn() => $this->render_skills_box( $project_id, $config ) );
		if ( $is_translation ) {
			$panel( 'glossario', fn() => $this->render_glossary_box( $project_id, $state, $dossier ) );
		} else {
			$panel( 'fonti', fn() => $this->render_sources_box( $project_id, $config ) );
			$panel( 'indice', fn() => $this->render_outline_box( $project_id, $state, $dossier ) );
		}
		$panel( 'capitoli', fn() => $this->render_chapters_box( $project_id, $dossier ) );
		$panel( 'copertina', fn() => $this->render_cover_box( $project_id, $config ) );
		$panel( 'export', fn() => $this->render_export_box( $project_id, $config ) );
		$panel(
			'attivita',
			function () use ( $project_id ): void {
				$this->render_usage_box( $project_id );
				$this->render_log_box( $project_id );
			}
		);
	}

	/**
	 * Tab Skills: quali skill del registro montare sul progetto e in quali
	 * fasi della pipeline. Le fasi proposte vengono dal frontmatter della
	 * skill (metadata.x-ghostwriter.default_phases) o dalla config salvata.
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_skills_box( int $project_id, array $config ): void {
		$registry = $this->skills->registry();
		$mounted  = array();
		foreach ( (array) ( $config['skills'] ?? array() ) as $entry ) {
			$mounted[ (string) ( $entry['skill_id'] ?? '' ) ] = array(
				'version' => (string) ( $entry['version'] ?? '' ),
				'phases'  => array_map( 'strval', (array) ( $entry['phases'] ?? array() ) ),
			);
		}

		$this->box_open( esc_html__( 'Skills del progetto', 'ghostwriter' ) );

		if ( array() === $registry ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessuna skill installata. Importa un pacchetto zip dalla sezione Skills.', 'ghostwriter' ) . '</p>'
				. '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SKILLS ) ) . '">' . esc_html__( 'Vai alle Skills', 'ghostwriter' ) . '</a></p>';
			$this->box_close();
			return;
		}

		$all_phases = array(
			'outline'     => __( 'Indice', 'ghostwriter' ),
			'draft'       => __( 'Stesura', 'ghostwriter' ),
			'review'      => __( 'Revisione', 'ghostwriter' ),
			'images'      => __( 'Immagini', 'ghostwriter' ),
			'cover'       => __( 'Copertina', 'ghostwriter' ),
			'translation' => __( 'Traduzione', 'ghostwriter' ),
			'glossary'    => __( 'Glossario', 'ghostwriter' ),
			'export'      => __( 'Export', 'ghostwriter' ),
		);

		echo '<p class="gw-muted">' . esc_html__( 'Spunta le skill da usare per questo progetto e le fasi della pipeline in cui montarle: ogni chiamata AI carica solo le skill della propria fase (controllo costi).', 'ghostwriter' ) . '</p>';
		echo '<form data-gw-form="PUT /projects/' . $project_id . '/settings" data-gw-transform="projectSkills">';
		echo '<table class="widefat striped gw-clean-table"><thead><tr>'
			. '<th class="check-column"></th>'
			. '<th>' . esc_html__( 'Skill', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Descrizione', 'ghostwriter' ) . '</th>'
			. '<th>' . esc_html__( 'Fasi in cui montarla', 'ghostwriter' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $registry as $skill_id => $versions ) {
			$version = $mounted[ $skill_id ]['version'] ?? (string) end( $versions );
			if ( ! in_array( $version, $versions, true ) ) {
				$version = (string) end( $versions );
			}
			$meta    = $this->skills->describe( $skill_id, $version );
			$active  = isset( $mounted[ $skill_id ] );
			$phases  = $active ? $mounted[ $skill_id ]['phases'] : $meta['default_phases'];

			echo '<tr class="gw-skill-row" data-skill="' . esc_attr( $skill_id ) . '" data-version="' . esc_attr( $version ) . '">';
			echo '<th class="check-column"><input type="checkbox" name="skill_on"' . checked( $active, true, false ) . '/></th>';
			echo '<td><strong>' . esc_html( $meta['name'] ) . '</strong><br/><span class="gw-muted">' . esc_html( $skill_id . ' · v' . $version ) . '</span></td>';
			echo '<td>' . esc_html( wp_trim_words( $meta['description'], 30 ) ) . '</td>';
			echo '<td>';
			foreach ( $all_phases as $phase => $phase_label ) {
				echo '<label class="gw-phase-check"><input type="checkbox" name="phase" value="' . esc_attr( $phase ) . '"' . checked( in_array( $phase, $phases, true ), true, false ) . '/> ' . esc_html( $phase_label ) . '</label>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="gw-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Salva skills del progetto', 'ghostwriter' ) . '</button></p>';
		echo '</form>';
		$this->box_close();
	}

	/**
	 * Impostazioni del progetto: tutti i campi della config, raggruppati con
	 * logica (contenuto → formato → struttura → motore AI e budget). Formato
	 * e blocchi si bloccano quando la generazione è partita.
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_settings_box( int $project_id, string $state, array $config, bool $is_translation ): void {
		$unlocked = in_array( $state, array( 'setup', 'sources_ingesting', 'outline_proposed', 'glossary_proposed' ), true );
		$lock     = $unlocked ? '' : ' disabled';
		$brief    = (array) ( $config['brief'] ?? array() );
		$format   = (array) ( $config['format'] ?? array() );
		$ai       = (array) ( $config['ai'] ?? array() );
		$allowed  = array_map( 'strval', (array) ( $config['structural_profile']['allowed_blocks'] ?? array() ) );

		echo '<form data-gw-form="PUT /projects/' . $project_id . '/settings" data-gw-transform="projectSettings">';

		$this->box_open( esc_html__( 'Contenuto e brief', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Titolo', 'ghostwriter' ) . '</th><td><input type="text" name="title" value="' . esc_attr( get_the_title( $project_id ) ) . '" class="regular-text"/></td></tr>';
		echo '<tr><th>' . esc_html__( 'Tesi / obiettivo', 'ghostwriter' ) . '</th><td><textarea name="thesis" rows="3" class="large-text">' . esc_textarea( (string) ( $brief['thesis'] ?? '' ) ) . '</textarea></td></tr>';
		echo '<tr><th>' . esc_html__( 'Pubblico', 'ghostwriter' ) . '</th><td><input type="text" name="audience" value="' . esc_attr( (string) ( $brief['audience'] ?? '' ) ) . '" class="regular-text"/></td></tr>';
		echo '<tr><th>' . esc_html__( 'Genere', 'ghostwriter' ) . '</th><td><select name="genre">';
		foreach ( array( 'divulgazione', 'saggistica', 'manualistica', 'guida', 'narrativa', 'altro' ) as $genre ) {
			echo '<option value="' . esc_attr( $genre ) . '"' . selected( (string) ( $brief['genre'] ?? '' ), $genre, false ) . '>' . esc_html( $genre ) . '</option>';
		}
		echo '</select> <label>' . esc_html__( 'Obiettivo parole', 'ghostwriter' ) . ' <input type="number" name="target_words" min="1000" step="1000" value="' . esc_attr( (string) ( $brief['target_length']['value'] ?? '' ) ) . '"/></label></td></tr>';
		echo '</table>';
		$this->box_close();

		$this->box_open(
			esc_html__( 'Formato fisico e struttura', 'ghostwriter' )
			. ( $unlocked ? '' : ' <span class="gw-state gw-state-complete">' . esc_html__( 'bloccati: generazione avviata', 'ghostwriter' ) . '</span>' )
		);
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Formato (mm)', 'ghostwriter' ) . '</th><td>'
			. '<input type="number" name="trim_width_mm" value="' . esc_attr( (string) ( $format['trim_width_mm'] ?? '' ) ) . '" style="width:90px"' . $lock . '/> × '
			. '<input type="number" name="trim_height_mm" value="' . esc_attr( (string) ( $format['trim_height_mm'] ?? '' ) ) . '" style="width:90px"' . $lock . '/> '
			. '<label style="margin-left:12px"><input type="checkbox" name="print_ready" value="1"' . checked( ! empty( $format['print_ready'] ), true, false ) . $lock . '/> ' . esc_html__( 'print-ready (300dpi, abbondanza)', 'ghostwriter' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Vincola copertina, risoluzione immagini e temi compatibili.', 'ghostwriter' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Blocchi ammessi', 'ghostwriter' ) . '</th><td><fieldset class="gw-blocks-fieldset">';
		foreach ( array( 'paragrafo', 'heading', 'citazione', 'box_approfondimento', 'figura', 'tabella', 'elenco', 'esercizio', 'codice', 'separatore' ) as $block ) {
			echo '<label><input type="checkbox" name="allowed_blocks[]" value="' . esc_attr( $block ) . '"' . checked( in_array( $block, $allowed, true ), true, false ) . $lock . '/> ' . esc_html( $block ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		echo '</table>';
		$this->box_close();

		$this->box_open( esc_html__( 'Motore AI e budget', 'ghostwriter' ) );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Provider e modello', 'ghostwriter' ) . '</th><td><select name="provider">';
		foreach ( array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'mock' => 'Mock (senza AI)' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) ( $ai['provider'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="text" name="model" value="' . esc_attr( (string) ( $ai['model'] ?? '' ) ) . '" class="regular-text"/>'
			. '<p class="description">' . esc_html__( 'Vale per le prossime chiamate: i capitoli già generati restano.', 'ghostwriter' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Immagini', 'ghostwriter' ) . '</th><td><select name="image_provider">'
			. '<option value=""' . selected( (string) ( $ai['image_provider'] ?? '' ), '', false ) . '>' . esc_html__( '— nessuna generazione —', 'ghostwriter' ) . '</option>'
			. '<option value="openai"' . selected( (string) ( $ai['image_provider'] ?? '' ), 'openai', false ) . '>OpenAI</option>'
			. '<option value="mock"' . selected( (string) ( $ai['image_provider'] ?? '' ), 'mock', false ) . '>mock</option>'
			. '</select> <input type="text" name="image_model" value="' . esc_attr( (string) ( $ai['image_model'] ?? '' ) ) . '" placeholder="gpt-image-1"/></td></tr>';
		echo '<tr><th>' . esc_html__( 'Budget massimo', 'ghostwriter' ) . '</th><td><input type="number" name="max_cost_eur" min="1" step="1" value="' . esc_attr( (string) ( $ai['budget']['max_cost_eur'] ?? '' ) ) . '" style="width:110px"/> €'
			. '<p class="description">' . esc_html__( 'Vuoto = nessun limite. Al superamento la pipeline si ferma in paused_budget.', 'ghostwriter' ) . '</p></td></tr>';
		echo '</table>';
		$this->box_close();

		echo '<p><button class="button button-primary">' . esc_html__( 'Salva impostazioni', 'ghostwriter' ) . '</button></p></form>';
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

		$this->render_queue_widget( $project_id, $state );
	}

	/**
	 * Widget "Lavori in corso": fotografia live della coda del progetto,
	 * aggiornata via polling (GET /projects/{id}/queue) da gw-admin.js, che
	 * ricarica la pagina quando lo stato cambia o i job finiscono.
	 */
	private function render_queue_widget( int $project_id, string $state ): void {
		$jobs = $this->queue->for_project( $project_id );

		echo '<div class="gw-queue" data-gw-queue data-gw-project="' . $project_id . '" data-gw-state="' . esc_attr( $state ) . '"'
			. ( empty( $jobs ) ? ' style="display:none"' : '' ) . '>';
		echo '<span class="spinner is-active"></span><div class="gw-queue-body">';
		foreach ( $jobs as $job ) {
			echo '<p class="gw-queue-job"><strong>' . esc_html( $job['label'] ) . '</strong> — '
				. esc_html( 'in-progress' === $job['status'] ? __( 'in esecuzione', 'ghostwriter' ) : __( 'in coda', 'ghostwriter' ) )
				. ( $job['attempt'] > 1 ? esc_html( sprintf( __( ' · tentativo %1$d di %2$d', 'ghostwriter' ), $job['attempt'], QueueStatus::MAX_ATTEMPTS ) ) : '' )
				. ( $job['next_run'] ? esc_html( sprintf( __( ' · prossimo passaggio alle %s', 'ghostwriter' ), $job['next_run'] ) ) : '' )
				. '</p>';
		}
		echo '</div></div>';
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
	 * Fonti: registry con test di raggiungibilità, media WP, path PDF, URL,
	 * articoli del sito (spunta) e vector store remoto in base al provider.
	 *
	 * @param array<string, mixed> $config Config progetto.
	 */
	private function render_sources_box( int $project_id, array $config ): void {
		$registry = (array) ( $config['sources']['registry'] ?? array() );
		$provider = (string) ( $config['ai']['provider'] ?? 'mock' );

		$this->box_open( esc_html__( 'Fonti registrate', 'ghostwriter' ) );
		if ( ! empty( $registry ) ) {
			echo '<table class="widefat striped gw-clean-table"><tbody>';
			foreach ( $registry as $source ) {
				$sid = (string) ( $source['source_id'] ?? '' );
				echo '<tr><td><strong>' . esc_html( (string) ( $source['title'] ?? $sid ) ) . '</strong>'
					. '<br/><span class="gw-muted">' . esc_html( (string) ( $source['type'] ?? '' ) . ' · ' . (string) ( $source['license'] ?? '' ) )
					. ( ! empty( $source['site_posts'] ) ? ' · ' . esc_html__( 'articoli del sito', 'ghostwriter' ) : '' )
					. ( ! empty( $source['attachment_id'] ) ? ' · media #' . (int) $source['attachment_id'] : '' )
					. '</span></td>'
					. '<td>' . $this->state_badge( (string) ( $source['ingest_status'] ?? 'registrata' ) )
					. ( ! empty( $source['chunk_count'] ) ? ' <span class="gw-muted">' . (int) $source['chunk_count'] . ' ' . esc_html__( 'frammenti', 'ghostwriter' ) . '</span>' : '' )
					. '</td><td class="gw-row-actions">'
					. '<button class="button button-small" data-gw-action="POST /projects/' . $project_id . '/sources/test" data-gw-body="' . esc_attr( (string) wp_json_encode( array( 'source_id' => $sid ) ) ) . '" data-gw-noreload>' . esc_html__( 'Test', 'ghostwriter' ) . '</button>'
					. '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="gw-muted">' . esc_html__( 'Nessuna fonte registrata: i capitoli citeranno solo la conoscenza del modello, senza provenienza verificabile.', 'ghostwriter' ) . '</p>';
		}
		$this->box_close();

		// Articoli del sito come fonte (spunta).
		$has_site_posts = false;
		foreach ( $registry as $source ) {
			if ( ! empty( $source['site_posts'] ) ) {
				$has_site_posts = true;
			}
		}
		$this->box_open( esc_html__( 'Aggiungi fonte', 'ghostwriter' ) );
		echo '<p><label><input type="checkbox" data-gw-site-posts="' . $project_id . '"' . ( $has_site_posts ? ' checked disabled' : '' ) . '/> '
			. esc_html__( 'Usa tutti gli articoli pubblicati del sito come fonte', 'ghostwriter' ) . '</label>'
			. ( $has_site_posts ? ' <span class="gw-muted">' . esc_html__( '(già registrati)', 'ghostwriter' ) . '</span>' : '' ) . '</p><hr/>';

		echo '<form data-gw-form="POST /projects/' . $project_id . '/sources" data-gw-transform="addSource" class="gw-source-grid">'
			. '<input type="text" name="title" placeholder="' . esc_attr__( 'Titolo della fonte', 'ghostwriter' ) . '" required />'
			. '<select name="type" data-gw-source-type>'
			. '<option value="url">' . esc_html__( 'URL (pagina o open data)', 'ghostwriter' ) . '</option>'
			. '<option value="media">' . esc_html__( 'Media WordPress (PDF/testo)', 'ghostwriter' ) . '</option>'
			. '<option value="pdf">' . esc_html__( 'PDF (path sul server)', 'ghostwriter' ) . '</option>'
			. '</select>'
			. '<div data-gw-location-url><input type="text" name="location" placeholder="https://…" /></div>'
			. '<div data-gw-location-media style="display:none"><button type="button" class="button" data-gw-pick-media>' . esc_html__( 'Scegli dai media…', 'ghostwriter' ) . '</button> <span class="gw-media-chosen gw-muted"></span><input type="hidden" name="attachment_id" /></div>'
			. '<select name="license"><option value="CC-BY-4.0">CC-BY-4.0</option><option value="CC0">CC0</option><option value="pubblico dominio">' . esc_html__( 'pubblico dominio', 'ghostwriter' ) . '</option><option value="proprietaria">' . esc_html__( 'proprietaria', 'ghostwriter' ) . '</option></select>'
			. '<label class="gw-inline-label"><input type="checkbox" name="attribution_required" value="1" /> ' . esc_html__( 'attribuzione richiesta', 'ghostwriter' ) . '</label>'
			. '<p class="gw-actions"><button type="button" class="button" data-gw-test-source="' . $project_id . '">' . esc_html__( 'Testa raggiungibilità', 'ghostwriter' ) . '</button> '
			. '<button class="button button-primary">' . esc_html__( 'Registra e ingerisci', 'ghostwriter' ) . '</button></p>'
			. '</form>';
		$this->box_close();

		// Vector store remoto (dipende dal provider configurato).
		$vector_store_id = (string) ( $config['sources']['vector_store_id'] ?? '' );
		$this->box_open( esc_html__( 'Vector store remoto', 'ghostwriter' ) );
		echo '<p class="gw-muted">' . esc_html(
			'openai' === $provider
				? __( 'ID di un vector store OpenAI già popolato (vs_…): verrà agganciato al progetto.', 'ghostwriter' )
				/* translators: %s: provider */
				: sprintf( __( 'Il provider configurato (%s) non usa vector store remoti: le fonti locali qui sopra alimentano il RAG del progetto.', 'ghostwriter' ), $provider )
		) . '</p>';
		echo '<form data-gw-form="PUT /projects/' . $project_id . '/vectorstore" class="gw-actions">'
			. '<input type="text" name="vector_store_id" value="' . esc_attr( $vector_store_id ) . '" placeholder="vs_…" class="regular-text" /> '
			. '<button type="button" class="button" data-gw-action="POST /projects/' . $project_id . '/vectorstore/test" data-gw-from-input="vector_store_id" data-gw-noreload>' . esc_html__( 'Test', 'ghostwriter' ) . '</button> '
			. '<button class="button">' . esc_html__( 'Salva', 'ghostwriter' ) . '</button>'
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
				$context = is_array( $entry['context'] ) ? $entry['context'] : array();
				$event   = (string) $entry['event'];

				echo '<li class="gw-log-' . esc_attr( (string) $entry['level'] ) . '" title="' . esc_attr( $event ) . '">'
					. '<strong>' . esc_html( self::event_label( $event ) ) . '</strong>'
					. ( isset( $context['job'] ) ? ' <span class="gw-muted">— ' . esc_html( QueueStatus::job_label( (string) $context['job'] ) ) . '</span>' : '' )
					. ( isset( $context['attempt'] ) ? ' <span class="gw-muted">' . esc_html( sprintf( __( '· tentativo %1$d di %2$d', 'ghostwriter' ), (int) $context['attempt'], QueueStatus::MAX_ATTEMPTS ) ) . '</span>' : '' )
					. '<span class="gw-muted"> · ' . esc_html( (string) $entry['created_at'] ) . '</span>';
				if ( isset( $context['error'] ) && '' !== (string) $context['error'] ) {
					echo '<br/><span class="gw-log-detail">' . esc_html( (string) $context['error'] ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		$this->box_close();
	}

	// ------------------------------------------------------------------ //

	/**
	 * Descrizione italiana degli eventi del log pipeline (il codice evento
	 * originale resta nel title dell'elemento).
	 */
	private static function event_label( string $event ): string {
		return match ( $event ) {
			'job_dispatched'          => __( 'Lavoro accodato', 'ghostwriter' ),
			'job_attempt_failed'      => __( 'Tentativo fallito: nuovo tentativo automatico', 'ghostwriter' ),
			'job_failed'              => __( 'Lavoro fallito: tentativi esauriti', 'ghostwriter' ),
			'state_changed'           => __( 'Cambio di stato', 'ghostwriter' ),
			'outline_proposed'        => __( 'Indice proposto', 'ghostwriter' ),
			'outline_approved'        => __( 'Indice approvato', 'ghostwriter' ),
			'outline_proposal_failed' => __( 'Proposta indice fallita', 'ghostwriter' ),
			'glossary_proposed'       => __( 'Glossario proposto', 'ghostwriter' ),
			'glossary_approved'       => __( 'Glossario approvato', 'ghostwriter' ),
			'glossary_proposal_failed' => __( 'Proposta glossario fallita', 'ghostwriter' ),
			'sources_ingest_started'  => __( 'Ingestione fonti avviata', 'ghostwriter' ),
			'source_ingested'         => __( 'Fonte acquisita', 'ghostwriter' ),
			'source_ingest_failed'    => __( 'Acquisizione fonte fallita', 'ghostwriter' ),
			'chapter_indexed'         => __( 'Capitolo indicizzato (RAG)', 'ghostwriter' ),
			'chapter_index_failed'    => __( 'Indicizzazione capitolo fallita', 'ghostwriter' ),
			'image_generated'         => __( 'Immagine generata', 'ghostwriter' ),
			'cover_approved'          => __( 'Copertina approvata', 'ghostwriter' ),
			'cover_brief_failed'      => __( 'Copertina: brief fallito', 'ghostwriter' ),
			'cover_artwork_failed'    => __( 'Copertina: artwork fallito', 'ghostwriter' ),
			'cover_compose_failed'    => __( 'Copertina: composizione fallita', 'ghostwriter' ),
			'export_completed'        => __( 'Export completato', 'ghostwriter' ),
			'export_failed'           => __( 'Export fallito', 'ghostwriter' ),
			'budget_exceeded'         => __( 'Budget superato: pipeline in pausa', 'ghostwriter' ),
			'budget_still_exceeded'   => __( 'Budget ancora superato', 'ghostwriter' ),
			'budget_resumed'          => __( 'Pipeline ripresa dopo pausa budget', 'ghostwriter' ),
			'translation_started'     => __( 'Traduzione avviata', 'ghostwriter' ),
			'chapters_translated'     => __( 'Capitoli tradotti', 'ghostwriter' ),
			'translation_completed'   => __( 'Traduzione completata', 'ghostwriter' ),
			default                   => $event,
		};
	}

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

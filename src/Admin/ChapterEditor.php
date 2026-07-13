<?php
declare(strict_types=1);

namespace Ghostwriter\Admin;

use Ghostwriter\Core\PostTypes;
use Ghostwriter\Rendering\EditorProjection;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Schema\SchemaValidationException;

/**
 * Il capitolo nell'editor classico di WordPress: titolo, contenuto, media.
 *
 * - Quando la pipeline salva il formato intermedio, il post_content riceve
 *   la proiezione HTML (così l'editor mostra sempre l'ultima versione).
 * - Quando l'utente salva dall'editor, l'HTML viene riconvertito in blocchi
 *   e validato; gli id dei blocchi sopravvivono dove possibile (revisioni,
 *   traduzioni), i blocchi complessi restano intatti.
 */
final class ChapterEditor {

	private const NOTICE_TRANSIENT = 'gw_chapter_editor_notice_';

	private static bool $syncing = false;

	public function __construct(
		private ChapterRepository $chapters,
		private \Ghostwriter\Repository\ProjectRepository $projects,
		private \Ghostwriter\Domain\Dossier $dossier
	) {
	}

	public function register(): void {
		add_action( 'gw_chapter_content_saved', array( $this, 'sync_post_content' ), 10, 2 );
		add_action( 'save_post_' . PostTypes::CHAPTER, array( $this, 'on_editor_save' ), 20, 3 );
		add_action( 'edit_form_after_title', array( $this, 'render_hint' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'add_meta_boxes_' . PostTypes::CHAPTER, array( $this, 'register_meta_box' ) );
		add_action( 'media_buttons', array( $this, 'render_image_button' ) );
		add_action( 'admin_footer', array( $this, 'render_image_modal' ) );
		add_filter( 'tiny_mce_before_init', array( $this, 'style_editor' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * La superficie di scrittura somiglia alla pagina del libro: serif,
	 * giustezza da lettura, figure e citazioni come appariranno. Scrivere
	 * dentro una pagina, non dentro un form.
	 *
	 * @param array<string, mixed> $settings Config TinyMCE.
	 * @return array<string, mixed>
	 */
	public function style_editor( array $settings, string $editor_id = '' ): array {
		if ( 'content' !== $editor_id || PostTypes::CHAPTER !== ( get_current_screen()->post_type ?? '' ) ) {
			return $settings;
		}

		$css = '
			html { background: #fff; }
				body#tinymce { font-family: Georgia, serif; font-size: 18px; line-height: 1.75; color: #1d2327; max-width: 44em; margin: 0 auto !important; padding: 24px 28px !important; }
			body#tinymce p { margin: 0 0 1em; }
			body#tinymce h2, body#tinymce h3, body#tinymce h4 { font-family: Georgia, serif; line-height: 1.3; margin: 1.6em 0 0.6em; }
			body#tinymce blockquote { border-left: 3px solid #c3c4c7; margin: 1.2em 0; padding: 0.2em 0 0.2em 1.2em; font-style: italic; color: #3c434a; }
			body#tinymce blockquote cite { display: block; font-style: normal; font-size: 0.85em; color: #646970; margin-top: 0.4em; }
			body#tinymce figure { text-align: center; margin: 1.6em auto; }
			body#tinymce figure img { max-width: 100%; height: auto; }
			body#tinymce figcaption { font-size: 0.85em; font-style: italic; color: #646970; margin-top: 0.4em; }
			body#tinymce pre { background: #f6f7f7; padding: 12px 14px; border-radius: 4px; font-size: 0.85em; overflow-x: auto; }
			body#tinymce table { border-collapse: collapse; margin: 1.2em 0; }
			body#tinymce table th, body#tinymce table td { border: 1px solid #c3c4c7; padding: 0.35em 0.6em; }
			body#tinymce hr { border: none; text-align: center; margin: 2em auto; width: 30%; border-top: 1px solid #c3c4c7; }
			body#tinymce .gw-locked-block { background: #f6f7f7; border: 1px dashed #c3c4c7; border-radius: 4px; padding: 10px 14px; color: #646970; font-family: -apple-system, sans-serif; font-size: 14px; }
			body#tinymce .gw-figura-placeholder { border: 1px dashed #c3c4c7; border-radius: 4px; padding: 14px; color: #646970; }
		';

		// MAI virgolette doppie qui: _WP_Editors::_parse_init serializza i
		// valori stringa dentro doppi apici SENZA escaping — una " nel CSS
		// rompe l'oggetto di init e TinyMCE non parte (editor visuale morto).
		$css = str_replace( '"', "'", (string) preg_replace( '/\s+/', ' ', $css ) );

		$settings['content_style'] = trim( ( $settings['content_style'] ?? '' ) . ' ' . $css );
		return $settings;
	}

	public function title_placeholder( string $placeholder, \WP_Post $post ): string {
		return PostTypes::CHAPTER === $post->post_type ? __( 'Titolo del capitolo', 'ghostwriter' ) : $placeholder;
	}

	/**
	 * Pulsante "Immagine AI" accanto ad "Aggiungi media" nell'editor.
	 */
	public function render_image_button(): void {
		if ( PostTypes::CHAPTER !== ( get_current_screen()->post_type ?? '' ) ) {
			return;
		}
		if ( $this->is_manual_chapter( (int) get_the_ID() ) ) {
			return; // Libro manuale: le immagini si caricano da "Aggiungi media".
		}
		echo '<button type="button" class="button" id="gw-ai-image-open"><span class="dashicons dashicons-art" style="vertical-align:text-top"></span> '
			. esc_html__( 'Immagine AI', 'ghostwriter' ) . '</button>';
	}

	/**
	 * Modale del pulsante: prompt + dimensione nel libro. La generazione va
	 * in coda; al termine la figura viene inserita nel punto del cursore.
	 */
	public function render_image_modal(): void {
		$screen = get_current_screen();
		if ( PostTypes::CHAPTER !== ( $screen->post_type ?? '' ) || 'post' !== ( $screen->base ?? '' ) ) {
			return;
		}
		$chapter_id = (int) ( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div id="gw-ai-image-modal" style="display:none" data-gw-image-chapter="' . $chapter_id . '">'
			. '<div class="gw-modal-backdrop"></div>'
			. '<div class="gw-modal" role="dialog" aria-modal="true">'
			. '<h2>' . esc_html__( 'Genera immagine con l\'AI', 'ghostwriter' ) . '</h2>'
			. '<p class="gw-muted">' . esc_html__( 'Descrivi il soggetto, lo stile e l\'atmosfera. L\'immagine viene salvata nella Media Library e inserita nel testo come figura del libro.', 'ghostwriter' ) . '</p>'
			. '<textarea id="gw-ai-image-prompt" rows="4" style="width:100%" placeholder="' . esc_attr__( 'Es.: fotografia dall\'alto di una masseria salentina al tramonto, toni caldi, senza testo', 'ghostwriter' ) . '"></textarea>'
			. '<p><label>' . esc_html__( 'Dimensione nel libro:', 'ghostwriter' ) . ' <select id="gw-ai-image-size">'
			. '<option value="full">' . esc_html__( 'Larghezza pagina', 'ghostwriter' ) . '</option>'
			. '<option value="medium" selected>' . esc_html__( 'Media (¾ di pagina)', 'ghostwriter' ) . '</option>'
			. '<option value="small">' . esc_html__( 'Piccola (½ di pagina)', 'ghostwriter' ) . '</option>'
			. '</select></label></p>'
			. '<p class="gw-ai-image-status gw-muted" style="display:none"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>'
			. esc_html__( 'Generazione in corso (30–60 secondi): l\'immagine verrà inserita nel punto del cursore.', 'ghostwriter' ) . '</p>'
			. '<p class="gw-modal-actions">'
			. '<button type="button" class="button button-primary" id="gw-ai-image-generate">' . esc_html__( 'Genera e inserisci', 'ghostwriter' ) . '</button> '
			. '<button type="button" class="button" id="gw-ai-image-cancel">' . esc_html__( 'Annulla', 'ghostwriter' ) . '</button>'
			. '</p></div></div>';
	}

	public function register_meta_box( \WP_Post $post ): void {
		add_meta_box(
			'gw-chapter-nav',
			__( 'Nel libro', 'ghostwriter' ),
			array( $this, 'render_nav_box' ),
			PostTypes::CHAPTER,
			'side',
			'high'
		);

		// Libro manuale: nessun assistente AI, si scrive e basta.
		if ( ! $this->is_manual_chapter( $post->ID ) ) {
			add_meta_box(
				'gw-chapter-assistant',
				__( 'Assistente AI', 'ghostwriter' ),
				array( $this, 'render_assistant_box' ),
				PostTypes::CHAPTER,
				'side',
				'high'
			);

			add_meta_box(
				'gw-chapter-blocks-ai',
				__( 'Blocchi speciali (AI)', 'ghostwriter' ),
				array( $this, 'render_blocks_box' ),
				PostTypes::CHAPTER,
				'side',
				'default'
			);
		}
	}

	/**
	 * Blocchi che l'editor visuale non può generare da sé: figure senza
	 * immagine (Genera immagine) e blocchi complessi bloccati come i box di
	 * approfondimento (Riscrivi con feedback). Le richieste vanno in coda;
	 * al termine la pagina si ricarica da sola (stesso watch dell'assistente).
	 */
	public function render_blocks_box( \WP_Post $post ): void {
		$content = $this->chapters->get_content( $post->ID );
		$blocks  = (array) ( $content['blocks'] ?? array() );

		$figures = array();
		$locked  = array();
		foreach ( $blocks as $block ) {
			$type = (string) ( $block['type'] ?? '' );
			if ( 'figura' === $type && empty( $block['props']['attachment_id'] ) ) {
				$figures[] = $block;
			} elseif ( in_array( $type, array( 'box_approfondimento', 'esercizio', 'blurb' ), true ) ) {
				$locked[] = $block;
			}
		}

		if ( empty( $figures ) && empty( $locked ) ) {
			echo '<p class="gw-muted">' . esc_html__( 'Nessun blocco da generare: le figure hanno tutte un\'immagine e non ci sono box bloccati. I blocchi di testo si modificano direttamente nell\'editor.', 'ghostwriter' ) . '</p>';
			return;
		}

		echo '<div data-gw-revise-watch="' . (int) $post->ID . '">';

		foreach ( $figures as $block ) {
			$brief = (string) ( $block['props']['image_brief'] ?? '' );
			echo '<div class="gw-block-action"><p class="gw-muted" style="margin:0 0 4px">'
				. '<span class="dashicons dashicons-format-image"></span> '
				. esc_html( '' !== $brief ? wp_html_excerpt( $brief, 90, '…' ) : __( 'Figura senza descrizione', 'ghostwriter' ) ) . '</p>'
				. '<button type="button" class="button button-small button-primary" style="width:100%"'
				. ' data-gw-action="POST /chapters/' . (int) $post->ID . '/blocks/' . esc_attr( (string) ( $block['id'] ?? '' ) ) . '/image" data-gw-confirm>'
				. esc_html__( 'Genera immagine (AI)', 'ghostwriter' ) . '</button></div>';
		}

		foreach ( $locked as $block ) {
			$title = (string) ( $block['props']['title'] ?? '' );
			echo '<div class="gw-block-action"><p class="gw-muted" style="margin:0 0 4px">'
				. '<span class="dashicons dashicons-lock"></span> '
				. esc_html( self::block_type_label( (string) ( $block['type'] ?? '' ) ) . ( '' !== $title ? ': ' . wp_html_excerpt( $title, 60, '…' ) : '' ) ) . '</p>'
				. '<button type="button" class="button button-small" style="width:100%"'
				. ' data-gw-action="POST /chapters/' . (int) $post->ID . '/blocks/' . esc_attr( (string) ( $block['id'] ?? '' ) ) . '/rewrite" data-gw-prompt-feedback>'
				. esc_html__( 'Riscrivi con l\'AI…', 'ghostwriter' ) . '</button></div>';
		}

		echo '<p class="gw-muted gw-assistant-status" style="display:none"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>'
			. esc_html__( 'Lavoro in coda: la pagina si ricarica da sola al termine. Non salvare nel frattempo.', 'ghostwriter' ) . '</p>';
		echo '</div>';
	}

	private static function block_type_label( string $type ): string {
		return match ( $type ) {
			'box_approfondimento' => __( 'Box di approfondimento', 'ghostwriter' ),
			'esercizio'           => __( 'Esercizio', 'ghostwriter' ),
			'blurb'               => __( 'Blurb', 'ghostwriter' ),
			default               => $type,
		};
	}

	private function is_manual_chapter( int $chapter_id ): bool {
		$project_id = (int) get_post_meta( $chapter_id, ChapterRepository::META_PROJECT_ID, true );
		return $project_id > 0 && $this->projects->is_manual( $project_id );
	}

	/**
	 * Contesto e navigazione: posizione nel libro, capitolo precedente e
	 * successivo, stato pipeline, brief. Si scrive senza uscire dal flusso.
	 */
	public function render_nav_box( \WP_Post $post ): void {
		$project_id = (int) get_post_meta( $post->ID, ChapterRepository::META_PROJECT_ID, true );
		if ( 0 === $project_id ) {
			echo '<p class="gw-muted">' . esc_html__( 'Capitolo senza progetto.', 'ghostwriter' ) . '</p>';
			return;
		}

		$ids      = $this->projects->get_chapter_ids( $project_id );
		$position = array_search( $post->ID, $ids, true );
		$total    = count( $ids );
		$state    = (string) get_post_meta( $post->ID, \Ghostwriter\Domain\StateMachine::META_STATE, true ) ?: 'planned';

		echo '<p style="margin-top:0"><strong>' . esc_html( get_the_title( $project_id ) ) . '</strong><br/>'
			. esc_html( sprintf( /* translators: 1: posizione, 2: totale */ __( 'Capitolo %1$d di %2$d', 'ghostwriter' ), (int) $position + 1, $total ) )
			. ' · <span class="gw-state gw-state-' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span></p>';

		$brief = (string) get_post_meta( $post->ID, ChapterRepository::META_BRIEF, true );
		if ( '' !== $brief ) {
			echo '<p class="gw-muted" style="border-left:3px solid #c3c4c7;padding-left:8px">' . esc_html( $brief ) . '</p>';
		}

		$prev = ( false !== $position && $position > 0 ) ? (int) $ids[ $position - 1 ] : 0;
		$next = ( false !== $position && $position < $total - 1 ) ? (int) $ids[ $position + 1 ] : 0;

		echo '<p>';
		if ( $prev > 0 && get_edit_post_link( $prev ) ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $prev ) ) . '">← ' . esc_html( get_the_title( $prev ) ) . '</a><br/>';
		}
		if ( $next > 0 && get_edit_post_link( $next ) ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $next ) ) . '">' . esc_html( get_the_title( $next ) ) . ' →</a>';
		}
		echo '</p>';

		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . $project_id . '#capitoli' ) ) . '">' . esc_html__( 'Torna al progetto', 'ghostwriter' ) . '</a></p>';
	}

	/**
	 * Area prompt nella colonna destra: istruzioni libere all'agente per
	 * riscrivere il capitolo (e, se richiesto, il titolo). La richiesta va
	 * in coda; al termine la pagina si ricarica da sola col nuovo testo.
	 */
	public function render_assistant_box( \WP_Post $post ): void {
		$has_content = null !== $this->chapters->get_content( $post->ID );

		echo '<div class="gw-assistant" data-gw-revise-watch="' . (int) $post->ID . '">';

		if ( ! $has_content ) {
			echo '<p class="gw-muted">' . esc_html__( 'Il capitolo non ha ancora contenuto: scrivilo con "Scrivi (AI)" dal progetto, oppure inizia a scriverlo qui e salva.', 'ghostwriter' ) . '</p></div>';
			return;
		}

		echo '<p class="gw-muted" style="margin-top:0">' . esc_html__( 'Di\' all\'agente cosa cambiare: la riscrittura rispetta dossier, skills e struttura a blocchi del libro.', 'ghostwriter' ) . '</p>'
			. '<textarea id="gw-ai-feedback" rows="5" style="width:100%" placeholder="' . esc_attr__( 'Es.: accorcia l\'introduzione, aggiungi un esempio pratico sul secondo concetto, tono meno accademico…', 'ghostwriter' ) . '"></textarea>'
			. '<p><label><input type="checkbox" id="gw-ai-title"/> ' . esc_html__( 'Può riscrivere anche il titolo', 'ghostwriter' ) . '</label></p>'
			. '<p><button type="button" class="button button-primary" style="width:100%" data-gw-action="POST /chapters/' . (int) $post->ID . '/revise" data-gw-confirm'
			. ' data-gw-collect=\'{"feedback":"#gw-ai-feedback","allow_title":"#gw-ai-title"}\'>'
			. esc_html__( 'Riscrivi con l\'AI', 'ghostwriter' ) . '</button></p>'
			. '<p class="gw-muted gw-assistant-status" style="display:none"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>'
			. esc_html__( 'Riscrittura in corso: la pagina si ricarica da sola al termine. Non salvare nel frattempo.', 'ghostwriter' ) . '</p>'
			. '</div>';
	}

	/**
	 * Pipeline → editor: proiezione HTML del formato intermedio in post_content.
	 *
	 * @param array<string, mixed> $content Formato intermedio appena salvato.
	 */
	public function sync_post_content( int $chapter_id, array $content ): void {
		if ( self::$syncing ) {
			return;
		}
		self::$syncing = true;
		wp_update_post(
			array(
				'ID'           => $chapter_id,
				'post_content' => EditorProjection::to_html( $content ),
			)
		);
		self::$syncing = false;
	}

	/**
	 * Editor → pipeline: al salvataggio manuale l'HTML torna blocchi validati.
	 */
	public function on_editor_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( self::$syncing
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| 'trash' === $post->post_status ) {
			return;
		}

		// Solo i salvataggi reali dall'editor: gli update programmatici
		// (rinumerazioni, titoli dai job) non devono rifare il round-trip.
		if ( 'editpost' !== (string) ( $_POST['action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$previous = $this->chapters->get_content( $post_id ) ?? array(
			'schema_version' => '1.0',
			'chapter_id'     => $post_id,
			'blocks'         => array(),
		);

		// wpautop: l'editor classico salva i paragrafi come doppi a-capo,
		// la struttura <p> va ricostruita prima del parsing.
		$content               = EditorProjection::to_blocks( wpautop( $post->post_content ), $previous );
		$content['chapter_id'] = $post_id;

		$content['meta']               = (array) ( $content['meta'] ?? array() );
		$content['meta']['title']      = $post->post_title;
		$content['meta']['word_count'] = ChapterRepository::count_words( $content );

		self::$syncing = true;
		try {
			$this->chapters->save_content( $post_id, $content );

			// L'outline del dossier segue titolo e ordine reali dei capitoli
			// (l'"Ordine" degli attributi pagina è modificabile dall'editor).
			$project_id = $this->chapters->get_project_id( $post_id );
			if ( $project_id > 0 && null !== $this->dossier->get( $project_id ) ) {
				$this->dossier->update_outline_entry(
					$project_id,
					$post_id,
					array(
						'title'      => $post->post_title,
						'word_count' => (int) $content['meta']['word_count'],
					)
				);
				$this->dossier->sync_outline_order( $project_id, $this->projects->get_chapter_ids( $project_id ) );
			}
		} catch ( SchemaValidationException $e ) {
			set_transient(
				self::NOTICE_TRANSIENT . get_current_user_id(),
				sprintf(
					/* translators: %s: dettaglio errore */
					__( 'Il contenuto salvato non è riconvertibile nei blocchi Ghostwriter: %s. Il testo è stato conservato nell\'editor ma l\'export userà l\'ultima versione valida.', 'ghostwriter' ),
					$e->getMessage()
				),
				60
			);
		} finally {
			self::$syncing = false;
		}
	}

	public function render_hint( \WP_Post $post ): void {
		if ( PostTypes::CHAPTER !== $post->post_type ) {
			return;
		}
		$project_id = (int) get_post_meta( $post->ID, ChapterRepository::META_PROJECT_ID, true );
		echo '<div class="notice notice-info inline" style="margin:12px 0 0"><p>'
			. esc_html__( 'Questo è un capitolo Ghostwriter: al salvataggio il testo viene riconvertito nei blocchi del libro (paragrafi, titoli, elenchi, citazioni, immagini, tabelle). I riquadri contrassegnati come "blocco strutturato" si modificano dalla scheda Capitoli del progetto.', 'ghostwriter' )
			. ( $project_id > 0 ? ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PROJECTS . '&project=' . $project_id ) ) . '">' . esc_html__( 'Torna al progetto', 'ghostwriter' ) . '</a>' : '' )
			. '</p></div>';
	}

	public function render_notice(): void {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error"><p><strong>Ghostwriter:</strong> ' . esc_html( $message ) . '</p></div>';
	}
}

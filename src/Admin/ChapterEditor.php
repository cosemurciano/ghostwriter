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

	public function __construct( private ChapterRepository $chapters ) {
	}

	public function register(): void {
		add_action( 'gw_chapter_content_saved', array( $this, 'sync_post_content' ), 10, 2 );
		add_action( 'save_post_' . PostTypes::CHAPTER, array( $this, 'on_editor_save' ), 20, 3 );
		add_action( 'edit_form_after_title', array( $this, 'render_hint' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
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

		$previous = $this->chapters->get_content( $post_id ) ?? array(
			'schema_version' => '1.0',
			'chapter_id'     => $post_id,
			'blocks'         => array(),
		);

		// wpautop: l'editor classico salva i paragrafi come doppi a-capo,
		// la struttura <p> va ricostruita prima del parsing.
		$content               = EditorProjection::to_blocks( wpautop( $post->post_content ), $previous );
		$content['chapter_id'] = $post_id;

		$content['meta']          = (array) ( $content['meta'] ?? array() );
		$content['meta']['title'] = $post->post_title;

		self::$syncing = true;
		try {
			$this->chapters->save_content( $post_id, $content );
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

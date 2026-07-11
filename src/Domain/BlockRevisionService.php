<?php
declare(strict_types=1);

namespace Ghostwriter\Domain;

use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;

/**
 * Versioning dei blocchi: unico punto autorizzato a modificare un blocco.
 *
 * Regole (ARCHITECTURE.md §5.1):
 * - l'id del blocco è stabile per sempre, version si incrementa a ogni modifica;
 * - ogni modifica archivia PRIMA la versione corrente in gw_block_revisions;
 * - la riscrittura non può cambiare il type del blocco;
 * - il ripristino crea una NUOVA versione (origin: restore) — storia lineare;
 * - anche le modifiche manuali passano da qui (origin: manual_edit);
 * - il feedback dell'utente è conservato con la revisione archiviata.
 *
 * La provenienza della versione corrente è tracciata nel blocco stesso
 * (chiave interna "origin" nel formato intermedio, default ai_draft).
 */
final class BlockRevisionService {

	public const ORIGIN_AI_DRAFT    = 'ai_draft';
	public const ORIGIN_AI_REVIEW   = 'ai_review';
	public const ORIGIN_AI_REWRITE  = 'ai_rewrite';
	public const ORIGIN_MANUAL_EDIT = 'manual_edit';
	public const ORIGIN_RESTORE     = 'restore';

	/** Stati capitolo in cui la riscrittura è ammessa (da draft_ready a complete). */
	private const REWRITABLE_STATES = array( 'draft_ready', 'in_review', 'revised', 'images_pending', 'complete' );

	public function __construct(
		private ChapterRepository $chapters,
		private LogRepository $log
	) {
	}

	/**
	 * Richiede la riscrittura AI di un blocco: valida lo stato del capitolo e
	 * pubblica l'evento che accoda il RewriteBlockJob (fase 3: coda).
	 * La riscrittura non regredisce lo stato del capitolo.
	 *
	 * @throws \RuntimeException Se lo stato del capitolo non ammette riscritture o il blocco non esiste.
	 */
	public function request_rewrite( int $chapter_id, string $block_id, string $feedback, int $user_id, bool $refresh_synopsis = false ): void {
		$state = (string) get_post_meta( $chapter_id, StateMachine::META_STATE, true );
		if ( ! in_array( $state, self::REWRITABLE_STATES, true ) ) {
			throw new \RuntimeException( "Riscrittura non ammessa nello stato capitolo \"{$state}\"." );
		}

		if ( null === $this->chapters->find_block( $chapter_id, $block_id ) ) {
			throw new \RuntimeException( "Blocco {$block_id} non trovato nel capitolo {$chapter_id}." );
		}

		$this->log->log(
			$this->chapters->get_project_id( $chapter_id ),
			$chapter_id,
			'info',
			'block_rewrite_requested',
			array(
				'block_id'         => $block_id,
				'feedback'         => $feedback,
				'user_id'          => $user_id,
				'refresh_synopsis' => $refresh_synopsis,
			)
		);

		/**
		 * Il listener (Queue\Dispatcher, fase 3) accoda RewriteBlockJob con dedup key.
		 */
		do_action( 'gw_block_rewrite_requested', $chapter_id, $block_id, $feedback, $user_id, $refresh_synopsis );
	}

	/**
	 * Scrive una nuova versione di un blocco: archivia la corrente, poi
	 * sostituisce nel formato intermedio con version incrementata.
	 *
	 * @param array<string, mixed> $new_block      Il blocco riscritto (stesso id, stesso type).
	 * @param string               $origin         Provenienza della NUOVA versione (ai_rewrite|ai_review|manual_edit|restore).
	 * @param string|null          $feedback       Feedback utente che ha motivato la sostituzione (conservato con la revisione archiviata).
	 * @param array|null           $generated_with Tracciabilità modello/skills per origini ai_*.
	 *
	 * @return array<string, mixed> Il blocco salvato (con version aggiornata).
	 * @throws \RuntimeException Se il blocco non esiste o il type cambia.
	 */
	public function write_new_version(
		int $chapter_id,
		string $block_id,
		array $new_block,
		string $origin,
		?string $feedback = null,
		?array $generated_with = null,
		?int $author_user_id = null,
		?int $restored_from_version = null
	): array {
		$current = $this->chapters->find_block( $chapter_id, $block_id );
		if ( null === $current ) {
			throw new \RuntimeException( "Blocco {$block_id} non trovato nel capitolo {$chapter_id}." );
		}

		if ( ( $new_block['type'] ?? '' ) !== $current['type'] ) {
			throw new \RuntimeException(
				'La riscrittura non può cambiare il type del blocco: per trasformarlo si elimina e si crea (scelta editoriale esplicita).'
			);
		}

		$current_version = (int) ( $current['version'] ?? 1 );

		$this->archive( $chapter_id, $current, $feedback );

		$new_block['id']      = $block_id;
		$new_block['version'] = $current_version + 1;
		$new_block['origin']  = $origin;
		if ( null !== $restored_from_version ) {
			$new_block['restored_from_version'] = $restored_from_version;
		} else {
			unset( $new_block['restored_from_version'] );
		}
		if ( null !== $generated_with ) {
			$new_block['generated_with'] = $generated_with;
		}
		if ( null !== $author_user_id ) {
			$new_block['author_user_id'] = $author_user_id;
		}

		$this->chapters->replace_block( $chapter_id, $new_block );

		$this->log->log(
			$this->chapters->get_project_id( $chapter_id ),
			$chapter_id,
			'info',
			'block_version_written',
			array(
				'block_id' => $block_id,
				'version'  => $new_block['version'],
				'origin'   => $origin,
			)
		);

		return $new_block;
	}

	/**
	 * Modifica manuale dall'editor: passa dallo stesso meccanismo così la
	 * cronologia è completa a prescindere da chi modifica.
	 *
	 * @param array<string, mixed> $new_block Blocco modificato.
	 */
	public function manual_edit( int $chapter_id, string $block_id, array $new_block, int $user_id ): array {
		return $this->write_new_version( $chapter_id, $block_id, $new_block, self::ORIGIN_MANUAL_EDIT, null, null, $user_id );
	}

	/**
	 * Ripristina una versione precedente creando una NUOVA versione con
	 * contenuto identico: la storia resta lineare, mai riscritta.
	 */
	public function restore( int $chapter_id, string $block_id, int $version, int $user_id ): array {
		$revision = $this->get_revision( $block_id, $version );
		if ( null === $revision ) {
			throw new \RuntimeException( "Versione {$version} del blocco {$block_id} non trovata." );
		}

		$block = $revision['block'];

		return $this->write_new_version(
			$chapter_id,
			$block_id,
			$block,
			self::ORIGIN_RESTORE,
			null,
			null,
			$user_id,
			$version
		);
	}

	/**
	 * Cronologia completa di un blocco (snapshot + feedback), versione più recente per prima.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function history( string $block_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}gw_block_revisions WHERE block_id = %s ORDER BY version DESC",
				$block_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate_row' ), $rows ?: array() );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_revision( string $block_id, int $version ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}gw_block_revisions WHERE block_id = %s AND version = %d",
				$block_id,
				$version
			),
			ARRAY_A
		);

		return $row ? self::hydrate_row( $row ) : null;
	}

	/**
	 * Archivia lo snapshot della versione corrente. Idempotente: se la coppia
	 * (block_id, version) è già archiviata non duplica (i job possono ripartire).
	 *
	 * @param array<string, mixed> $block    Blocco corrente (snapshot completo).
	 * @param string|null          $feedback Feedback che ha motivato la sostituzione.
	 */
	private function archive( int $chapter_id, array $block, ?string $feedback ): void {
		global $wpdb;

		$version = (int) ( $block['version'] ?? 1 );

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}gw_block_revisions WHERE block_id = %s AND version = %d",
				$block['id'],
				$version
			)
		);
		if ( $exists ) {
			return;
		}

		// I campi di tracciabilità interna escono dallo snapshot e diventano colonne.
		$origin         = $block['origin'] ?? self::ORIGIN_AI_DRAFT;
		$generated_with = $block['generated_with'] ?? null;
		$author_user_id = $block['author_user_id'] ?? null;
		$restored_from  = $block['restored_from_version'] ?? null;
		unset( $block['origin'], $block['generated_with'], $block['author_user_id'], $block['restored_from_version'] );

		$wpdb->insert(
			$wpdb->prefix . 'gw_block_revisions',
			array(
				'chapter_id'            => $chapter_id,
				'block_id'              => $block['id'],
				'version'               => $version,
				'origin'                => $origin,
				'block'                 => wp_json_encode( $block ),
				'feedback'              => $feedback,
				'restored_from_version' => $restored_from,
				'generated_with'        => $generated_with ? wp_json_encode( $generated_with ) : null,
				'author_user_id'        => $author_user_id,
				'created_at'            => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * @param array<string, string|null> $row Riga della tabella.
	 * @return array<string, mixed>
	 */
	private static function hydrate_row( array $row ): array {
		return array(
			'block_id'              => $row['block_id'],
			'chapter_id'            => (int) $row['chapter_id'],
			'version'               => (int) $row['version'],
			'origin'                => $row['origin'],
			'block'                 => json_decode( (string) $row['block'], true ),
			'feedback'              => $row['feedback'],
			'restored_from_version' => null !== $row['restored_from_version'] ? (int) $row['restored_from_version'] : null,
			'generated_with'        => $row['generated_with'] ? json_decode( (string) $row['generated_with'], true ) : null,
			'author_user_id'        => null !== $row['author_user_id'] ? (int) $row['author_user_id'] : null,
			'created_at'            => $row['created_at'],
		);
	}
}

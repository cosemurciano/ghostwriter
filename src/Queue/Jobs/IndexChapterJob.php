<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\LocalRagService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Rendering\RichText;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Indicizza il capitolo completato nel vector store del progetto
 * (se sources.index_chapters_in_vector_store è attivo): i capitoli
 * successivi possono richiamarne passaggi puntuali oltre la sinossi.
 */
final class IndexChapterJob implements JobInterface {

	public function __construct(
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private LocalRagService $rag,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'index_chapter';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );

		$config = $this->projects->get_config( $project_id );
		if ( ! ( $config['sources']['index_chapters_in_vector_store'] ?? true ) ) {
			return;
		}

		$content = $this->chapters->get_content( $chapter_id );
		if ( null === $content ) {
			return;
		}

		$text = self::plain_text( $content );
		if ( '' === $text ) {
			return;
		}

		// L'ingestione è replace: rieseguire il job (o reindicizzare dopo
		// riscritture) non duplica frammenti.
		$chunk_count = $this->rag->ingest_chapter( $project_id, $chapter_id, $text );

		$this->log->log( $project_id, $chapter_id, LogRepository::LEVEL_INFO, 'chapter_indexed', array( 'chunks' => $chunk_count ) );
	}

	/**
	 * Testo semplice del capitolo dal formato intermedio (per l'indice).
	 *
	 * @param array<string, mixed> $content Formato intermedio.
	 */
	public static function plain_text( array $content ): string {
		$parts = array();
		if ( ! empty( $content['meta']['title'] ) ) {
			$parts[] = (string) $content['meta']['title'];
		}
		self::collect_text( $content['blocks'] ?? array(), $parts );
		return trim( implode( "\n", $parts ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi (anche annidati).
	 * @param string[]                         $parts  Accumulatore.
	 */
	private static function collect_text( array $blocks, array &$parts ): void {
		foreach ( $blocks as $block ) {
			$props = (array) ( $block['props'] ?? array() );
			foreach ( array( 'text', 'title', 'caption', 'code' ) as $key ) {
				if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) ) {
					$parts[] = RichText::to_plain( $props[ $key ] );
				}
			}
			foreach ( (array) ( $props['items'] ?? array() ) as $item ) {
				if ( is_string( $item ) ) {
					$parts[] = RichText::to_plain( $item );
				}
			}
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] ) ) {
				self::collect_text( $props['blocks'], $parts );
			}
		}
	}

	public function on_failure( array $args, \Throwable $e ): void {
		// L'indicizzazione non blocca la pipeline: solo log.
		$this->log->log(
			(int) ( $args['project_id'] ?? 0 ),
			(int) ( $args['chapter_id'] ?? 0 ),
			LogRepository::LEVEL_WARNING,
			'chapter_index_failed',
			array( 'error' => $e->getMessage() )
		);
	}
}

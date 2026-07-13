<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\ImageRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Genera l'immagine di un blocco figura: image_brief → provider →
 * Media Library → attachment_id nel blocco. Le immagini sono l'unica fase
 * parallelizzabile della pipeline: quando l'ultima figura del capitolo è
 * risolta, il capitolo passa a complete.
 */
final class GenerateImageJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private ImageService $images,
		private StateMachine $states,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'generate_image';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$block_id   = (string) ( $args['block_id'] ?? '' );
		$project_id = $this->chapters->get_project_id( $chapter_id );

		$block = $this->chapters->find_block( $chapter_id, $block_id );
		if ( null === $block || 'figura' !== ( $block['type'] ?? '' ) ) {
			throw new \RuntimeException( "Blocco figura {$block_id} non trovato nel capitolo {$chapter_id}." );
		}

		// Idempotenza: figura già risolta.
		if ( ! empty( $block['props']['attachment_id'] ) ) {
			$this->maybe_complete_chapter( $chapter_id );
			return;
		}

		$brief = (string) ( $block['props']['image_brief'] ?? '' );
		if ( '' === $brief ) {
			throw new \RuntimeException( "Blocco figura {$block_id} senza image_brief." );
		}

		$config = $this->projects->get_config( $project_id );
		[$width, $height] = ImageService::target_resolution( $config, (string) ( $block['props']['size'] ?? 'medium' ) );

		$image = $this->provider->generate_image(
			new ImageRequest( $brief, $width, $height, $project_id, $chapter_id, $block_id )
		);

		$attachment_id = $this->images->save_to_media_library(
			$image,
			$project_id,
			$block_id,
			(string) ( $block['props']['alt'] ?? '' )
		);

		// La risoluzione del placeholder non è una revisione editoriale:
		// si aggiorna il blocco in place (version invariata) e si logga.
		$block['props']['attachment_id'] = $attachment_id;
		$this->chapters->replace_block( $chapter_id, $block );

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['image_provider'] ?? $config['ai']['provider'] ?? 'mock' ),
			$image->model,
			0,
			0,
			1
		);

		$this->log->log( $project_id, $chapter_id, LogRepository::LEVEL_INFO, 'image_generated', array( 'block_id' => $block_id, 'attachment_id' => $attachment_id ) );

		$this->maybe_complete_chapter( $chapter_id );
	}

	/**
	 * Se non restano figure irrisolte e il capitolo è in images_pending,
	 * completa. Guardato da can(): i job immagine girano in parallelo e
	 * solo l'ultimo esegue la transizione.
	 */
	private function maybe_complete_chapter( int $chapter_id ): void {
		if ( 'images_pending' !== $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ) ) {
			return;
		}

		$content = $this->chapters->get_content( $chapter_id );
		if ( null === $content || self::has_unresolved_figures( $content['blocks'] ?? array() ) ) {
			return;
		}

		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, 'images_pending', 'completed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'completed', array( 'job' => self::name() ) );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi (anche annidati).
	 */
	public static function has_unresolved_figures( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$block = (array) $block;
			// Le props vuote possono essere stdClass (normalizzazione schema):
			// il cast evita il fatal "Cannot use object of type stdClass as array".
			$props = (array) ( $block['props'] ?? array() );
			if ( 'figura' === ( $block['type'] ?? '' ) && empty( $props['attachment_id'] ) ) {
				return true;
			}
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] )
				&& self::has_unresolved_figures( $props['blocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[] Gli id dei blocchi figura irrisolti (per l'accodamento).
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocchi (anche annidati).
	 */
	public static function unresolved_figure_ids( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			$block = (array) $block;
			$props = (array) ( $block['props'] ?? array() );
			if ( 'figura' === ( $block['type'] ?? '' ) && empty( $props['attachment_id'] ) && ! empty( $block['id'] ) ) {
				$ids[] = (string) $block['id'];
			}
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] ) ) {
				$ids = array_merge( $ids, self::unresolved_figure_ids( $props['blocks'] ) );
			}
		}
		return $ids;
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ), 'failed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'failed', array( 'job' => self::name(), 'error' => $e->getMessage(), 'block_id' => $args['block_id'] ?? '' ) );
		}
	}
}

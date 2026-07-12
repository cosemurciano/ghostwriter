<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\ImageRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Immagine generata dal pulsante "Immagine AI" nell'editor del capitolo:
 * prompt libero dell'utente → provider immagini → Media Library. L'esito
 * finisce in un transient che l'editor interroga in polling per inserire
 * la figura nel testo.
 */
final class GenerateEditorImageJob implements JobInterface {

	public const TRANSIENT_PREFIX = 'gw_editor_image_';

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private ImageService $images,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'editor_image';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );
		$request_id = (string) ( $args['request_id'] ?? '' );
		$prompt     = trim( (string) ( $args['prompt'] ?? '' ) );
		$size       = in_array( $args['size'] ?? '', array( 'small', 'medium', 'full' ), true ) ? (string) $args['size'] : 'medium';

		if ( 0 === $project_id || '' === $request_id || '' === $prompt ) {
			throw new \RuntimeException( 'Richiesta immagine incompleta (prompt/request_id).' );
		}

		$config             = $this->projects->get_config( $project_id );
		[ $width, $height ] = ImageService::target_resolution( $config, $size );

		$image = $this->provider->generate_image(
			new ImageRequest( $prompt, $width, $height, $project_id, $chapter_id, 'editor-' . $request_id )
		);

		$attachment_id = $this->images->save_to_media_library(
			$image,
			$project_id,
			'editor-' . $request_id,
			wp_trim_words( $prompt, 20, '' )
		);

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['image_provider'] ?? $config['ai']['provider'] ?? 'mock' ),
			$image->model,
			0,
			0,
			1
		);

		set_transient( self::TRANSIENT_PREFIX . $request_id, array( 'attachment_id' => $attachment_id ), HOUR_IN_SECONDS );

		$this->log->log( $project_id, $chapter_id, LogRepository::LEVEL_INFO, 'image_generated', array( 'source' => 'editor', 'attachment_id' => $attachment_id ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$request_id = (string) ( $args['request_id'] ?? '' );
		if ( '' !== $request_id ) {
			set_transient( self::TRANSIENT_PREFIX . $request_id, array( 'error' => $e->getMessage() ), HOUR_IN_SECONDS );
		}

		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$this->log->log(
			$this->chapters->get_project_id( $chapter_id ),
			$chapter_id,
			LogRepository::LEVEL_ERROR,
			'editor_image_failed',
			array( 'error' => $e->getMessage() )
		);
	}
}

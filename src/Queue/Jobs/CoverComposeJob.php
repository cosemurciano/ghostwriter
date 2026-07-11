<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\ImageResult;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Media\CoverComposer;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Composizione locale (no AI): tipografia sopra l'artwork → PNG in Media
 * Library → composed_attachment_id in config. Approvazione umana a valle.
 */
final class CoverComposeJob implements JobInterface {

	public function __construct(
		private ProjectRepository $projects,
		private CoverComposer $composer,
		private ImageService $images,
		private StateMachine $states,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'cover_compose';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );

		if ( 'artwork_ready' !== $this->states->state_of( $project_id, StateMachine::TYPE_COVER ) ) {
			return;
		}

		$config = $this->projects->get_config( $project_id );
		$cover  = (array) ( $config['cover'] ?? array() );

		$artwork_path = null;
		if ( ! empty( $cover['front_artwork_attachment_id'] ) ) {
			$path = get_attached_file( (int) $cover['front_artwork_attachment_id'] );
			if ( is_string( $path ) && file_exists( $path ) ) {
				$artwork_path = $path;
			}
		}

		[$width, $height] = CoverArtworkJob::cover_resolution( $config );

		$png = $this->composer->compose(
			$artwork_path,
			(string) get_the_title( $project_id ),
			null,
			$this->author_name( $project_id ),
			$width,
			$height
		);

		$attachment_id = $this->images->save_to_media_library(
			new ImageResult( $png, 'image/png', 'cover-composer' ),
			$project_id,
			'cover-composed',
			get_the_title( $project_id )
		);

		$config['cover']                           = $cover;
		$config['cover']['composed_attachment_id'] = $attachment_id;
		$this->projects->save_config( $project_id, $config );

		$this->states->transition( $project_id, StateMachine::TYPE_COVER, 'composed', array( 'job' => self::name(), 'attachment_id' => $attachment_id ) );
	}

	private function author_name( int $project_id ): string {
		$post = get_post( $project_id );
		if ( null === $post || ! $post->post_author ) {
			return '';
		}
		return (string) get_the_author_meta( 'display_name', (int) $post->post_author );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'cover_compose_failed', array( 'error' => $e->getMessage() ) );
	}
}

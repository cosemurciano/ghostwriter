<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\ImageRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Artwork di copertina: SEMPRE senza testo (la tipografia la compone il
 * plugin). In modalità upload usa l'attachment già indicato in config.
 */
final class CoverArtworkJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ImageService $images,
		private StateMachine $states,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'cover_artwork';
	}

	/**
	 * Risoluzione della copertina dal formato fisico (con abbondanza se print_ready).
	 *
	 * @param array<string, mixed> $config Config progetto.
	 * @return array{0: int, 1: int}
	 */
	public static function cover_resolution( array $config ): array {
		$format = (array) ( $config['format'] ?? array() );
		$print  = ! empty( $format['print_ready'] );
		$dpi    = $print ? 300 : 150;
		$bleed  = $print ? 2 * (float) ( $format['bleed_mm'] ?? 3 ) : 0;

		$width  = ( (float) ( $format['trim_width_mm'] ?? 150 ) + $bleed ) / 25.4 * $dpi;
		$height = ( (float) ( $format['trim_height_mm'] ?? 230 ) + $bleed ) / 25.4 * $dpi;

		return array( (int) round( $width ), (int) round( $height ) );
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );

		if ( 'brief_ready' !== $this->states->state_of( $project_id, StateMachine::TYPE_COVER ) ) {
			return;
		}

		$config = $this->projects->get_config( $project_id );
		$cover  = (array) ( $config['cover'] ?? array() );

		// Modalità upload: l'artwork è già in Media Library.
		if ( 'upload' === ( $cover['mode'] ?? 'ai_generated' ) ) {
			if ( empty( $cover['front_artwork_attachment_id'] ) ) {
				throw new \RuntimeException( 'Modalità upload senza front_artwork_attachment_id: caricare l\'artwork.' );
			}
			$this->states->transition( $project_id, StateMachine::TYPE_COVER, 'artwork_ready', array( 'job' => self::name(), 'mode' => 'upload' ) );
			return;
		}

		$brief = (string) ( $cover['creative_brief'] ?? '' );
		if ( '' === $brief ) {
			throw new \RuntimeException( 'Brief di copertina assente.' );
		}

		[$width, $height] = self::cover_resolution( $config );

		$image = $this->provider->generate_image(
			new ImageRequest(
				$brief . ' — Nessun testo, titolo o lettering nell\'immagine.',
				$width,
				$height,
				$project_id
			)
		);

		$attachment_id = $this->images->save_to_media_library( $image, $project_id, 'cover-front', get_the_title( $project_id ) );

		$config['cover']                                = $cover;
		$config['cover']['front_artwork_attachment_id'] = $attachment_id;
		$this->projects->save_config( $project_id, $config );

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['image_provider'] ?? $config['ai']['provider'] ?? 'mock' ),
			$image->model,
			0,
			0,
			1
		);

		$this->states->transition( $project_id, StateMachine::TYPE_COVER, 'artwork_ready', array( 'job' => self::name(), 'attachment_id' => $attachment_id ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'cover_artwork_failed', array( 'error' => $e->getMessage() ) );
	}
}

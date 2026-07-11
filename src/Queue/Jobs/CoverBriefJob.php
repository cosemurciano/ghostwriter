<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;

/**
 * Brief creativo della copertina dal dossier (l'utente può modificarlo
 * prima dell'artwork). In modalità upload il brief AI non serve: si passa
 * direttamente a brief_ready.
 */
final class CoverBriefJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private StateMachine $states,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'cover_brief';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );

		// Idempotenza: la copertina è già oltre il brief.
		if ( 'pending' !== $this->states->state_of( $project_id, StateMachine::TYPE_COVER ) ) {
			return;
		}

		$config = $this->projects->get_config( $project_id );
		$mode   = (string) ( $config['cover']['mode'] ?? 'ai_generated' );

		if ( 'ai_generated' === $mode && empty( $config['cover']['creative_brief'] ) ) {
			$result = $this->provider->complete(
				new AiRequest(
					AiRequest::PHASE_COVER,
					array( 'dossier' => $this->projects->get_dossier( $project_id ) ?? array() ),
					$project_id
				)
			);

			$brief = trim( (string) ( $result->content['creative_brief'] ?? '' ) );
			if ( '' === $brief ) {
				throw new \RuntimeException( 'Il provider non ha prodotto un brief di copertina.' );
			}

			$config['cover']                   = (array) ( $config['cover'] ?? array() );
			$config['cover']['creative_brief'] = $brief;
			$this->projects->save_config( $project_id, $config );

			$this->usage->record(
				$project_id,
				self::name(),
				(string) ( $config['ai']['provider'] ?? 'mock' ),
				$result->model,
				$result->input_tokens,
				$result->output_tokens
			);
		}

		$this->states->transition( $project_id, StateMachine::TYPE_COVER, 'brief_ready', array( 'job' => self::name(), 'mode' => $mode ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'cover_brief_failed', array( 'error' => $e->getMessage() ) );
	}
}

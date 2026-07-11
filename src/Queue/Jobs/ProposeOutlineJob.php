<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Domain\Dossier;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Ai\UsageMeter;

/**
 * Chiama l'AI (fase outline) con il brief e scrive l'outline proposto nel
 * dossier; stato progetto → outline_proposed.
 *
 * Prima dell'approvazione i capitoli non esistono ancora: le voci di outline
 * usano chapter_id temporanei negativi (-1, -2, ...), sostituiti con gli ID
 * reali da MaterializeChaptersJob.
 */
final class ProposeOutlineJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private Dossier $dossier,
		private StateMachine $states,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'propose_outline';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );
		if ( ! $this->projects->exists( $project_id ) ) {
			throw new \RuntimeException( "Progetto {$project_id} inesistente." );
		}

		// Idempotenza: outline già proposto e presente nel dossier.
		$existing = $this->dossier->get( $project_id );
		if ( 'outline_proposed' === $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT )
			&& ! empty( $existing['outline'] ) ) {
			return;
		}

		$config = $this->projects->get_config( $project_id );

		$result   = $this->provider->complete(
			new AiRequest(
				AiRequest::PHASE_OUTLINE,
				array( 'brief' => $config['brief'] ?? array() ),
				$project_id
			)
		);
		$chapters = $result->content['chapters'] ?? array();
		if ( empty( $chapters ) || ! is_array( $chapters ) ) {
			throw new \RuntimeException( 'Il provider non ha proposto capitoli.' );
		}

		if ( null === $existing ) {
			$this->dossier->initialize( $project_id, $config );
		}

		$this->dossier->update(
			$project_id,
			static function ( array $dossier ) use ( $chapters ): array {
				$dossier['outline'] = array();
				foreach ( array_values( $chapters ) as $i => $chapter ) {
					$dossier['outline'][] = array(
						'chapter_id'      => -( $i + 1 ), // Temporaneo: reale dopo l'approvazione.
						'parent_id'       => null,
						'order'           => $i,
						'title'           => (string) ( $chapter['title'] ?? 'Senza titolo' ),
						'brief'           => (string) ( $chapter['brief'] ?? '' ),
						'planned_sources' => array_map( 'strval', (array) ( $chapter['planned_sources'] ?? array() ) ),
						'status'          => 'planned',
						'synopsis'        => null,
					);
				}
				return $dossier;
			}
		);

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		$this->states->transition( $project_id, StateMachine::TYPE_PROJECT, 'outline_proposed', array( 'job' => self::name() ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		// Il progetto resta nello stato corrente: la ri-proposta è manuale.
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'outline_proposal_failed', array( 'error' => $e->getMessage() ) );
	}
}

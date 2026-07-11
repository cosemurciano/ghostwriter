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
use Ghostwriter\Translation\GlossaryService;

/**
 * Propone il glossario del progetto di traduzione: terminologia estratta
 * dal dossier sorgente → rese proposte dall'AI → dossier del derivato;
 * stato → glossary_proposed (checkpoint umano obbligatorio).
 */
final class ProposeGlossaryJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private GlossaryService $glossary,
		private StateMachine $states,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'propose_glossary';
	}

	public function handle( array $args ): void {
		$project_id = (int) ( $args['project_id'] ?? 0 );

		$config = $this->projects->get_config( $project_id );
		$source = (int) ( $config['derived_from'] ?? 0 );
		if ( 0 === $source ) {
			throw new \RuntimeException( "Il progetto {$project_id} non è una traduzione." );
		}

		// Idempotenza: glossario già proposto.
		if ( 'glossary_proposed' === $this->states->state_of( $project_id, StateMachine::TYPE_TRANSLATION )
			&& ! empty( $this->glossary->get( $project_id ) ) ) {
			return;
		}

		$source_config  = $this->projects->get_config( $source );
		$source_dossier = $this->projects->get_dossier( $source ) ?? array();

		$result = $this->provider->complete(
			new AiRequest(
				AiRequest::PHASE_GLOSSARY,
				array(
					'candidate_terms' => GlossaryService::candidate_terms( $source_dossier ),
					'source_language' => (string) ( $source_config['language'] ?? '' ),
					'target_language' => (string) ( $config['language'] ?? '' ),
					'dossier'         => $source_dossier,
				),
				$project_id
			)
		);

		$glossary = (array) ( $result->content['glossary'] ?? array() );
		if ( empty( $glossary ) ) {
			throw new \RuntimeException( 'Il provider non ha proposto voci di glossario.' );
		}

		$this->glossary->put( $project_id, $glossary );

		$this->usage->record(
			$project_id,
			self::name(),
			(string) ( $config['ai']['provider'] ?? 'mock' ),
			$result->model,
			$result->input_tokens,
			$result->output_tokens
		);

		$this->states->transition( $project_id, StateMachine::TYPE_TRANSLATION, 'glossary_proposed', array( 'job' => self::name() ) );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'glossary_proposal_failed', array( 'error' => $e->getMessage() ) );
	}
}

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

		$regenerate = ! empty( $args['regenerate'] );

		// Idempotenza: outline già proposto e presente nel dossier. La
		// rigenerazione esplicita bypassa il guard: ri-proporre È l'intento.
		$existing = $this->dossier->get( $project_id );
		if ( ! $regenerate
			&& 'outline_proposed' === $this->states->state_of( $project_id, StateMachine::TYPE_PROJECT )
			&& ! empty( $existing['outline'] ) ) {
			return;
		}

		$config  = $this->projects->get_config( $project_id );
		$current = array_values( (array) ( $existing['outline'] ?? array() ) );
		$keep    = array_values( array_map( 'intval', (array) ( $args['keep'] ?? array() ) ) );

		$context = array( 'brief' => $config['brief'] ?? array() );
		if ( $regenerate && ! empty( $current ) ) {
			$context['current_outline'] = array();
			foreach ( $current as $i => $entry ) {
				$context['current_outline'][] = array(
					'position' => $i + 1,
					'title'    => (string) ( $entry['title'] ?? '' ),
					'brief'    => (string) ( $entry['brief'] ?? '' ),
					'locked'   => in_array( $i, $keep, true ),
				);
			}
			if ( ! empty( $args['feedback'] ) ) {
				$context['feedback'] = (string) $args['feedback'];
			}
		}

		$result   = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_OUTLINE, $context, $project_id )
		);
		$chapters = $result->content['chapters'] ?? array();
		if ( empty( $chapters ) || ! is_array( $chapters ) ) {
			throw new \RuntimeException( 'Il provider non ha proposto capitoli.' );
		}

		if ( $regenerate ) {
			$chapters = self::enforce_locked( array_values( $chapters ), $current, $keep );
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

	/**
	 * Garanzia deterministica sui capitoli bloccati: qualunque cosa proponga
	 * l'AI, le voci "Mantieni" tornano nell'indice con titolo e brief
	 * originali. Match per titolo; se l'AI le ha rimosse, vengono reinserite
	 * alla loro posizione originale.
	 *
	 * @param array<int, array<string, mixed>> $proposed Capitoli proposti dall'AI.
	 * @param array<int, array<string, mixed>> $current  Outline attuale nel dossier.
	 * @param int[]                            $keep     Indici (0-based) da non modificare.
	 * @return array<int, array<string, mixed>>
	 */
	private static function enforce_locked( array $proposed, array $current, array $keep ): array {
		sort( $keep );
		$normalize = static fn( string $title ): string => mb_strtolower( trim( $title ) );

		foreach ( $keep as $index ) {
			if ( ! isset( $current[ $index ] ) ) {
				continue;
			}
			$locked = array(
				'title'           => (string) ( $current[ $index ]['title'] ?? '' ),
				'brief'           => (string) ( $current[ $index ]['brief'] ?? '' ),
				'planned_sources' => (array) ( $current[ $index ]['planned_sources'] ?? array() ),
			);

			$found = null;
			foreach ( $proposed as $i => $chapter ) {
				if ( $normalize( (string) ( $chapter['title'] ?? '' ) ) === $normalize( $locked['title'] ) ) {
					$found = $i;
					break;
				}
			}

			if ( null !== $found ) {
				$proposed[ $found ] = $locked;
			} else {
				array_splice( $proposed, min( $index, count( $proposed ) ), 0, array( $locked ) );
			}
		}

		return array_values( $proposed );
	}

	public function on_failure( array $args, \Throwable $e ): void {
		// Il progetto resta nello stato corrente: la ri-proposta è manuale.
		$this->log->log( (int) ( $args['project_id'] ?? 0 ), null, LogRepository::LEVEL_ERROR, 'outline_proposal_failed', array( 'error' => $e->getMessage() ) );
	}
}

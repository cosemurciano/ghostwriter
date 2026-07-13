<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Repository\UsageRepository;

/**
 * Contatore token/costi/immagini per progetto e budget cap (§4, §6).
 *
 * Ogni chiamata AI registra i consumi qui (non su UsageRepository
 * direttamente): al superamento del budget il meter lancia l'evento
 * budget_exceeded, il progetto va in paused_budget e il PipelineRouter
 * sospende l'accodamento. Mai job silenziosamente costosi.
 */
final class UsageMeter {

	public function __construct(
		private UsageRepository $usage,
		private ProjectRepository $projects,
		private StateMachine $states
	) {
	}

	/**
	 * Registra i consumi di una chiamata e applica il budget cap.
	 */
	public function record(
		int $project_id,
		string $job,
		string $provider,
		string $model,
		int $input_tokens,
		int $output_tokens,
		int $images = 0
	): void {
		$cost = self::estimate_cost( $model, $input_tokens, $output_tokens, $images );

		$this->usage->record( $project_id, $job, $provider, $model, $input_tokens, $output_tokens, $images, $cost );

		$this->enforce_budget( $project_id );
	}

	/**
	 * True se il progetto può ancora spendere (usato come guard prima di
	 * accodare job costosi).
	 */
	public function within_budget( int $project_id ): bool {
		return null === $this->exceeded_limit( $project_id );
	}

	/**
	 * Applica il cap: se superato, transizione a paused_budget (una sola volta).
	 */
	public function enforce_budget( int $project_id ): void {
		$limit = $this->exceeded_limit( $project_id );
		if ( null === $limit ) {
			return;
		}

		$type  = $this->projects->is_translation( $project_id ) ? StateMachine::TYPE_TRANSLATION : StateMachine::TYPE_PROJECT;
		$state = $this->states->state_of( $project_id, $type );
		if ( StateMachine::can( $type, $state, 'budget_exceeded' ) ) {
			$this->states->transition( $project_id, $type, 'budget_exceeded', array( 'limit' => $limit ) );
		}
	}

	/**
	 * @return string|null Il limite superato ('max_cost_eur'|'max_images') o null.
	 */
	public function exceeded_limit( int $project_id ): ?string {
		$config = $this->projects->get_config( $project_id );
		$budget = (array) ( $config['ai']['budget'] ?? array() );
		if ( empty( $budget ) ) {
			return null;
		}

		$totals = $this->usage->totals( $project_id );

		if ( isset( $budget['max_cost_eur'] ) && $totals['cost_estimate'] >= (float) $budget['max_cost_eur'] ) {
			return 'max_cost_eur';
		}
		if ( isset( $budget['max_images'] ) && $totals['images'] >= (int) $budget['max_images'] ) {
			return 'max_images';
		}

		return null;
	}

	/**
	 * Stato del budget per il pannello costi (totali, limiti, percentuale).
	 *
	 * @return array<string, mixed>
	 */
	public function report( int $project_id ): array {
		$config = $this->projects->get_config( $project_id );
		$budget = (array) ( $config['ai']['budget'] ?? array() );
		$totals = $this->usage->totals( $project_id );

		$pct = null;
		if ( ! empty( $budget['max_cost_eur'] ) && (float) $budget['max_cost_eur'] > 0 ) {
			$pct = (int) round( 100 * $totals['cost_estimate'] / (float) $budget['max_cost_eur'] );
		}

		return array(
			'totals'   => $totals,
			'budget'   => $budget,
			'cost_pct' => $pct,
			'alert'    => null !== $pct && $pct >= (int) ( $budget['alert_at_pct'] ?? 80 ),
			'exceeded' => $this->exceeded_limit( $project_id ),
		);
	}

	/**
	 * Stima del costo in EUR dalle tariffe per milione di token.
	 * Tariffe di default filtrabili (gw_model_rates); i modelli non mappati
	 * costano 0 (il cap sul costo non scatta: usare max_images o mappare).
	 */
	public static function estimate_cost( string $model, int $input_tokens, int $output_tokens, int $images = 0 ): float {
		/**
		 * Mappa prefisso modello → [EUR per Mtok input, EUR per Mtok output].
		 *
		 * @param array<string, array{0: float, 1: float}> $rates Tariffe.
		 */
		$rates = apply_filters(
			'gw_model_rates',
			array(
				'claude-fable'  => array( 9.3, 46.5 ),
				'claude-opus'   => array( 4.7, 23.5 ),
				'claude-sonnet' => array( 2.8, 14.0 ),
				'claude-haiku'  => array( 0.9, 4.5 ),
				'gpt-5'         => array( 1.2, 9.0 ),
				'gpt-4o'        => array( 2.3, 9.0 ),
			)
		);

		/**
		 * Costo per immagine generata (EUR).
		 */
		$image_cost = (float) apply_filters( 'gw_image_cost', 0.08 );

		$cost = $images * $image_cost;
		foreach ( $rates as $prefix => [$in_rate, $out_rate] ) {
			if ( str_starts_with( $model, $prefix ) ) {
				$cost += $input_tokens * $in_rate / 1_000_000 + $output_tokens * $out_rate / 1_000_000;
				break;
			}
		}

		return round( $cost, 4 );
	}
}

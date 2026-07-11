<?php
declare(strict_types=1);

namespace Ghostwriter\Repository;

/**
 * Tabella gw_usage: token, immagini e costi per progetto.
 * Alimenta il budget cap (UsageMeter, fase 4) e il pannello costi.
 */
class UsageRepository {

	public function record(
		int $project_id,
		string $job,
		string $provider,
		string $model,
		int $input_tokens,
		int $output_tokens,
		int $images = 0,
		float $cost_estimate = 0.0
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'gw_usage',
			array(
				'project_id'    => $project_id,
				'job'           => $job,
				'provider'      => $provider,
				'model'         => $model,
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'images'        => $images,
				'cost_estimate' => $cost_estimate,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s' )
		);
	}

	/**
	 * Totali del progetto per il budget cap e il pannello costi.
	 *
	 * @return array{input_tokens: int, output_tokens: int, images: int, cost_estimate: float}
	 */
	public function totals( int $project_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(input_tokens),0) AS input_tokens,
				        COALESCE(SUM(output_tokens),0) AS output_tokens,
				        COALESCE(SUM(images),0) AS images,
				        COALESCE(SUM(cost_estimate),0) AS cost_estimate
				 FROM {$wpdb->prefix}gw_usage WHERE project_id = %d",
				$project_id
			),
			ARRAY_A
		);

		return array(
			'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
			'images'        => (int) ( $row['images'] ?? 0 ),
			'cost_estimate' => (float) ( $row['cost_estimate'] ?? 0 ),
		);
	}
}

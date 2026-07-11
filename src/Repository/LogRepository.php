<?php
declare(strict_types=1);

namespace Ghostwriter\Repository;

/**
 * Tabella gw_log: il diario tecnico della pipeline. Ogni transizione di
 * stato, ogni chiamata AI con esito, ogni retry passa da qui.
 *
 * ATTENZIONE: mai loggare segreti (API key) nel context.
 */
class LogRepository {

	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/**
	 * @param array<string, mixed> $context Contesto serializzato in JSON.
	 */
	public function log( int $project_id, ?int $chapter_id, string $level, string $event, array $context = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'gw_log',
			array(
				'project_id' => $project_id,
				'chapter_id' => $chapter_id,
				'level'      => $level,
				'event'      => $event,
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Ultime voci di log del progetto (per la UI), più recenti per prime.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function latest( int $project_id, int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}gw_log WHERE project_id = %d ORDER BY id DESC LIMIT %d",
				$project_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['context'] = $row['context'] ? json_decode( (string) $row['context'], true ) : null;
				return $row;
			},
			$rows ?: array()
		);
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Schema JSON dell'output atteso per ogni fase della pipeline.
 * È il contratto passato ai provider: "rispondi solo con JSON conforme a
 * questo schema" (tool-use forzato su Anthropic, structured outputs su
 * OpenAI). La validazione vera resta nei job (SchemaValidator).
 */
final class PhaseSchemas {

	public function __construct( private string $schemas_dir ) {
		$this->schemas_dir = rtrim( $schemas_dir, '/' );
	}

	/**
	 * @return array<string, mixed> Schema self-contained per la fase.
	 */
	public function for_phase( string $phase ): array {
		return match ( $phase ) {
			AiRequest::PHASE_OUTLINE  => self::outline_schema(),
			AiRequest::PHASE_DRAFT,
			AiRequest::PHASE_REVIEW   => $this->chapter_content_schema(),
			AiRequest::PHASE_SYNOPSIS => self::synopsis_schema(),
			AiRequest::PHASE_REWRITE  => $this->block_schema(),
			default                   => throw new \InvalidArgumentException( "Fase senza schema: {$phase}" ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function chapter_content_schema(): array {
		$schema = json_decode( (string) file_get_contents( $this->schemas_dir . '/chapter-content.schema.json' ), true );
		if ( ! is_array( $schema ) ) {
			throw new \RuntimeException( 'chapter-content.schema.json non leggibile.' );
		}
		// $id/$schema confondono alcuni endpoint: il contratto resta identico.
		unset( $schema['$id'], $schema['$schema'] );
		return $schema;
	}

	/**
	 * Schema del SOLO blocco (per la riscrittura): il ramo #/definitions/block
	 * reso self-contained portando con sé le definizioni.
	 *
	 * @return array<string, mixed>
	 */
	private function block_schema(): array {
		$full = $this->chapter_content_schema();

		return array(
			'$ref'        => '#/definitions/block',
			'definitions' => $full['definitions'] ?? array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function outline_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'chapters' ),
			'properties' => array(
				'chapters' => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'title', 'brief' ),
						'properties' => array(
							'title'           => array( 'type' => 'string' ),
							'brief'           => array(
								'type'        => 'string',
								'description' => '2-3 frasi: obiettivo del capitolo',
							),
							'planned_sources' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'source_id dal registry del progetto',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function synopsis_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'synopsis' ),
			'properties' => array(
				'synopsis'   => array(
					'type'        => 'string',
					'description' => 'Sinossi di 100-200 parole del capitolo',
				),
				'continuity' => array(
					'type'       => 'object',
					'properties' => array(
						'terminology'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'required'   => array( 'term', 'introduced_in' ),
								'properties' => array(
									'term'          => array( 'type' => 'string' ),
									'definition'    => array( 'type' => 'string' ),
									'introduced_in' => array( 'type' => 'integer' ),
								),
							),
						),
						'concepts_covered' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'concept'    => array( 'type' => 'string' ),
									'chapter_id' => array( 'type' => 'integer' ),
								),
							),
						),
						'promises'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'required'   => array( 'text', 'made_in', 'target_chapter', 'fulfilled' ),
								'properties' => array(
									'text'           => array( 'type' => 'string' ),
									'made_in'        => array( 'type' => 'integer' ),
									'target_chapter' => array( 'type' => array( 'integer', 'null' ) ),
									'fulfilled'      => array( 'type' => 'boolean' ),
								),
							),
						),
						'style_decisions'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}
}

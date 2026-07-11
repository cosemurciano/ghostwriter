<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Richiesta di completamento strutturato a un provider AI.
 * Il contratto di output è sempre "solo JSON conforme allo schema indicato".
 */
final class AiRequest {

	public const PHASE_OUTLINE  = 'outline';
	public const PHASE_DRAFT    = 'draft';
	public const PHASE_SYNOPSIS = 'synopsis';
	public const PHASE_REVIEW   = 'review';
	public const PHASE_REWRITE  = 'rewrite';
	public const PHASE_GLOSSARY    = 'glossary';
	public const PHASE_TRANSLATION = 'translation';
	public const PHASE_COVER       = 'cover';

	/**
	 * @param string               $phase   Fase della pipeline (determina skills e schema attesi).
	 * @param array<string, mixed> $context Contesto composto (dossier, brief, capitolo precedente, feedback...).
	 * @param int                  $project_id Progetto di riferimento (per usage e log).
	 * @param int|null             $chapter_id Capitolo di riferimento, se pertinente.
	 */
	public function __construct(
		public readonly string $phase,
		public readonly array $context,
		public readonly int $project_id,
		public readonly ?int $chapter_id = null
	) {
	}
}

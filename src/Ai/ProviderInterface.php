<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Contratto minimo verso i provider AI (ARCHITECTURE.md §6).
 *
 * I provider reali (Anthropic, OpenAI) arrivano in fase 4 con ContextComposer,
 * SkillsManager, RagService e UsageMeter; la pipeline di fase 3 gira sul
 * MockProvider. Le firme restano volutamente strette: structured output only.
 */
interface ProviderInterface {

	/**
	 * Completamento strutturato: la risposta è SOLO JSON conforme allo schema
	 * della fase; la validazione avviene nel job chiamante (un retry con
	 * l'errore di validazione nel prompt, poi failed).
	 */
	public function complete( AiRequest $request ): AiResult;
}

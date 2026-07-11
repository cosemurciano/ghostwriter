<?php
declare(strict_types=1);

namespace Ghostwriter\Queue;

/**
 * Contratto dei job della pipeline (ARCHITECTURE.md §5).
 *
 * Regole comuni:
 * - idempotenza: il job controlla lo stato in ingresso e, se il lavoro
 *   risulta già fatto, esce senza effetti;
 * - una sola chiamata AI per job (mai loop di chiamate: si spezza in job
 *   successivi);
 * - nessun job scrive stati direttamente: passa sempre da StateMachine.
 */
interface JobInterface {

	/**
	 * Nome breve del job (usato per hook, dedup key e gw_log).
	 */
	public static function name(): string;

	/**
	 * Esegue il job. Deve essere rieseguibile senza duplicare effetti.
	 *
	 * @param array<string, mixed> $args Argomenti serializzabili.
	 * @throws \Throwable In caso di errore: il Dispatcher gestisce i retry.
	 */
	public function handle( array $args ): void;

	/**
	 * Chiamato dal Dispatcher quando i tentativi sono esauriti:
	 * porta l'entità in stato failed e registra il motivo su gw_log.
	 *
	 * @param array<string, mixed> $args Gli argomenti del job fallito.
	 */
	public function on_failure( array $args, \Throwable $e ): void;
}

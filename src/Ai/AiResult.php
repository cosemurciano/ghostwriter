<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Risultato di un completamento: contenuto strutturato + consumi.
 */
final class AiResult {

	/**
	 * @param array<string, mixed> $content Output strutturato (da validare contro lo schema di fase).
	 * @param int                  $input_tokens  Token in ingresso.
	 * @param int                  $output_tokens Token in uscita.
	 * @param string               $model         Modello che ha prodotto il risultato.
	 */
	public function __construct(
		public readonly array $content,
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0,
		public readonly string $model = ''
	) {
	}
}

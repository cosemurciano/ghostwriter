<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Errore di comunicazione o di risposta di un provider AI.
 * Il messaggio non contiene mai la chiave API.
 */
final class AiProviderException extends \RuntimeException {

	public function __construct(
		string $message,
		public readonly ?int $status_code = null,
		public readonly ?string $provider = null
	) {
		parent::__construct( $message );
	}
}

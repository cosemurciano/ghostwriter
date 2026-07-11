<?php
declare(strict_types=1);

namespace Ghostwriter\Domain;

/**
 * Lanciata quando un evento non è ammesso dallo stato corrente dell'entità.
 */
final class InvalidTransitionException extends \RuntimeException {

	public static function create( string $entity_type, string $state, string $event ): self {
		return new self(
			sprintf( 'Transizione non valida: evento "%s" non ammesso dallo stato "%s" (%s).', $event, $state, $entity_type )
		);
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Schema;

/**
 * Lanciata quando un dato non è conforme al suo schema JSON.
 */
final class SchemaValidationException extends \RuntimeException {

	/** @var array<int, array{property: string, message: string}> */
	private array $errors;

	/**
	 * @param array<int, array{property: string, message: string}> $errors Errori del validatore.
	 */
	public function __construct( string $schema_name, array $errors ) {
		$this->errors = $errors;

		$summary = implode(
			'; ',
			array_map(
				static fn( array $e ): string => trim( "{$e['property']}: {$e['message']}" ),
				array_slice( $errors, 0, 5 )
			)
		);

		parent::__construct( "Dato non conforme allo schema {$schema_name}: {$summary}" );
	}

	/**
	 * @return array<int, array{property: string, message: string}>
	 */
	public function get_errors(): array {
		return $this->errors;
	}
}

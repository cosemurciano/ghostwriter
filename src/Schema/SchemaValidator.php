<?php
declare(strict_types=1);

namespace Ghostwriter\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validazione dei dati contro gli schemi JSON del contratto dati (schemas/).
 *
 * Ogni scrittura di _gw_config, _gw_dossier, _gw_content e ogni import di
 * temi passa da qui.
 *
 * Nota: la libreria è opis/json-schema e non justinrainbow/json-schema
 * (indicata in ARCHITECTURE.md §1) perché justinrainbow non supporta le
 * condizionali if/then di draft-07, su cui gli schemi basano la validazione
 * per-tipo delle props dei blocchi: con justinrainbow i blocchi malformati
 * passerebbero silenziosamente.
 *
 * Gli schemi si referenziano a vicenda con $ref relativi
 * (es. chapter-content.schema.json#/definitions/block): all'avvio ogni schema
 * viene registrato sotto un base URI canonico così i riferimenti relativi
 * si risolvono tra loro.
 */
final class SchemaValidator {

	public const PROJECT_CONFIG  = 'project-config';
	public const CHAPTER_CONTENT = 'chapter-content';
	public const DOSSIER         = 'dossier';
	public const THEME           = 'theme';
	public const BLOCK_REVISION  = 'block-revision';

	private const BASE_URI = 'https://ghostwriter.local/schemas/';

	private const SCHEMAS = array(
		self::PROJECT_CONFIG,
		self::CHAPTER_CONTENT,
		self::DOSSIER,
		self::THEME,
		self::BLOCK_REVISION,
	);

	private ?Validator $validator = null;

	public function __construct( private string $schemas_dir ) {
		$this->schemas_dir = rtrim( $schemas_dir, '/' );
	}

	/**
	 * Valida un dato contro uno schema. Lancia in caso di non conformità.
	 *
	 * @param array<string, mixed>|object $data        Dato da validare (array associativo o oggetto).
	 * @param string                      $schema_name Nome schema senza suffisso (es. self::CHAPTER_CONTENT).
	 *
	 * @throws SchemaValidationException Se il dato non è conforme.
	 * @throws \InvalidArgumentException Se lo schema non esiste.
	 */
	public function validate( array|object $data, string $schema_name ): void {
		$errors = $this->get_validation_errors( $data, $schema_name );
		if ( ! empty( $errors ) ) {
			throw new SchemaValidationException( $schema_name, $errors );
		}
	}

	/**
	 * Come validate() ma restituisce gli errori invece di lanciare.
	 *
	 * @param array<string, mixed>|object $data Dato da validare.
	 * @return array<int, array{property: string, message: string}> Vuoto se conforme.
	 */
	public function get_validation_errors( array|object $data, string $schema_name ): array {
		if ( ! in_array( $schema_name, self::SCHEMAS, true ) ) {
			throw new \InvalidArgumentException( "Schema non registrato: {$schema_name}" );
		}

		return $this->run( $data, self::BASE_URI . $schema_name . '.schema.json' );
	}

	/**
	 * Valida un singolo blocco del formato intermedio (usato da BlockRevisionService
	 * e dai job di riscrittura: l'output AI è il SOLO blocco).
	 *
	 * @param array<string, mixed>|object $block Blocco da validare.
	 * @return array<int, array{property: string, message: string}> Vuoto se conforme.
	 */
	public function get_block_validation_errors( array|object $block ): array {
		return $this->run( $block, self::BASE_URI . self::CHAPTER_CONTENT . '.schema.json#/definitions/block' );
	}

	/**
	 * @param array<string, mixed>|object $data Dato da validare.
	 * @return array<int, array{property: string, message: string}>
	 */
	private function run( array|object $data, string $schema_uri ): array {
		// Il validatore lavora su oggetti: gli array associativi vengono convertiti.
		$payload = is_array( $data ) ? json_decode( (string) json_encode( $data ) ) : $data;

		$result = $this->get_validator()->validate( $payload, $schema_uri );

		if ( $result->isValid() ) {
			return array();
		}

		return self::format_error( $result->error() );
	}

	private function get_validator(): Validator {
		if ( null !== $this->validator ) {
			return $this->validator;
		}

		$this->validator = new Validator();
		$this->validator->setMaxErrors( 10 );

		foreach ( self::SCHEMAS as $name ) {
			$file = "{$this->schemas_dir}/{$name}.schema.json";
			if ( ! file_exists( $file ) ) {
				throw new \RuntimeException( "Schema mancante: {$file}" );
			}

			$schema = json_decode( (string) file_get_contents( $file ) );
			if ( ! is_object( $schema ) ) {
				throw new \RuntimeException( "Schema non parsabile: {$file}" );
			}

			// L'$id dichiarato (es. "ghostwriter/chapter-content.schema.json") non è
			// un URI assoluto: viene canonizzato così i $ref relativi tra schemi
			// si risolvono tutti sotto lo stesso base URI.
			$schema->{'$id'} = self::BASE_URI . $name . '.schema.json';

			$this->validator->resolver()->registerRaw( $schema );
		}

		return $this->validator;
	}

	/**
	 * @return array<int, array{property: string, message: string}>
	 */
	private static function format_error( ValidationError $error ): array {
		$formatted = ( new ErrorFormatter() )->format( $error, true );

		$errors = array();
		foreach ( $formatted as $pointer => $messages ) {
			foreach ( (array) $messages as $message ) {
				$errors[] = array(
					'property' => (string) $pointer,
					'message'  => (string) $message,
				);
			}
		}
		return $errors;
	}
}

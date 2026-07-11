<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Schema\SchemaValidationException;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase {

	private SchemaValidator $validator;

	protected function setUp(): void {
		$this->validator = new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR );
	}

	/**
	 * Golden file: il capitolo d'esempio del pacchetto spec deve validare.
	 */
	public function test_example_chapter_is_valid(): void {
		$chapter = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);
		self::assertIsArray( $chapter );

		$errors = $this->validator->get_validation_errors( $chapter, SchemaValidator::CHAPTER_CONTENT );
		self::assertSame( array(), $errors );
	}

	public function test_chapter_without_required_fields_is_invalid(): void {
		$this->expectException( SchemaValidationException::class );
		$this->validator->validate( array( 'schema_version' => '1.0' ), SchemaValidator::CHAPTER_CONTENT );
	}

	public function test_unknown_block_type_is_invalid(): void {
		$chapter = array(
			'schema_version' => '1.0',
			'chapter_id'     => 1,
			'blocks'         => array(
				array(
					'id'    => 'b-1',
					'type'  => 'tipo_inventato',
					'props' => array( 'text' => 'x' ),
				),
			),
		);

		$errors = $this->validator->get_validation_errors( $chapter, SchemaValidator::CHAPTER_CONTENT );
		self::assertNotEmpty( $errors );
	}

	public function test_single_block_validation(): void {
		$valid = array(
			'id'    => 'b-1',
			'type'  => 'paragrafo',
			'props' => array( 'text' => 'Testo del paragrafo.' ),
		);
		self::assertSame( array(), $this->validator->get_block_validation_errors( $valid ) );

		$invalid = array(
			'id'    => 'b-2',
			'type'  => 'heading',
			'props' => array(
				'text'  => 'Titolo',
				'level' => 9, // Fuori range: max 4.
			),
		);
		self::assertNotEmpty( $this->validator->get_block_validation_errors( $invalid ) );
	}

	public function test_minimal_project_config_is_valid(): void {
		$config = array(
			'schema_version'     => '1.0',
			'language'           => 'it',
			'format'             => array(
				'trim_width_mm'  => 150,
				'trim_height_mm' => 230,
			),
			'structural_profile' => array(
				'allowed_blocks' => array( 'paragrafo', 'heading', 'figura' ),
			),
			'skills'             => array(
				array(
					'skill_id' => 'stile-divulgazione',
					'version'  => 3,
					'phases'   => array( 'draft', 'review' ),
				),
			),
			'ai'                 => array(
				'provider' => 'anthropic',
				'model'    => 'claude-opus-4-8',
			),
		);

		$errors = $this->validator->get_validation_errors( $config, SchemaValidator::PROJECT_CONFIG );
		self::assertSame( array(), $errors );
	}

	public function test_project_config_with_unknown_provider_is_invalid(): void {
		$config = array(
			'schema_version'     => '1.0',
			'language'           => 'it',
			'format'             => array(
				'trim_width_mm'  => 150,
				'trim_height_mm' => 230,
			),
			'structural_profile' => array( 'allowed_blocks' => array( 'paragrafo' ) ),
			'skills'             => array(),
			'ai'                 => array(
				'provider' => 'gemini',
				'model'    => 'x',
			),
		);

		$errors = $this->validator->get_validation_errors( $config, SchemaValidator::PROJECT_CONFIG );
		self::assertNotEmpty( $errors );
	}

	public function test_minimal_dossier_is_valid(): void {
		$dossier = array(
			'schema_version' => '1.0',
			'project_id'     => 7,
			'updated_at'     => gmdate( 'c' ),
			'brief'          => array(
				'thesis'   => 'Tesi',
				'audience' => 'Lettori curiosi',
				'genre'    => 'divulgazione',
				'language' => 'it',
			),
			'outline'        => array(
				array(
					'chapter_id' => 12,
					'title'      => 'Capitolo 1',
					'status'     => 'planned',
				),
			),
		);

		$errors = $this->validator->get_validation_errors( $dossier, SchemaValidator::DOSSIER );
		self::assertSame( array(), $errors );
	}

	public function test_block_revision_record_is_valid(): void {
		$revision = array(
			'block_id'   => 'b1f0a2c4-0001',
			'chapter_id' => 412,
			'version'    => 2,
			'origin'     => 'ai_rewrite',
			'feedback'   => 'Troppo tecnico, semplifica.',
			'block'      => array(
				'id'    => 'b1f0a2c4-0001',
				'type'  => 'paragrafo',
				'props' => array( 'text' => 'Versione precedente.' ),
			),
			'created_at' => gmdate( 'c' ),
		);

		$errors = $this->validator->get_validation_errors( $revision, SchemaValidator::BLOCK_REVISION );
		self::assertSame( array(), $errors );
	}

	public function test_unknown_schema_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->validator->validate( array(), 'inesistente' );
	}
}

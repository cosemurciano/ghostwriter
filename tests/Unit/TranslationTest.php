<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\MockProvider;
use Ghostwriter\Ai\PhaseSchemas;
use Ghostwriter\Queue\Jobs\TranslateChapterJob;
use Ghostwriter\Schema\SchemaValidator;
use Ghostwriter\Translation\GlossaryService;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $example;

	protected function setUp(): void {
		$this->example = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);
	}

	// --- GlossaryService: estrazione candidati -----------------------------

	public function test_candidate_terms_deduplicated_from_source_dossier(): void {
		$source_dossier = array(
			'continuity' => array(
				'terminology'      => array(
					array( 'term' => 'Masseria', 'definition' => 'Azienda agricola fortificata', 'introduced_in' => 1 ),
					array( 'term' => 'masseria', 'definition' => 'duplicato con case diverso', 'introduced_in' => 2 ),
					array( 'term' => 'Caditoia', 'definition' => 'Apertura difensiva', 'introduced_in' => 2 ),
				),
				'concepts_covered' => array(
					array( 'concept' => 'difesa piombante', 'chapter_id' => 2 ),
					array( 'concept' => 'Caditoia', 'chapter_id' => 3 ), // Già in terminologia.
				),
			),
		);

		$terms = GlossaryService::candidate_terms( $source_dossier );

		self::assertSame(
			array( 'Masseria', 'Caditoia', 'difesa piombante' ),
			array_column( $terms, 'term' )
		);
		self::assertSame( 'Azienda agricola fortificata', $terms[0]['definition'] );
	}

	public function test_candidate_terms_empty_dossier(): void {
		self::assertSame( array(), GlossaryService::candidate_terms( array() ) );
	}

	// --- MockProvider: fasi glossary e translation --------------------------

	public function test_mock_glossary_maps_candidates_and_validates(): void {
		$result = ( new MockProvider() )->complete(
			new AiRequest(
				AiRequest::PHASE_GLOSSARY,
				array(
					'candidate_terms' => array(
						array( 'term' => 'masseria', 'definition' => '...' ),
					),
				),
				9
			)
		);

		self::assertSame( 'masseria', $result->content['glossary'][0]['source_term'] );
		self::assertNotSame( '', $result->content['glossary'][0]['target_term'] );

		// Lo schema di fase deve accettare l'output del mock.
		$schema = ( new PhaseSchemas( dirname( __DIR__, 2 ) . '/schemas' ) )->for_phase( AiRequest::PHASE_GLOSSARY );
		self::assertSame( array( 'glossary' ), $schema['required'] );
	}

	public function test_mock_translation_preserves_block_mapping_and_validates(): void {
		$result = ( new MockProvider() )->complete(
			new AiRequest(
				AiRequest::PHASE_TRANSLATION,
				array(
					'source_content'  => $this->example,
					'target_language' => 'en',
				),
				9,
				77
			)
		);

		$translated = $result->content;

		// Mapping intatto: stessi id e type, stesso ordine, anche annidati.
		self::assertTrue( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
		// Testi tradotti (prefisso mock) e titolo incluso.
		self::assertStringStartsWith( '[en] ', $translated['meta']['title'] );
		self::assertStringStartsWith( '[en] ', $translated['blocks'][0]['props']['text'] );
		// Conforme al contratto dati.
		$translated['chapter_id'] = 77;
		$errors = ( new SchemaValidator( dirname( __DIR__, 2 ) . '/schemas' ) )
			->get_validation_errors( $translated, SchemaValidator::CHAPTER_CONTENT );
		self::assertSame( array(), $errors );
	}

	// --- Verifica mapping ----------------------------------------------------

	public function test_mapping_broken_by_missing_block(): void {
		$translated = $this->example;
		array_pop( $translated['blocks'] );

		self::assertFalse( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
	}

	public function test_mapping_broken_by_changed_id_or_type(): void {
		$translated                     = $this->example;
		$translated['blocks'][0]['id'] .= '-x';
		self::assertFalse( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );

		$translated                       = $this->example;
		$translated['blocks'][0]['type']  = 'citazione';
		self::assertFalse( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
	}

	public function test_mapping_broken_by_reordering(): void {
		$translated           = $this->example;
		$translated['blocks'] = array_reverse( $translated['blocks'] );

		self::assertFalse( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
	}

	public function test_mapping_broken_inside_nested_box(): void {
		$translated = $this->example;
		// Il box_approfondimento è il blocco 5 (indice 4) e contiene blocchi annidati.
		$translated['blocks'][4]['props']['blocks'][0]['id'] = 'altro-id';

		self::assertFalse( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
	}

	public function test_mapping_intact_when_only_texts_change(): void {
		$translated = $this->example;
		$translated['blocks'][0]['props']['text'] = 'Texto traducido.';
		$translated['meta']['title']              = 'Título traducido';

		self::assertTrue( TranslateChapterJob::block_mapping_intact( $this->example, $translated ) );
	}
}

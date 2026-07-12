<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Il bug della degradazione JSON: "props": {} del provider, decodificato
 * associativo e ri-serializzato, diventava [] e NON validava contro
 * "type": "object" (era la causa dei draft_chapter falliti in produzione).
 */
final class ContentNormalizationTest extends TestCase {

	private SchemaValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->validator = new SchemaValidator( dirname( __DIR__, 2 ) . '/schemas' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_props_degraded_to_array_fails_without_normalization(): void {
		// Così arrivava dal provider dopo json_decode(..., true).
		$content = array(
			'schema_version' => '1.0',
			'chapter_id'     => 7,
			'blocks'         => array(
				array( 'id' => 'b1', 'type' => 'separatore', 'version' => 1, 'props' => array() ),
			),
		);

		self::assertNotEmpty( $this->validator->get_validation_errors( $content, SchemaValidator::CHAPTER_CONTENT ) );
	}

	public function test_normalized_content_with_empty_props_validates(): void {
		$content = array(
			'schema_version' => '1.0',
			'chapter_id'     => 7,
			'blocks'         => array(
				array( 'id' => 'b1', 'type' => 'separatore', 'version' => 1, 'props' => array() ),
				array( 'id' => 'b2', 'type' => 'paragrafo', 'version' => 1, 'props' => array( 'text' => 'Testo.' ) ),
				array(
					'id'      => 'b3',
					'type'    => 'box_approfondimento',
					'version' => 1,
					'props'   => array(
						'title'  => 'Nota',
						'blocks' => array(
							array( 'id' => 'b3-1', 'type' => 'separatore', 'props' => array() ),
						),
					),
				),
			),
			'meta'           => array(),
		);

		$normalized = ChapterRepository::normalize_content( $content );

		self::assertSame( array(), $this->validator->get_validation_errors( $normalized, SchemaValidator::CHAPTER_CONTENT ) );
		self::assertInstanceOf( \stdClass::class, $normalized['blocks'][0]['props'] );
		self::assertInstanceOf( \stdClass::class, $normalized['blocks'][2]['props']['blocks'][0]['props'] );
		self::assertArrayNotHasKey( 'meta', $normalized );
	}
}

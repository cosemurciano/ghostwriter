<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
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

	/**
	 * Il rovescio della normalizzazione: in storage le props vuote sono
	 * stdClass, ma i lettori PHP accedono con $block['props'][...] — su un
	 * oggetto è "Cannot use object of type stdClass as array" (era il fatal
	 * di resume_pipeline). get_content deve restituire SOLO array puri.
	 */
	public function test_get_content_returns_pure_arrays_even_for_stdclass_props(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$stored = array(
			'schema_version' => '1.0',
			'chapter_id'     => 7,
			'blocks'         => array(
				array( 'id' => 'b1', 'type' => 'separatore', 'version' => 1, 'props' => new \stdClass() ),
				array(
					'id'      => 'b2',
					'type'    => 'box_approfondimento',
					'version' => 1,
					'props'   => array(
						'title'  => 'Nota',
						'blocks' => array(
							array( 'id' => 'b2-1', 'type' => 'figura', 'version' => 1, 'props' => array( 'image_brief' => 'Una masseria' ) ),
							array( 'id' => 'b2-2', 'type' => 'separatore', 'version' => 1, 'props' => new \stdClass() ),
						),
					),
				),
			),
		);
		Functions\when( 'get_post_meta' )->justReturn( $stored );

		$repo    = new ChapterRepository( $this->validator );
		$content = $repo->get_content( 7 );

		self::assertIsArray( $content['blocks'][0]['props'] );
		self::assertIsArray( $content['blocks'][1]['props']['blocks'][1]['props'] );

		// I walker sui blocchi non devono mai esplodere, nemmeno con il
		// contenuto normalizzato in memoria (props ancora stdClass).
		self::assertSame( array( 'b2-1' ), GenerateImageJob::unresolved_figure_ids( $stored['blocks'] ) );
		self::assertTrue( GenerateImageJob::has_unresolved_figures( $stored['blocks'] ) );

		// find_block trova anche i blocchi annidati accanto a props stdClass.
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		self::assertSame( 'figura', $repo->find_block( 7, 'b2-1' )['type'] );
	}
}

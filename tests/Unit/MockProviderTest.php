<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\MockProvider;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Il MockProvider deve produrre SEMPRE output conformi agli schemi:
 * è il banco di prova della pipeline prima dei provider reali.
 */
final class MockProviderTest extends TestCase {

	private MockProvider $provider;
	private SchemaValidator $validator;

	protected function setUp(): void {
		$this->provider  = new MockProvider();
		$this->validator = new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR );
	}

	public function test_outline_proposes_chapters_with_briefs(): void {
		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_OUTLINE, array( 'brief' => array( 'thesis' => 'le masserie del Salento' ) ), 7 )
		);

		self::assertNotEmpty( $result->content['chapters'] );
		foreach ( $result->content['chapters'] as $chapter ) {
			self::assertNotSame( '', $chapter['title'] );
			self::assertStringContainsString( 'le masserie del Salento', $chapter['brief'] );
		}
		self::assertGreaterThan( 0, $result->output_tokens );
	}

	public function test_draft_output_validates_against_chapter_content_schema(): void {
		$result = $this->provider->complete(
			new AiRequest(
				AiRequest::PHASE_DRAFT,
				array( 'chapter_brief' => array( 'title' => 'Capitolo di prova' ) ),
				7,
				412
			)
		);

		$errors = $this->validator->get_validation_errors( $result->content, SchemaValidator::CHAPTER_CONTENT );
		self::assertSame( array(), $errors );
		self::assertSame( 412, $result->content['chapter_id'] );
	}

	public function test_rewrite_preserves_id_and_type_and_validates(): void {
		$block = array(
			'id'      => 'b1',
			'type'    => 'paragrafo',
			'version' => 3,
			'props'   => array( 'text' => 'Testo originale.', 'role' => 'normal' ),
		);

		$result = $this->provider->complete(
			new AiRequest(
				AiRequest::PHASE_REWRITE,
				array( 'block' => $block, 'feedback' => 'troppo tecnico' ),
				7,
				412
			)
		);

		self::assertSame( 'b1', $result->content['id'] );
		self::assertSame( 'paragrafo', $result->content['type'] );
		self::assertStringContainsString( 'troppo tecnico', $result->content['props']['text'] );
		self::assertSame( array(), $this->validator->get_block_validation_errors( $result->content ) );
	}

	public function test_review_returns_content_unchanged(): void {
		$content = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);

		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_REVIEW, array( 'content' => $content ), 7, 412 )
		);

		self::assertSame( $content, $result->content );
	}

	public function test_synopsis_has_text(): void {
		$result = $this->provider->complete(
			new AiRequest( AiRequest::PHASE_SYNOPSIS, array( 'chapter_title' => 'Le masserie' ), 7, 412 )
		);

		self::assertStringContainsString( 'Le masserie', $result->content['synopsis'] );
	}

	public function test_unknown_phase_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->provider->complete( new AiRequest( 'cover', array(), 7 ) );
	}
}

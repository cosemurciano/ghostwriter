<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ImageRequest;
use Ghostwriter\Ai\LocalRagService;
use Ghostwriter\Ai\MockProvider;
use Ghostwriter\Media\ImageService;
use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Queue\Jobs\IndexChapterJob;
use Ghostwriter\Repository\RagChunkRepository;
use Ghostwriter\Sources\TextExtractor;
use PHPUnit\Framework\TestCase;

final class SourcesAndRagTest extends TestCase {

	private function rag_with_memory(): LocalRagService {
		$repo = new class() extends RagChunkRepository {
			/** @var array<int, array{chunk: string, source_id: string|null, chapter_id: int|null}> */
			public array $rows = array();

			public function insert_chunks( int $project_id, ?string $source_id, ?int $chapter_id, array $chunks ): void {
				foreach ( $chunks as $chunk ) {
					$this->rows[] = array(
						'chunk'      => $chunk,
						'source_id'  => $source_id,
						'chapter_id' => $chapter_id,
					);
				}
			}

			public function delete_for_source( int $project_id, string $source_id ): void {
				$this->rows = array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['source_id'] !== $source_id ) );
			}

			public function delete_for_chapter( int $project_id, int $chapter_id ): void {
				$this->rows = array_values( array_filter( $this->rows, static fn( array $r ): bool => $r['chapter_id'] !== $chapter_id ) );
			}

			public function all_for_project( int $project_id ): array {
				return $this->rows;
			}
		};

		return new LocalRagService( $repo );
	}

	// --- Chunking ----------------------------------------------------------

	public function test_chunking_respects_size_and_overlap(): void {
		$text   = str_repeat( 'parola ', 1000 ); // ~7000 caratteri.
		$chunks = LocalRagService::chunk_text( $text );

		self::assertGreaterThan( 4, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			self::assertLessThanOrEqual( LocalRagService::CHUNK_SIZE_CHARS, mb_strlen( $chunk ) );
		}
		// L'overlap fa sì che l'inizio del chunk N sia dentro il chunk N-1.
		$tail = mb_substr( $chunks[0], -50 );
		self::assertStringContainsString( mb_substr( $tail, 0, 20 ), $chunks[1] );
	}

	public function test_chunking_empty_text(): void {
		self::assertSame( array(), LocalRagService::chunk_text( "  \n " ) );
	}

	// --- Retrieval ----------------------------------------------------------

	public function test_query_ranks_relevant_chunks_first(): void {
		$rag = $this->rag_with_memory();

		$rag->ingest_source( 7, 'src-masserie', 'Le masserie fortificate del Salento nascono come presidi agricoli difensivi contro le incursioni turche.' );
		$rag->ingest_source( 7, 'src-cucina', 'La cucina salentina è famosa per i pasticciotti e il rustico leccese, dolci e street food della tradizione.' );

		$results = $rag->query( 7, 'strutture difensive e masserie fortificate', 2 );

		self::assertNotEmpty( $results );
		self::assertSame( 'src-masserie', $results[0]['source_id'] );
	}

	public function test_reingestion_replaces_chunks(): void {
		$rag = $this->rag_with_memory();

		$rag->ingest_source( 7, 's1', 'Versione vecchia del documento con contenuto ormai superato.' );
		$rag->ingest_source( 7, 's1', 'Versione nuova del documento sulle torri costiere del Salento.' );

		$results = $rag->query( 7, 'torri costiere', 5 );
		self::assertCount( 1, $results );
		self::assertStringContainsString( 'Versione nuova', $results[0]['text'] );
	}

	public function test_indexed_chapter_is_retrievable_with_chapter_source(): void {
		$rag = $this->rag_with_memory();
		$rag->ingest_chapter( 7, 412, 'Il capitolo racconta le masserie fortificate e le loro torri di avvistamento cinquecentesche.' );

		$results = $rag->query( 7, 'torri di avvistamento', 1 );

		self::assertSame( 'capitolo:412', $results[0]['source_id'] );
	}

	public function test_query_without_index_or_terms(): void {
		$rag = $this->rag_with_memory();
		self::assertSame( array(), $rag->query( 7, 'qualunque cosa' ) );

		$rag->ingest_source( 7, 's1', 'Del testo qualunque.' );
		self::assertSame( array(), $rag->query( 7, '!!! ??' ) );
	}

	// --- IndexChapterJob: estrazione testo dal formato intermedio ----------

	public function test_chapter_plain_text_covers_nested_blocks_and_strips_markdown(): void {
		$content = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);

		$text = IndexChapterJob::plain_text( $content );

		self::assertStringContainsString( 'Le masserie fortificate del Salento', $text );
		self::assertStringContainsString( 'Caditoie', $text ); // Elenco annidato nel box.
		self::assertStringNotContainsString( '*Caditoie*', $text ); // Markdown rimosso.
		self::assertStringNotContainsString( '[^n1]', $text ); // Note refs rimossi.
	}

	// --- TextExtractor -------------------------------------------------------

	public function test_extracts_text_from_real_pdf(): void {
		// Fixture PDF generata al volo con mPDF: il parser deve rileggerla.
		$dir = sys_get_temp_dir() . '/gw-extract-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700, true );
		$pdf_path = $dir . '/fonte.pdf';

		$mpdf = new \Mpdf\Mpdf( array( 'mode' => 'utf-8', 'tempDir' => $dir ) );
		$mpdf->WriteHTML( '<p>Le masserie fortificate del Salento: censimento del Catasto Onciario.</p>' );
		$mpdf->Output( $pdf_path, \Mpdf\Output\Destination::FILE );

		$extractor = new TextExtractor();
		$text      = $extractor->extract( array( 'type' => 'pdf', 'file_path' => $pdf_path ) );

		self::assertStringContainsString( 'Catasto Onciario', $text );
	}

	public function test_extracts_from_url_stripping_tags(): void {
		$extractor = new TextExtractor(
			static fn( string $url ): array => array(
				'body' => '<html><head><style>p{color:red}</style></head><body><h1>Titolo</h1><p>Testo &egrave; utile.</p><script>alert(1)</script></body></html>',
			)
		);

		$text = $extractor->extract( array( 'type' => 'url', 'url' => 'https://example.org/pagina' ) );

		self::assertStringContainsString( 'Titolo', $text );
		self::assertStringContainsString( 'Testo è utile.', $text );
		self::assertStringNotContainsString( 'alert(1)', $text );
		self::assertStringNotContainsString( 'color:red', $text );
	}

	public function test_rejects_non_http_url(): void {
		$extractor = new TextExtractor( static fn(): array => array( 'body' => 'x' ) );

		$this->expectException( \RuntimeException::class );
		$extractor->extract( array( 'type' => 'url', 'url' => 'file:///etc/passwd' ) );
	}

	// --- Immagini ------------------------------------------------------------

	public function test_mock_provider_generates_valid_png(): void {
		$image = ( new MockProvider() )->generate_image( new ImageRequest( 'una masseria', 512, 384, 7, 412, 'b1' ) );

		self::assertSame( "\x89PNG", substr( $image->binary, 0, 4 ) );
		self::assertSame( 'png', $image->extension() );
	}

	public function test_target_resolution_scales_with_print_ready_and_size(): void {
		$config = array( 'format' => array( 'trim_width_mm' => 150, 'trim_height_mm' => 230, 'print_ready' => true ) );

		[$w_full] = ImageService::target_resolution( $config, 'full' );
		[$w_small] = ImageService::target_resolution( $config, 'small' );
		// 150mm a 300dpi ≈ 1772px.
		self::assertEqualsWithDelta( 1772, $w_full, 5 );
		self::assertLessThan( $w_full, $w_small );

		$config['format']['print_ready'] = false;
		[$w_screen] = ImageService::target_resolution( $config, 'full' );
		self::assertLessThan( $w_full, $w_screen );
	}

	public function test_unresolved_figure_detection_including_nested(): void {
		$blocks = array(
			array( 'id' => 'p1', 'type' => 'paragrafo', 'props' => array( 'text' => 'x' ) ),
			array(
				'id'    => 'box1',
				'type'  => 'box_approfondimento',
				'props' => array(
					'title'  => 'Box',
					'blocks' => array(
						array( 'id' => 'f2', 'type' => 'figura', 'props' => array( 'attachment_id' => null, 'caption' => 'c' ) ),
					),
				),
			),
			array( 'id' => 'f1', 'type' => 'figura', 'props' => array( 'attachment_id' => 42, 'caption' => 'c' ) ),
		);

		self::assertTrue( GenerateImageJob::has_unresolved_figures( $blocks ) );
		self::assertSame( array( 'f2' ), GenerateImageJob::unresolved_figure_ids( $blocks ) );

		$blocks[1]['props']['blocks'][0]['props']['attachment_id'] = 43;
		self::assertFalse( GenerateImageJob::has_unresolved_figures( $blocks ) );
	}
}

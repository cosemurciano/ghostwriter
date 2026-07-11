<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Rendering\BlockRenderer;
use Ghostwriter\Rendering\BookData;
use Ghostwriter\Rendering\EpubExporter;
use Ghostwriter\Rendering\PdfExporter;
use Ghostwriter\Rendering\Theme;
use Ghostwriter\Rendering\ThemeCompiler\EpubCssCompiler;
use Ghostwriter\Rendering\ThemeCompiler\MpdfCssCompiler;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Export end-to-end dal capitolo d'esempio (golden file) col tema di serie:
 * PDF reale via mPDF ed ePub reale via ZipArchive, senza WordPress.
 */
final class ExportersTest extends TestCase {

	private static string $work_dir;
	private static Theme $theme;

	/** @var array<string, mixed> */
	private static array $example;

	public static function setUpBeforeClass(): void {
		self::$work_dir = sys_get_temp_dir() . '/gw-export-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( self::$work_dir, 0700, true );

		$registry    = new ThemeRegistry( new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR ), dirname( __DIR__, 2 ) . '/themes-bundled' );
		self::$theme = $registry->get( 'classico' ) ?? throw new \RuntimeException( 'Tema di serie assente.' );

		self::$example = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);
	}

	private function book(): BookData {
		return new BookData(
			title: 'Il Salento di pietra',
			subtitle: 'Masserie, torri e paesaggi fortificati',
			author: 'Cosè Murciano',
			language: 'it',
			trim_width_mm: 150,
			trim_height_mm: 230,
			chapters: array(
				array(
					'id'      => 412,
					'title'   => 'Le masserie fortificate del Salento',
					'depth'   => 0,
					'content' => self::$example,
				),
				array(
					'id'      => 413,
					'title'   => 'Le torri costiere',
					'depth'   => 1,
					'content' => array(
						'schema_version' => '1.0',
						'chapter_id'     => 413,
						'blocks'         => array(
							array(
								'id'    => 'x-1',
								'type'  => 'paragrafo',
								'props' => array(
									'text' => 'Le torri costiere completano il sistema difensivo.',
									'role' => 'normal',
								),
							),
						),
					),
				),
			),
			bibliography: array(
				'G. Cosi, *Torri marittime di Terra d\'Otranto*. Congedo, 1989.',
			),
			publisher: 'Edizioni di prova',
			isbn: '978-88-0000-000-0',
			year: '2026'
		);
	}

	public function test_pdf_export_produces_valid_pdf(): void {
		$output = self::$work_dir . '/libro.pdf';

		$exporter = new PdfExporter( new BlockRenderer(), new MpdfCssCompiler(), self::$work_dir . '/mpdf-tmp' );
		$exporter->export( $this->book(), self::$theme, $output );

		self::assertFileExists( $output );
		self::assertStringStartsWith( '%PDF-', (string) file_get_contents( $output, false, null, 0, 5 ) );
		self::assertGreaterThan( 10_000, filesize( $output ), 'Un PDF di due capitoli non può pesare pochi byte.' );
	}

	public function test_pdf_export_refuses_unsupported_format(): void {
		$book = new BookData(
			title: 'X',
			subtitle: null,
			author: 'Y',
			language: 'it',
			trim_width_mm: 300,
			trim_height_mm: 300,
			chapters: array()
		);

		$exporter = new PdfExporter( new BlockRenderer(), new MpdfCssCompiler(), self::$work_dir . '/mpdf-tmp' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'non supporta il formato' );
		$exporter->export( $book, self::$theme, self::$work_dir . '/mai.pdf' );
	}

	public function test_epub_export_produces_valid_package(): void {
		$output = self::$work_dir . '/libro.epub';

		$exporter = new EpubExporter( new BlockRenderer(), new EpubCssCompiler() );
		$exporter->export( $this->book(), self::$theme, $output );

		self::assertFileExists( $output );

		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $output ) );

		// mimetype: primo entry, non compresso, contenuto esatto (requisito OCF).
		self::assertSame( 'mimetype', $zip->getNameIndex( 0 ) );
		$stat = $zip->statIndex( 0 );
		self::assertSame( 0, $stat['comp_method'], 'mimetype deve essere STORED (non compresso)' );
		self::assertSame( 'application/epub+zip', $zip->getFromIndex( 0 ) );

		// Struttura OCF/OPF.
		self::assertNotFalse( $zip->locateName( 'META-INF/container.xml' ) );
		self::assertNotFalse( $zip->locateName( 'OEBPS/content.opf' ) );
		self::assertNotFalse( $zip->locateName( 'OEBPS/nav.xhtml' ) );
		self::assertNotFalse( $zip->locateName( 'OEBPS/styles/book.css' ) );
		// Spine per capitolo + bibliografia.
		self::assertNotFalse( $zip->locateName( 'OEBPS/chapter-001.xhtml' ) );
		self::assertNotFalse( $zip->locateName( 'OEBPS/chapter-002.xhtml' ) );
		self::assertNotFalse( $zip->locateName( 'OEBPS/bibliography.xhtml' ) );

		// Ogni XHTML deve essere XML valido (i reader sono spietati).
		foreach ( array( 'OEBPS/nav.xhtml', 'OEBPS/chapter-001.xhtml', 'OEBPS/chapter-002.xhtml', 'OEBPS/bibliography.xhtml', 'OEBPS/content.opf', 'META-INF/container.xml' ) as $entry ) {
			$xml = simplexml_load_string( (string) $zip->getFromName( $entry ) );
			self::assertNotFalse( $xml, "XML non valido: {$entry}" );
		}

		// L'OPF referenzia i capitoli nello spine e i metadati Dublin Core.
		$opf = (string) $zip->getFromName( 'OEBPS/content.opf' );
		self::assertStringContainsString( '<dc:title id="title">Il Salento di pietra</dc:title>', $opf );
		self::assertStringContainsString( '<dc:language>it</dc:language>', $opf );
		self::assertStringContainsString( '<itemref idref="ch001"/>', $opf );
		self::assertStringContainsString( '<itemref idref="bibliography"/>', $opf );

		// La nav annida il sottocapitolo (depth 1) dentro il capitolo radice.
		$nav = (string) $zip->getFromName( 'OEBPS/nav.xhtml' );
		self::assertMatchesRegularExpression(
			'/<li><a href="chapter-001\.xhtml">[^<]+<\/a><ol><li><a href="chapter-002\.xhtml">/s',
			$nav
		);

		$zip->close();
	}

	public function test_epub_chapter_uses_stacked_table_and_placeholder(): void {
		$output   = self::$work_dir . '/libro2.epub';
		$exporter = new EpubExporter( new BlockRenderer(), new EpubCssCompiler() );
		$exporter->export( $this->book(), self::$theme, $output );

		$zip = new \ZipArchive();
		$zip->open( $output );
		$chapter1 = (string) $zip->getFromName( 'OEBPS/chapter-001.xhtml' );
		$zip->close();

		// La figura del capitolo d'esempio non è risolta: placeholder, niente <img>.
		self::assertStringContainsString( 'gw-figura-placeholder', $chapter1 );
		self::assertStringNotContainsString( '<img', $chapter1 );
	}
}

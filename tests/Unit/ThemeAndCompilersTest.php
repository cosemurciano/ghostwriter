<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Rendering\Theme;
use Ghostwriter\Rendering\ThemeCompiler\EpubCssCompiler;
use Ghostwriter\Rendering\ThemeCompiler\MpdfCssCompiler;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class ThemeAndCompilersTest extends TestCase {

	private ThemeRegistry $registry;

	protected function setUp(): void {
		$this->registry = new ThemeRegistry(
			new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR ),
			dirname( __DIR__, 2 ) . '/themes-bundled'
		);
	}

	public function test_bundled_theme_loads_and_validates(): void {
		$theme = $this->registry->get( 'classico' );

		self::assertNotNull( $theme );
		self::assertSame( 'Classico', $theme->name() );
		self::assertSame( '1.0.0', $theme->version() );
		self::assertTrue( $theme->supports_format( 150, 230 ) );
		self::assertTrue( $theme->supports_format( 148, 210 ) );
		self::assertFalse( $theme->supports_format( 100, 100 ) );
		// Il tema di serie copre tutti i tipi di blocco del contratto dati.
		self::assertCount( 11, $theme->supports_blocks() );
	}

	public function test_theme_page_fragments_resolve_within_bundle(): void {
		$theme = $this->registry->get( 'classico' );

		$title_page = $theme->page_fragment( 'title_page' );
		self::assertNotNull( $title_page );
		self::assertStringContainsString( '{title}', $title_page );
		self::assertNull( $theme->page_fragment( 'cover_spine' ) );
	}

	public function test_mpdf_css_compilation(): void {
		$theme = $this->registry->get( 'classico' );
		$css   = ( new MpdfCssCompiler() )->compile( $theme );

		// Tipografia di base in pt con font normalizzati per mPDF.
		self::assertStringContainsString( 'font-size: 11pt;', $css );
		self::assertStringContainsString( 'font-family: dejavuserif;', $css );
		self::assertStringContainsString( 'line-height: 1.45;', $css );
		// Token della palette risolti nei valori.
		self::assertStringContainsString( 'border-left: 2pt solid #7a1f1f;', $css );
		self::assertStringContainsString( 'background-color: #f2ede4;', $css );
		// Direttive PDF: sillabazione, widows/orphans, apertura capitolo.
		self::assertStringContainsString( 'hyphens: auto;', $css );
		self::assertStringContainsString( 'widows: 2;', $css );
		self::assertStringContainsString( '.gw-chapter-opening', $css );
		self::assertStringContainsString( 'margin-top: 40mm;', $css );
		// Chiavi shorthand tradotte.
		self::assertStringContainsString( 'text-indent: 1.2em;', $css );
		self::assertStringContainsString( 'text-align: justify;', $css );
		// Regola annidata della didascalia figura.
		self::assertStringContainsString( '.gw-figura .gw-caption', $css );
	}

	public function test_epub_css_compilation_uses_safe_subset_and_fallbacks(): void {
		$theme = $this->registry->get( 'classico' );
		$css   = ( new EpubCssCompiler() )->compile( $theme );

		// respect_reader_settings: nessun font-size assoluto sul body.
		self::assertDoesNotMatchRegularExpression( '/body \{[^}]*font-size/s', $css );
		// Heading in em, non pt.
		self::assertMatchesRegularExpression( '/h2 \{[^}]*font-size: [0-9.]+em/s', $css );
		// Fallback del box: niente sfondo, bordo al suo posto.
		self::assertDoesNotMatchRegularExpression( '/\.gw-box_approfondimento \{[^}]*background-color/s', $css );
		self::assertMatchesRegularExpression( '/\.gw-box_approfondimento \{[^}]*border: 1pt solid #1a1a1a/s', $css );
		// Palette: variante epub_value ad alto contrasto.
		self::assertStringContainsString( '#5a0f0f', $css );
		self::assertStringNotContainsString( '#7a1f1f', $css );
		// La sillabazione è roba da PDF: mai nell'ePub.
		self::assertStringNotContainsString( 'hyphens', $css );
		// Niente @font-face: il tema di serie non ha font embeddabili.
		self::assertStringNotContainsString( '@font-face', $css );
	}

	public function test_registry_returns_null_for_unknown_theme(): void {
		self::assertNull( $this->registry->get( 'inesistente' ) );
		self::assertNull( $this->registry->get( 'classico', '9.9.9' ) );
	}

	public function test_import_zip_rejects_bundle_with_php(): void {
		$staging = sys_get_temp_dir() . '/gw-test-theme-' . bin2hex( random_bytes( 4 ) );
		mkdir( $staging, 0700, true );
		$zip_path = $staging . '/evil.zip';

		$theme_json = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/themes-bundled/classico/theme.json' ),
			true
		);
		$theme_json['meta']['name'] = 'Evil';
		unset( $theme_json['tokens']['fonts'], $theme_json['special_pages'] ); // Il re-encode da array degraderebbe "files": {}.

		$zip = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE );
		$zip->addFromString( 'theme.json', (string) json_encode( $theme_json ) );
		$zip->addFromString( 'assets/backdoor.php', '<?php evil();' );
		$zip->close();

		$registry = new ThemeRegistry(
			new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR ),
			dirname( __DIR__, 2 ) . '/themes-bundled',
			$staging . '/imported'
		);

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'PHP' );
			$registry->import_zip( $zip_path );
		} finally {
			@unlink( $zip_path ); // phpcs:ignore
		}
	}

	public function test_import_zip_registers_valid_theme(): void {
		$staging = sys_get_temp_dir() . '/gw-test-theme-' . bin2hex( random_bytes( 4 ) );
		mkdir( $staging, 0700, true );
		$zip_path = $staging . '/nuovo.zip';

		$theme_json                    = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/themes-bundled/classico/theme.json' ),
			true
		);
		$theme_json['meta']['name']    = 'Nuovo Tema';
		$theme_json['meta']['version'] = '2.1.0';
		// Il pacchetto non porta pages/ né font; il re-encode da array degraderebbe "files": {}.
		unset( $theme_json['special_pages'], $theme_json['tokens']['fonts'] );

		$zip = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE );
		$zip->addFromString( 'theme.json', (string) json_encode( $theme_json ) );
		$zip->close();

		$registry = new ThemeRegistry(
			new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR ),
			dirname( __DIR__, 2 ) . '/themes-bundled',
			$staging . '/imported'
		);

		$theme = $registry->import_zip( $zip_path );

		self::assertSame( 'nuovo-tema', $theme->id() );
		self::assertSame( '2.1.0', $theme->version() );
		self::assertNotNull( $registry->get( 'nuovo-tema', '2.1.0' ) );

		// Reimportare la stessa versione è un errore esplicito.
		$this->expectException( \RuntimeException::class );
		$registry->import_zip( $zip_path );
	}

	public function test_slugify(): void {
		self::assertSame( 'eb-garamond-classic', Theme::slugify( 'EB Garamond — Classic!' ) );
	}
}

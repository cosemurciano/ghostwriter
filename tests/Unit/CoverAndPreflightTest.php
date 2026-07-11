<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\MockProvider;
use Ghostwriter\Media\CoverComposer;
use Ghostwriter\Queue\Jobs\CoverArtworkJob;
use Ghostwriter\Rendering\Preflight;
use Ghostwriter\Rendering\Theme;
use Ghostwriter\Rendering\ThemeRegistry;
use Ghostwriter\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class CoverAndPreflightTest extends TestCase {

	private Theme $theme;
	private SchemaValidator $validator;

	/** @var array<string, mixed> */
	private array $example;

	protected function setUp(): void {
		$registry        = new ThemeRegistry( new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR ), dirname( __DIR__, 2 ) . '/themes-bundled' );
		$this->theme     = $registry->get( 'classico' ) ?? throw new \RuntimeException( 'tema mancante' );
		$this->validator = new SchemaValidator( GHOSTWRITER_SCHEMAS_DIR );
		$this->example   = json_decode( (string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ), true );
	}

	private function preflight( array $paths = array(), array $sizes = array() ): Preflight {
		return new Preflight(
			$this->validator,
			static fn( int $id ): ?string => $paths[ $id ] ?? null,
			static fn( string $path ): ?array => $sizes[ $path ] ?? array( 2000, 1500 )
		);
	}

	/** @return array<string, mixed> */
	private function config(): array {
		return array(
			'schema_version'     => '1.0',
			'language'           => 'it',
			'format'             => array( 'trim_width_mm' => 150, 'trim_height_mm' => 230 ),
			'structural_profile' => array( 'allowed_blocks' => array( 'paragrafo', 'heading', 'citazione', 'box_approfondimento', 'figura', 'elenco' ) ),
			'skills'             => array(),
			'sources'            => array(
				'registry' => array(
					array( 'source_id' => 'src-catasto-1750', 'type' => 'book', 'title' => 'Catasto Onciario', 'license' => 'pubblico dominio' ),
					array( 'source_id' => 'src-cosi-1989', 'type' => 'book', 'title' => 'Torri marittime', 'license' => 'proprietaria', 'citation' => 'G. Cosi, 1989.' ),
				),
			),
			'ai'                 => array( 'provider' => 'mock', 'model' => 'mock-1' ),
		);
	}

	// --- Preflight -----------------------------------------------------------

	public function test_preflight_flags_unresolved_figures_and_unfulfilled_promises(): void {
		$dossier = array(
			'continuity' => array(
				'promises' => array(
					array( 'text' => 'Approfondiremo le torri costiere', 'made_in' => 1, 'target_chapter' => null, 'fulfilled' => false ),
				),
			),
		);

		$report = $this->preflight()->run( array( 412 => $this->example ), $this->config(), $dossier, $this->theme, 'pdf' );

		$all_errors = implode( ' ', $report['errors'] );
		// La figura del capitolo d'esempio è un placeholder.
		self::assertStringContainsString( 'figura b1f0a2c4-0004 senza immagine', $all_errors );
		self::assertStringContainsString( 'Promessa al lettore non mantenuta', $all_errors );
	}

	public function test_preflight_passes_when_everything_is_resolved(): void {
		$content = $this->example;
		// Risolvi la figura e completa le promesse.
		$content['blocks'][3]['props']['attachment_id'] = 42;

		$report = $this->preflight( array( 42 => __FILE__ ) )->run(
			array( 412 => $content ),
			$this->config(),
			array( 'continuity' => array( 'promises' => array( array( 'text' => 'x', 'made_in' => 1, 'target_chapter' => null, 'fulfilled' => true ) ) ) ),
			$this->theme,
			'pdf'
		);

		self::assertSame( array(), $report['errors'], implode( ' | ', $report['errors'] ) );
	}

	public function test_preflight_flags_missing_note_and_unknown_source(): void {
		$content = $this->example;
		$content['blocks'][3]['props']['attachment_id'] = 42;
		$content['notes'] = array(); // La nota n1 resta referenziata nel testo.
		$content['blocks'][2]['sources'][0]['source_id'] = 'src-inesistente';

		$report = $this->preflight( array( 42 => __FILE__ ) )->run( array( 412 => $content ), $this->config(), array(), $this->theme, 'pdf' );

		$all_errors = implode( ' ', $report['errors'] );
		self::assertStringContainsString( 'nota [n1]', $all_errors );
		self::assertStringContainsString( 'src-inesistente', $all_errors );
	}

	public function test_preflight_blocks_uncovered_blocks_and_wrong_format(): void {
		$config = $this->config();
		$config['structural_profile']['allowed_blocks'][] = 'blocco_futuro';
		$config['format']['trim_width_mm']                = 300;

		$report = $this->preflight()->run( array(), $config, array(), $this->theme, 'pdf' );

		$all_errors = implode( ' ', $report['errors'] );
		self::assertStringContainsString( 'blocco_futuro', $all_errors );
		self::assertStringContainsString( 'non supporta il formato', $all_errors );
	}

	public function test_preflight_low_resolution_is_warning_for_print(): void {
		$config                          = $this->config();
		$config['format']['print_ready'] = true;
		$content                         = $this->example;
		$content['blocks'][3]['props']['attachment_id'] = 42;

		$fake = tempnam( sys_get_temp_dir(), 'gw-img' );
		file_put_contents( $fake, 'x' );

		$report = $this->preflight(
			array( 42 => $fake ),
			array( $fake => array( 300, 200 ) )
		)->run( array( 412 => $content ), $config, array(), $this->theme, 'pdf' );
		unlink( $fake );

		self::assertNotEmpty( $report['warnings'] );
		self::assertStringContainsString( 'bassa risoluzione', implode( ' ', $report['warnings'] ) );
	}

	public function test_preflight_helpers_note_refs_and_sources(): void {
		self::assertSame( array( 'n1' ), Preflight::note_refs( $this->example['blocks'] ) );
		self::assertSame( array( 'src-catasto-1750', 'src-cosi-1989' ), Preflight::used_source_ids( $this->example['blocks'] ) );
	}

	// --- CoverComposer ---------------------------------------------------------

	public function test_compose_without_artwork_produces_png_with_right_size(): void {
		$png = ( new CoverComposer() )->compose( null, 'Il Salento di pietra', 'Masserie e torri', 'Cosè Murciano', 600, 900 );

		$info = getimagesizefromstring( $png );
		self::assertNotFalse( $info );
		self::assertSame( 600, $info[0] );
		self::assertSame( 900, $info[1] );
		self::assertSame( 'image/png', $info['mime'] );
	}

	public function test_compose_with_artwork_cover_crops(): void {
		// Artwork di prova 400x300 rosso.
		$art = imagecreatetruecolor( 400, 300 );
		imagefilledrectangle( $art, 0, 0, 400, 300, imagecolorallocate( $art, 200, 30, 30 ) );
		$path = sys_get_temp_dir() . '/gw-art-' . bin2hex( random_bytes( 4 ) ) . '.png';
		imagepng( $art, $path );

		$png  = ( new CoverComposer() )->compose( $path, 'Titolo', null, 'Autore', 300, 450 );
		$info = getimagesizefromstring( $png );

		self::assertSame( array( 300, 450 ), array( $info[0], $info[1] ) );
		unlink( $path );
	}

	// --- Fase cover / risoluzione -----------------------------------------------

	public function test_mock_provider_cover_brief_forbids_text(): void {
		$result = ( new MockProvider() )->complete( new AiRequest( AiRequest::PHASE_COVER, array(), 7 ) );

		self::assertNotSame( '', $result->content['creative_brief'] );
		self::assertStringContainsString( 'nessun testo', strtolower( $result->content['creative_brief'] ) );
	}

	public function test_cover_resolution_includes_bleed_when_print_ready(): void {
		$screen = CoverArtworkJob::cover_resolution( array( 'format' => array( 'trim_width_mm' => 150, 'trim_height_mm' => 230 ) ) );
		$print  = CoverArtworkJob::cover_resolution(
			array( 'format' => array( 'trim_width_mm' => 150, 'trim_height_mm' => 230, 'print_ready' => true, 'bleed_mm' => 3 ) )
		);

		// 150mm@150dpi ≈ 886px; (150+6)mm@300dpi ≈ 1843px.
		self::assertEqualsWithDelta( 886, $screen[0], 3 );
		self::assertEqualsWithDelta( 1843, $print[0], 5 );
		self::assertGreaterThan( $screen[1], $print[1] );
	}
}

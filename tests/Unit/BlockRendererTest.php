<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Rendering\BlockRenderer;
use PHPUnit\Framework\TestCase;

final class BlockRendererTest extends TestCase {

	private BlockRenderer $renderer;

	/** @var array<string, mixed> */
	private array $example;

	protected function setUp(): void {
		$this->renderer = new BlockRenderer();
		$this->example  = json_decode(
			(string) file_get_contents( GHOSTWRITER_EXAMPLES_DIR . '/chapter.example.json' ),
			true
		);
	}

	public function test_example_chapter_pdf_profile(): void {
		$html = $this->renderer->render_chapter( $this->example, BlockRenderer::PROFILE_PDF );

		// Classi stabili per blocco.
		self::assertStringContainsString( 'class="gw-block gw-paragrafo gw-role-lead"', $html );
		self::assertStringContainsString( 'gw-block gw-heading', $html );
		self::assertStringContainsString( 'gw-block gw-citazione gw-display-block', $html );
		self::assertStringContainsString( 'gw-block gw-box_approfondimento gw-variant-approfondimento', $html );
		// Epigrafe dal meta.
		self::assertStringContainsString( 'gw-epigraph', $html );
		self::assertStringContainsString( 'La pietra racconta', $html );
		// Figura non risolta → placeholder con l'image brief.
		self::assertStringContainsString( 'gw-figura-placeholder', $html );
		self::assertStringContainsString( 'stile acquerello', $html );
		// Nota: riferimento numerato e sezione endnote.
		self::assertStringContainsString( '<sup class="gw-noteref"><a href="#gw-note-n1">1</a></sup>', $html );
		self::assertStringContainsString( '<section class="gw-notes">', $html );
		self::assertStringContainsString( 'id="gw-note-n1"', $html );
		// RichText nell'elenco annidato nel box.
		self::assertStringContainsString( '<em>Caditoie</em>', $html );
	}

	public function test_example_chapter_epub_profile_has_epub_types(): void {
		$html = $this->renderer->render_chapter( $this->example, BlockRenderer::PROFILE_EPUB );

		self::assertStringContainsString( 'epub:type="endnotes"', $html );
	}

	public function test_figure_resolution_via_option(): void {
		$block = array(
			'id'    => 'f1',
			'type'  => 'figura',
			'props' => array(
				'attachment_id' => 42,
				'caption'       => 'Didascalia *breve*',
				'alt'           => 'Alt text',
				'size'          => 'full',
			),
		);

		$html = $this->renderer->render_block(
			$block,
			BlockRenderer::PROFILE_PDF,
			array( 'image_src' => static fn( ?int $id ): ?string => 42 === $id ? '/tmp/img-42.jpg' : null )
		);

		self::assertStringContainsString( '<img src="/tmp/img-42.jpg" alt="Alt text" />', $html );
		self::assertStringContainsString( 'gw-size-full', $html );
		self::assertStringContainsString( '<figcaption class="gw-caption">Didascalia <em>breve</em></figcaption>', $html );
	}

	public function test_pull_quote_degrades_on_epub(): void {
		$block = array(
			'id'    => 'q1',
			'type'  => 'citazione',
			'props' => array(
				'text'    => 'Una citazione a effetto.',
				'display' => 'pull',
			),
		);

		$pdf  = $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF );
		$epub = $this->renderer->render_block( $block, BlockRenderer::PROFILE_EPUB );

		self::assertStringContainsString( 'gw-display-pull', $pdf );
		self::assertStringNotContainsString( 'gw-display-pull', $epub );
		self::assertStringContainsString( 'gw-display-block', $epub );
	}

	public function test_table_stacked_fallback_on_epub(): void {
		$block = array(
			'id'    => 't1',
			'type'  => 'tabella',
			'props' => array(
				'header' => array( 'Anno', 'Strutture' ),
				'rows'   => array( array( '1750', '212' ), array( '1800', '245' ) ),
			),
		);

		$pdf  = $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF );
		$epub = $this->renderer->render_block( $block, BlockRenderer::PROFILE_EPUB );

		self::assertStringContainsString( '<table', $pdf );
		self::assertStringContainsString( '<th>Anno</th>', $pdf );
		self::assertStringNotContainsString( '<table', $epub );
		self::assertStringContainsString( 'gw-stacked', $epub );
		self::assertStringContainsString( '<dt>Anno</dt>', $epub );
		self::assertStringContainsString( '<dd>1750</dd>', $epub );
	}

	public function test_table_scroll_fallback_keeps_table_on_epub(): void {
		$block = array(
			'id'    => 't2',
			'type'  => 'tabella',
			'props' => array(
				'rows'          => array( array( 'a', 'b' ) ),
				'epub_fallback' => 'scroll',
			),
		);

		self::assertStringContainsString( '<table', $this->renderer->render_block( $block, BlockRenderer::PROFILE_EPUB ) );
	}

	public function test_heading_emits_tocentry_only_for_pdf_within_depth(): void {
		$block = array(
			'id'    => 'h1',
			'type'  => 'heading',
			'props' => array(
				'text'  => 'Origini e funzione',
				'level' => 2,
			),
		);

		$with_toc = $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF, array( 'toc_depth' => 2 ) );
		$no_toc   = $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF, array( 'toc_depth' => 0 ) );
		$epub     = $this->renderer->render_block( $block, BlockRenderer::PROFILE_EPUB, array( 'toc_depth' => 2 ) );

		self::assertStringContainsString( '<tocentry content="Origini e funzione" level="1" />', $with_toc );
		self::assertStringNotContainsString( '<tocentry', $no_toc );
		self::assertStringNotContainsString( '<tocentry', $epub );
	}

	public function test_blurb_is_not_rendered_in_body(): void {
		$block = array(
			'id'    => 'bl1',
			'type'  => 'blurb',
			'props' => array( 'text' => 'Un libro imperdibile.' ),
		);

		self::assertSame( '', $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF ) );
	}

	public function test_code_block_is_escaped(): void {
		$block = array(
			'id'    => 'c1',
			'type'  => 'codice',
			'props' => array(
				'code'     => '<?php echo "ciao"; ?>',
				'language' => 'php',
			),
		);

		$html = $this->renderer->render_block( $block, BlockRenderer::PROFILE_PDF );
		self::assertStringContainsString( '&lt;?php echo &quot;ciao&quot;; ?&gt;', $html );
		self::assertStringContainsString( 'gw-lang-php', $html );
	}

	public function test_unknown_profile_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->renderer->render_chapter( $this->example, 'stampa' );
	}
}

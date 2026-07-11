<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

use Ghostwriter\Rendering\ThemeCompiler\MpdfCssCompiler;
use Ghostwriter\Rendering\ThemeCompiler\StyleResolver;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Orchestrazione mPDF: il punto di massimo riuso da BookCreator
 * (mirrorMargins, TOC nativo tocpagebreak/tocentry, testatine per capitolo
 * via htmlpageheader/sethtmlpageheader, immagini da path locale).
 *
 * Correzioni rispetto a BookCreator: tempDir esplicita (lì mancava e i libri
 * lunghi esaurivano il default), font custom registrati via fontdata (mPDF
 * non supporta woff2 in @font-face), nessuna dipendenza dal tema WP attivo.
 */
final class PdfExporter {

	public function __construct(
		private BlockRenderer $renderer,
		private MpdfCssCompiler $compiler,
		private ?string $temp_dir = null
	) {
	}

	/**
	 * Esporta il libro in PDF sul path indicato.
	 *
	 * @throws \RuntimeException Se il formato non è supportato dal tema o l'output non è un PDF valido.
	 */
	public function export( BookData $book, Theme $theme, string $output_path ): void {
		if ( ! $theme->supports_format( $book->trim_width_mm, $book->trim_height_mm ) ) {
			throw new \RuntimeException(
				sprintf(
					'Il tema %s non supporta il formato %sx%s mm.',
					$theme->name(),
					$book->trim_width_mm,
					$book->trim_height_mm
				)
			);
		}

		$mpdf = $this->create_mpdf( $book, $theme );

		$mpdf->SetTitle( $book->title );
		$mpdf->SetAuthor( $book->author );
		$mpdf->SetCreator( 'Ghostwriter' );

		$mpdf->WriteHTML( $this->compiler->compile( $theme ), HTMLParserMode::HEADER_CSS );
		$mpdf->WriteHTML( $this->build_body( $book, $theme ), HTMLParserMode::HTML_BODY );

		$mpdf->Output( $output_path, Destination::FILE );

		// Difesa collaudata in BookCreator: mPDF può produrre output corrotto
		// senza lanciare; si verifica la firma.
		$head = (string) file_get_contents( $output_path, false, null, 0, 5 );
		if ( '%PDF-' !== $head ) {
			@unlink( $output_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new \RuntimeException( 'Output mPDF non valido (firma %PDF assente).' );
		}
	}

	private function create_mpdf( BookData $book, Theme $theme ): Mpdf {
		$margins = (array) ( $theme->page()['margins_mm'] ?? array() );

		$temp_dir = $this->temp_dir ?? sys_get_temp_dir() . '/gw-mpdf';
		if ( ! is_dir( $temp_dir ) ) {
			mkdir( $temp_dir, 0700, true );
		}

		$config = array(
			'mode'                => 'utf-8',
			'format'              => array( $book->trim_width_mm, $book->trim_height_mm ),
			'tempDir'             => $temp_dir,
			// margin_left = interno, margin_right = esterno: con mirrorMargins
			// mPDF scambia i margini sulle pagine pari (strada BookCreator).
			'margin_left'         => (float) ( $margins['inner'] ?? 20 ),
			'margin_right'        => (float) ( $margins['outer'] ?? 18 ),
			'margin_top'          => (float) ( $margins['top'] ?? 20 ),
			'margin_bottom'       => (float) ( $margins['bottom'] ?? 20 ),
			'margin_header'       => 8,
			'margin_footer'       => 10,
			'mirrorMargins'       => true,
			'setAutoTopMargin'    => 'pad',
			'setAutoBottomMargin' => 'pad',
		);

		$config = $this->add_theme_fonts( $config, $theme );

		return new Mpdf( $config );
	}

	/**
	 * Registra i font del tema (file ttf/otf in fonts/ del pacchetto) nella
	 * fontdata mPDF. I font senza file (es. DejaVu di serie) non servono qui.
	 *
	 * @param array<string, mixed> $config Config mPDF.
	 * @return array<string, mixed>
	 */
	private function add_theme_fonts( array $config, Theme $theme ): array {
		$font_dir = $theme->base_dir() . '/fonts';
		$fontdata = array();

		$variant_keys = array(
			'regular'     => 'R',
			'italic'      => 'I',
			'bold'        => 'B',
			'bold_italic' => 'BI',
		);

		foreach ( $theme->fonts() as $font ) {
			if ( empty( $font['family'] ) || empty( $font['files'] ) ) {
				continue;
			}
			$entry = array();
			foreach ( (array) $font['files'] as $variant => $file ) {
				if ( isset( $variant_keys[ $variant ] ) && is_string( $file ) && file_exists( $font_dir . '/' . $file ) ) {
					$entry[ $variant_keys[ $variant ] ] = basename( $file );
				}
			}
			if ( ! empty( $entry['R'] ) ) {
				$fontdata[ StyleResolver::mpdf_family_key( (string) $font['family'] ) ] = $entry;
			}
		}

		if ( ! empty( $fontdata ) ) {
			$defaults           = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
			$font_defaults      = ( new \Mpdf\Config\FontVariables() )->getDefaults();
			$config['fontDir']  = array_merge( $defaults['fontDir'], array( $font_dir ) );
			$config['fontdata'] = array_merge( $font_defaults['fontdata'], $fontdata );
		}

		return $config;
	}

	private function build_body( BookData $book, Theme $theme ): string {
		$page = $theme->page();
		$pdf  = $theme->pdf();

		$html = $this->page_footers( $page );

		// --- Copertina composta: piena pagina, prima di tutto ---
		if ( null !== $book->cover_path && file_exists( $book->cover_path ) ) {
			$html .= '<div style="position: absolute; left: 0; top: 0; width: ' . $book->trim_width_mm . 'mm; height: ' . $book->trim_height_mm . 'mm;">'
				. '<img src="' . htmlspecialchars( $book->cover_path, ENT_QUOTES, 'UTF-8' ) . '" style="width: ' . $book->trim_width_mm . 'mm; height: ' . $book->trim_height_mm . 'mm;" />'
				. '</div><pagebreak />';
		}

		// --- Front matter (prima del TOC, senza testatine) ---
		foreach ( array( 'half_title', 'title_page', 'colophon' ) as $i => $key ) {
			$fragment = $theme->page_fragment( $key );
			if ( null === $fragment ) {
				continue;
			}
			if ( $i > 0 ) {
				$html .= '<pagebreak />';
			}
			$html .= $book->fill_placeholders( $fragment );
		}

		// --- Indice (TOC nativo mPDF, numerazione che riparte dal corpo) ---
		// Il vincolo recto/verso del primo capitolo sta sul break del TOC
		// stesso: un ulteriore pagebreak esplicito subito dopo produrrebbe
		// pagine bianche spurie (il tocpagebreak salta già pagina).
		$chapter_break = (string) ( $page['chapter_start']['on'] ?? 'recto' );
		$break_type    = match ( $chapter_break ) {
			'recto' => 'next-odd',
			'verso' => 'next-even',
			default => '',
		};

		$toc_depth = (int) ( $pdf['toc']['depth'] ?? 2 );
		$html     .= '<tocpagebreak links="1" toc-suppress="1" '
			. ( '' !== $break_type ? 'type="' . $break_type . '" ' : '' )
			. 'toc-preHTML="&lt;h2 class=&quot;gw-toc-title&quot;&gt;Indice&lt;/h2&gt;" '
			. 'paging="on" resetpagenum="1" pagenumstyle="1" suppress="off" />';

		// --- Capitoli ---
		$headers  = (array) ( $page['running_headers'] ?? array() );
		$even_tpl = (string) ( $headers['even'] ?? '' );
		$odd_tpl  = (string) ( $headers['odd'] ?? '' );

		foreach ( $book->chapters as $i => $chapter ) {
			$html .= $this->chapter_html( $book, $chapter, $i, $chapter_break, $toc_depth, $even_tpl, $odd_tpl );
		}

		// --- Bibliografia dal registry (mai a memoria del modello) ---
		if ( ! empty( $book->bibliography ) ) {
			$html .= '<pagebreak type="next-odd" />';
			$html .= '<tocentry content="Bibliografia" level="0" />';
			$html .= '<h1 class="gw-chapter-title">Bibliografia</h1><ul class="gw-bibliografia">';
			foreach ( $book->bibliography as $entry ) {
				$html .= '<li>' . RichText::render( $entry ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Footer di pagina dal tema (numero pagina; posizione outer/center/inner:
	 * outer = destra sulle dispari e sinistra sulle pari, inner viceversa).
	 *
	 * @param array<string, mixed> $page Direttive di pagina del tema.
	 */
	private function page_footers( array $page ): string {
		$content = (string) ( $page['footer']['content'] ?? '{page_number}' );
		$content = htmlspecialchars( $content, ENT_QUOTES, 'UTF-8' );
		$content = str_replace( '{page_number}', '{PAGENO}', $content );

		$position   = (string) ( $page['footer']['position'] ?? 'outer' );
		$align_odd  = match ( $position ) {
			'center' => 'center',
			'inner'  => 'left',
			default  => 'right',
		};
		$align_even = match ( $position ) {
			'center' => 'center',
			'inner'  => 'right',
			default  => 'left',
		};

		return '<htmlpagefooter name="gw-f-odd"><div style="text-align: ' . $align_odd . '; font-size: 8.5pt;">' . $content . '</div></htmlpagefooter>'
			. '<htmlpagefooter name="gw-f-even"><div style="text-align: ' . $align_even . '; font-size: 8.5pt;">' . $content . '</div></htmlpagefooter>';
	}

	/**
	 * @param array{id: int, title: string, depth: int, content: array<string, mixed>} $chapter Capitolo assemblato.
	 */
	private function chapter_html(
		BookData $book,
		array $chapter,
		int $index,
		string $chapter_break,
		int $toc_depth,
		string $even_tpl,
		string $odd_tpl
	): string {
		$title = htmlspecialchars( $chapter['title'], ENT_QUOTES, 'UTF-8' );
		$depth = max( 0, (int) $chapter['depth'] );

		// Apertura: recto (pagina destra, con eventuale bianca), verso o libera.
		// Il primo capitolo non salta: ci pensa il break del tocpagebreak.
		// TODO(fase 6, checklist visiva): sopprimere le testatine sulla pagina
		// bianca inserita — l'attributo suppress di <pagebreak> in mPDF genera
		// pagine spurie, serve un'altra strada (es. DeletePages/SetHTMLHeader).
		$html = 0 === $index ? '' : match ( $chapter_break ) {
			'recto' => '<pagebreak type="next-odd" />',
			'verso' => '<pagebreak type="next-even" />',
			default => '<pagebreak />',
		};

		$html .= $this->running_headers( $book, $chapter, $index, $even_tpl, $odd_tpl );

		$html .= '<tocentry content="' . $title . '" level="' . $depth . '" />';
		$html .= '<div class="gw-chapter-opening"><h1 class="gw-chapter-title">' . $title . '</h1></div>';

		$html .= $this->renderer->render_chapter(
			$chapter['content'],
			BlockRenderer::PROFILE_PDF,
			array(
				'image_src' => fn( ?int $attachment_id ): ?string => $book->image_path( $attachment_id ),
				'toc_depth' => $toc_depth,
			)
		);

		return $html;
	}

	/**
	 * Testatine per capitolo: mPDF non ha variabili di capitolo, quindi i
	 * placeholder del tema ({book_title}, {chapter_title}, {author},
	 * {page_number}) vengono risolti qui, con una coppia di header nominati
	 * per capitolo (tecnica BookCreator). I footer si attivano dal primo capitolo.
	 *
	 * @param array{id: int, title: string, depth: int, content: array<string, mixed>} $chapter Capitolo assemblato.
	 */
	private function running_headers( BookData $book, array $chapter, int $index, string $even_tpl, string $odd_tpl ): string {
		$html = '<sethtmlpagefooter name="gw-f-odd" page="ODD" value="ON" />'
			. '<sethtmlpagefooter name="gw-f-even" page="EVEN" value="ON" />';

		if ( '' === $even_tpl && '' === $odd_tpl ) {
			return $html;
		}

		$values = array_merge(
			$book->placeholders(),
			array(
				'{chapter_title}' => $chapter['title'],
				'{author}'        => $book->author,
			)
		);
		$safe = array( '{page_number}' => '{PAGENO}' );
		foreach ( $values as $key => $value ) {
			$safe[ $key ] = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		}

		$name_even = 'gw-h-even-' . $index;
		$name_odd  = 'gw-h-odd-' . $index;
		$style     = 'font-size: 8.5pt; font-variant: small-caps;';

		if ( '' !== $even_tpl ) {
			$html .= '<htmlpageheader name="' . $name_even . '"><div style="text-align: left; ' . $style . '">' . strtr( $even_tpl, $safe ) . '</div></htmlpageheader>'
				. '<sethtmlpageheader name="' . $name_even . '" page="EVEN" value="ON" />';
		}
		if ( '' !== $odd_tpl ) {
			$html .= '<htmlpageheader name="' . $name_odd . '"><div style="text-align: right; ' . $style . '">' . strtr( $odd_tpl, $safe ) . '</div></htmlpageheader>'
				. '<sethtmlpageheader name="' . $name_odd . '" page="ODD" value="ON" show-this-page="0" />';
		}

		return $html;
	}
}

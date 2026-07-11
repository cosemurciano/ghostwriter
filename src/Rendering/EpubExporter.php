<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

use Ghostwriter\Rendering\ThemeCompiler\EpubCssCompiler;

/**
 * Builder EPUB 3 interno (XHTML + zip, controllo diretto sui fallback —
 * ARCHITECTURE.md §1). A differenza di BookCreator: un file XHTML per
 * capitolo (spine navigabile, reader più fluidi sui libri lunghi).
 *
 * Struttura: mimetype (primo entry, non compresso) · META-INF/container.xml
 * · OEBPS/content.opf · OEBPS/nav.xhtml · OEBPS/chapter-NNN.xhtml
 * · OEBPS/styles/book.css · OEBPS/images/ · OEBPS/fonts/.
 */
final class EpubExporter {

	public function __construct(
		private BlockRenderer $renderer,
		private EpubCssCompiler $compiler
	) {
	}

	/**
	 * Esporta il libro in ePub sul path indicato.
	 *
	 * @throws \RuntimeException Se lo zip non è creabile.
	 */
	public function export( BookData $book, Theme $theme, string $output_path ): void {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( "Impossibile creare l'ePub in {$output_path}." );
		}

		// mimetype: primo entry, MAI compresso (requisito OCF).
		$zip->addFromString( 'mimetype', 'application/epub+zip' );
		$zip->setCompressionName( 'mimetype', \ZipArchive::CM_STORE );

		$zip->addFromString( 'META-INF/container.xml', $this->container_xml() );
		$zip->addFromString( 'OEBPS/styles/book.css', $this->compiler->compile( $theme ) );

		// Copertina composta (se disponibile).
		$has_cover = null !== $book->cover_path && file_exists( $book->cover_path );
		if ( $has_cover ) {
			$cover_ext = strtolower( pathinfo( $book->cover_path, PATHINFO_EXTENSION ) ?: 'png' );
			$zip->addFile( $book->cover_path, 'OEBPS/images/cover.' . $cover_ext );
			$zip->addFromString(
				'OEBPS/cover.xhtml',
				$this->xhtml_document(
					$book->language,
					self::esc( $book->title ),
					'<section epub:type="cover" style="text-align:center;margin:0;padding:0">'
					. '<img src="images/cover.' . $cover_ext . '" alt="' . self::esc( $book->title ) . '" style="max-width:100%"/></section>'
				)
			);
		}

		// Capitoli: le immagini usate vengono raccolte durante il rendering.
		$used_images = array();
		$chapters    = array();
		foreach ( $book->chapters as $i => $chapter ) {
			$filename   = sprintf( 'chapter-%03d.xhtml', $i + 1 );
			$chapters[] = array(
				'file'  => $filename,
				'id'    => sprintf( 'ch%03d', $i + 1 ),
				'title' => $chapter['title'],
				'depth' => max( 0, (int) $chapter['depth'] ),
			);
			$zip->addFromString( 'OEBPS/' . $filename, $this->chapter_xhtml( $book, $chapter, $used_images ) );
		}

		// Bibliografia come documento finale.
		if ( ! empty( $book->bibliography ) ) {
			$chapters[] = array(
				'file'  => 'bibliography.xhtml',
				'id'    => 'bibliography',
				'title' => 'Bibliografia',
				'depth' => 0,
			);
			$zip->addFromString( 'OEBPS/bibliography.xhtml', $this->bibliography_xhtml( $book ) );
		}

		// Immagini effettivamente referenziate.
		$image_items = array();
		foreach ( $used_images as $attachment_id => $target ) {
			$path = $book->image_path( (int) $attachment_id );
			if ( null !== $path && file_exists( $path ) ) {
				$zip->addFile( $path, 'OEBPS/' . $target );
				$image_items[] = $target;
			}
		}

		// Font embeddabili dichiarati dal tema.
		$font_items = array();
		if ( ! empty( $theme->epub()['embed_fonts'] ) ) {
			foreach ( $theme->fonts() as $font ) {
				if ( empty( $font['embeddable'] ) || empty( $font['files'] ) ) {
					continue;
				}
				foreach ( (array) $font['files'] as $file ) {
					$path = $theme->base_dir() . '/fonts/' . $file;
					if ( is_string( $file ) && file_exists( $path ) ) {
						$target = 'fonts/' . basename( $file );
						$zip->addFile( $path, 'OEBPS/' . $target );
						$font_items[] = $target;
					}
				}
			}
		}

		$zip->addFromString( 'OEBPS/nav.xhtml', $this->nav_xhtml( $book, $chapters ) );
		$zip->addFromString( 'OEBPS/content.opf', $this->content_opf( $book, $chapters, $image_items, $font_items ) );

		if ( ! $zip->close() ) {
			throw new \RuntimeException( 'Scrittura ePub fallita.' );
		}
	}

	private function container_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
			. '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
			. '</container>';
	}

	/**
	 * @param array{id: int, title: string, depth: int, content: array<string, mixed>} $chapter     Capitolo assemblato.
	 * @param array<int, string>                                                       $used_images Mappa attachment_id → path relativo nello zip (accumulata).
	 */
	private function chapter_xhtml( BookData $book, array $chapter, array &$used_images ): string {
		$body = $this->renderer->render_chapter(
			$chapter['content'],
			BlockRenderer::PROFILE_EPUB,
			array(
				'image_src' => static function ( ?int $attachment_id ) use ( $book, &$used_images ): ?string {
					$path = $book->image_path( $attachment_id );
					if ( null === $path || null === $attachment_id ) {
						return null;
					}
					$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ?: 'jpg' );
					$used_images[ $attachment_id ] = 'images/img-' . $attachment_id . '.' . $ext;
					return $used_images[ $attachment_id ];
				},
			)
		);

		$title = self::esc( $chapter['title'] );

		return $this->xhtml_document(
			$book->language,
			$title,
			'<section epub:type="chapter"><h1 class="gw-chapter-title">' . $title . '</h1>' . "\n" . $body . '</section>'
		);
	}

	private function bibliography_xhtml( BookData $book ): string {
		$items = '';
		foreach ( $book->bibliography as $entry ) {
			$items .= '<li>' . RichText::render( $entry ) . '</li>';
		}
		return $this->xhtml_document(
			$book->language,
			'Bibliografia',
			'<section epub:type="bibliography"><h1 class="gw-chapter-title">Bibliografia</h1><ul class="gw-bibliografia">' . $items . '</ul></section>'
		);
	}

	/**
	 * @param array<int, array{file: string, id: string, title: string, depth: int}> $chapters Voci spine.
	 */
	private function nav_xhtml( BookData $book, array $chapters ): string {
		// Albero di navigazione dalla sequenza (depth relativa alla voce precedente).
		$html  = '<nav epub:type="toc" id="toc"><h2>Indice</h2>';
		$html .= $this->nav_list( $chapters, 0 );
		$html .= '</nav>';

		return $this->xhtml_document( $book->language, 'Indice', $html );
	}

	/**
	 * Costruisce ricorsivamente gli <ol> annidati della nav dal vettore piatto.
	 *
	 * @param array<int, array{file: string, id: string, title: string, depth: int}> $chapters Voci residue.
	 */
	private function nav_list( array $chapters, int $depth ): string {
		$html = '<ol>';
		$i    = 0;
		$n    = count( $chapters );

		while ( $i < $n ) {
			$entry = $chapters[ $i ];
			if ( $entry['depth'] < $depth ) {
				break;
			}

			// Raccoglie i figli (depth maggiore immediatamente successiva).
			$children = array();
			$j        = $i + 1;
			while ( $j < $n && $chapters[ $j ]['depth'] > $depth ) {
				$children[] = $chapters[ $j ];
				$j++;
			}

			$html .= '<li><a href="' . self::esc( $entry['file'] ) . '">' . self::esc( $entry['title'] ) . '</a>';
			if ( ! empty( $children ) ) {
				$html .= $this->nav_list( $children, $depth + 1 );
			}
			$html .= '</li>';

			$i = $j;
		}

		return $html . '</ol>';
	}

	/**
	 * @param array<int, array{file: string, id: string, title: string, depth: int}> $chapters    Voci spine.
	 * @param string[]                                                               $image_items Path relativi immagini.
	 * @param string[]                                                               $font_items  Path relativi font.
	 */
	private function content_opf( BookData $book, array $chapters, array $image_items, array $font_items ): string {
		$identifier = $book->identifier ?: 'urn:uuid:' . self::uuid_from( $book->title . '|' . $book->author );
		$modified   = gmdate( 'Y-m-d\TH:i:s\Z' );

		$manifest = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
			. '<item id="css" href="styles/book.css" media-type="text/css"/>';
		$spine    = '';

		if ( null !== $book->cover_path && file_exists( $book->cover_path ) ) {
			$cover_ext  = strtolower( pathinfo( $book->cover_path, PATHINFO_EXTENSION ) ?: 'png' );
			$cover_mime = 'jpg' === $cover_ext || 'jpeg' === $cover_ext ? 'image/jpeg' : 'image/png';
			$manifest  .= '<item id="cover-image" href="images/cover.' . $cover_ext . '" media-type="' . $cover_mime . '" properties="cover-image"/>'
				. '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
			$spine     .= '<itemref idref="cover" linear="yes"/>';
		}

		foreach ( $chapters as $entry ) {
			$manifest .= '<item id="' . $entry['id'] . '" href="' . self::esc( $entry['file'] ) . '" media-type="application/xhtml+xml"/>';
			$spine    .= '<itemref idref="' . $entry['id'] . '"/>';
		}

		$media_types = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
			'webp' => 'image/webp',
			'ttf'  => 'font/ttf',
			'otf'  => 'font/otf',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
		);

		foreach ( array_merge( $image_items, $font_items ) as $i => $item ) {
			$ext       = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
			$type      = $media_types[ $ext ] ?? 'application/octet-stream';
			$manifest .= '<item id="res' . $i . '" href="' . self::esc( $item ) . '" media-type="' . $type . '"/>';
		}

		$subtitle_meta = null !== $book->subtitle && '' !== $book->subtitle
			? '<meta property="title-type" refines="#subtitle">subtitle</meta>'
			: '';
		$subtitle_dc   = null !== $book->subtitle && '' !== $book->subtitle
			? '<dc:title id="subtitle">' . self::esc( $book->subtitle ) . '</dc:title>'
			: '';

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="' . self::esc( $book->language ) . '">'
			. '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<dc:identifier id="bookid">' . self::esc( $identifier ) . '</dc:identifier>'
			. '<dc:title id="title">' . self::esc( $book->title ) . '</dc:title>'
			. $subtitle_dc . $subtitle_meta
			. '<dc:creator>' . self::esc( $book->author ) . '</dc:creator>'
			. '<dc:language>' . self::esc( $book->language ) . '</dc:language>'
			. ( '' !== $book->publisher ? '<dc:publisher>' . self::esc( $book->publisher ) . '</dc:publisher>' : '' )
			. '<meta property="dcterms:modified">' . $modified . '</meta>'
			. '</metadata>'
			. '<manifest>' . $manifest . '</manifest>'
			. '<spine>' . $spine . '</spine>'
			. '</package>';
	}

	private function xhtml_document( string $language, string $title, string $body ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE html>' . "\n"
			. '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="' . self::esc( $language ) . '" lang="' . self::esc( $language ) . '">'
			. '<head><meta charset="utf-8"/><title>' . $title . '</title>'
			. '<link rel="stylesheet" type="text/css" href="styles/book.css"/></head>'
			. '<body>' . $body . '</body></html>';
	}

	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * UUID v5-like deterministico dal contenuto (per libri senza identifier:
	 * lo stesso libro riesportato mantiene lo stesso id).
	 */
	private static function uuid_from( string $seed ): string {
		$hash = sha1( 'ghostwriter|' . $seed );
		return sprintf(
			'%s-%s-5%s-%s%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 13, 3 ),
			dechex( ( hexdec( $hash[16] ) & 0x3 ) | 0x8 ),
			substr( $hash, 17, 3 ),
			substr( $hash, 20, 12 )
		);
	}
}

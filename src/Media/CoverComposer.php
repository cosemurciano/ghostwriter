<?php
declare(strict_types=1);

namespace Ghostwriter\Media;

/**
 * Composizione della copertina: tipografia SOPRA l'artwork (che è sempre
 * senza testo). Conseguenza: la copertina di una traduzione si ricompone da
 * sola col titolo tradotto.
 *
 * v1: composizione GD con i font DejaVu (titolo, sottotitolo, autore
 * centrati con banda di leggibilità). La composizione pilotata dai
 * frammenti cover_composition del tema è la rifinitura successiva; il
 * contratto (artwork senza testo + composizione locale) resta identico.
 */
final class CoverComposer {

	public function __construct( private ?string $font_dir = null ) {
		$this->font_dir = $font_dir ?? ( defined( 'GHOSTWRITER_PLUGIN_DIR' )
			? GHOSTWRITER_PLUGIN_DIR . 'vendor/mpdf/mpdf/ttfonts'
			: dirname( __DIR__, 2 ) . '/vendor/mpdf/mpdf/ttfonts' );
	}

	/**
	 * Compone la copertina e restituisce il PNG.
	 *
	 * @param string|null $artwork_path Path locale dell'artwork (null: fondo pieno).
	 * @param string      $background   Colore di fondo esadecimale se manca l'artwork.
	 *
	 * @throws \RuntimeException Se GD non è disponibile o la composizione fallisce.
	 */
	public function compose(
		?string $artwork_path,
		string $title,
		?string $subtitle,
		string $author,
		int $width_px,
		int $height_px,
		string $background = '#31404f'
	): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new \RuntimeException( 'Estensione GD assente: impossibile comporre la copertina.' );
		}

		$canvas = imagecreatetruecolor( $width_px, $height_px );

		if ( null !== $artwork_path && file_exists( $artwork_path ) ) {
			$this->paint_artwork( $canvas, $artwork_path, $width_px, $height_px );
		} else {
			[$r, $g, $b] = self::hex_rgb( $background );
			imagefilledrectangle( $canvas, 0, 0, $width_px, $height_px, imagecolorallocate( $canvas, $r, $g, $b ) );
		}

		// Banda scura traslucida in alto e in basso per la leggibilità.
		$band = imagecolorallocatealpha( $canvas, 0, 0, 0, 72 );
		imagefilledrectangle( $canvas, 0, 0, $width_px, (int) ( $height_px * 0.34 ), $band );
		imagefilledrectangle( $canvas, 0, (int) ( $height_px * 0.86 ), $width_px, $height_px, $band );

		$white = imagecolorallocate( $canvas, 255, 255, 255 );

		$serif = $this->font( array( 'DejaVuSerif-Bold.ttf', 'DejaVuSerif.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans.ttf' ) );
		$sans  = $this->font( array( 'DejaVuSans.ttf', 'DejaVuSerif.ttf' ) );

		// Titolo: dimensione proporzionale, a capo automatico.
		$title_size = max( 18, (int) ( $width_px * 0.055 ) );
		$y          = (int) ( $height_px * 0.12 );
		$y          = $this->draw_centered_wrapped( $canvas, $title, $serif, $title_size, $white, $width_px, $y );

		if ( null !== $subtitle && '' !== $subtitle ) {
			$subtitle_size = max( 12, (int) ( $title_size * 0.45 ) );
			$this->draw_centered_wrapped( $canvas, $subtitle, $sans, $subtitle_size, $white, $width_px, $y + (int) ( $subtitle_size * 1.2 ) );
		}

		$author_size = max( 14, (int) ( $title_size * 0.5 ) );
		$this->draw_centered_wrapped( $canvas, $author, $sans, $author_size, $white, $width_px, (int) ( $height_px * 0.90 ) );

		ob_start();
		imagepng( $canvas );
		$binary = (string) ob_get_clean();
		imagedestroy( $canvas );

		if ( '' === $binary ) {
			throw new \RuntimeException( 'Composizione copertina fallita (PNG vuoto).' );
		}
		return $binary;
	}

	/**
	 * Artwork in cover-crop: riempie il canvas conservando le proporzioni.
	 *
	 * @param resource|\GdImage $canvas Canvas GD.
	 */
	private function paint_artwork( $canvas, string $path, int $width, int $height ): void {
		$data = (string) file_get_contents( $path );
		$src  = imagecreatefromstring( $data );
		if ( false === $src ) {
			throw new \RuntimeException( 'Artwork non decodificabile.' );
		}

		$sw = imagesx( $src );
		$sh = imagesy( $src );

		$scale = max( $width / $sw, $height / $sh );
		$cw    = (int) round( $width / $scale );
		$ch    = (int) round( $height / $scale );
		$sx    = (int) max( 0, ( $sw - $cw ) / 2 );
		$sy    = (int) max( 0, ( $sh - $ch ) / 2 );

		imagecopyresampled( $canvas, $src, 0, 0, $sx, $sy, $width, $height, $cw, $ch );
		imagedestroy( $src );
	}

	/**
	 * Testo centrato con a capo automatico. Restituisce la y dopo l'ultima riga.
	 *
	 * @param resource|\GdImage $canvas Canvas GD.
	 */
	private function draw_centered_wrapped( $canvas, string $text, string $font, int $size, int $color, int $width, int $y ): int {
		$max_width = (int) ( $width * 0.84 );
		$lines     = array();
		$current   = '';

		foreach ( preg_split( '/\s+/', trim( $text ) ) ?: array() as $word ) {
			$candidate = '' === $current ? $word : $current . ' ' . $word;
			$box       = imagettfbbox( $size, 0, $font, $candidate );
			if ( ( $box[2] - $box[0] ) > $max_width && '' !== $current ) {
				$lines[] = $current;
				$current = $word;
			} else {
				$current = $candidate;
			}
		}
		if ( '' !== $current ) {
			$lines[] = $current;
		}

		foreach ( $lines as $line ) {
			$box = imagettfbbox( $size, 0, $font, $line );
			$x   = (int) ( ( $width - ( $box[2] - $box[0] ) ) / 2 );
			$y  += (int) ( $size * 1.35 );
			imagettftext( $canvas, $size, 0, $x, $y, $color, $font, $line );
		}

		return $y;
	}

	private function font( array $candidates ): string {
		foreach ( $candidates as $file ) {
			$path = rtrim( (string) $this->font_dir, '/' ) . '/' . $file;
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		throw new \RuntimeException( 'Nessun font DejaVu disponibile per la composizione copertina.' );
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function hex_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			$hex = '31404f';
		}
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}

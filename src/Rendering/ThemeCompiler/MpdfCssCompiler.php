<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering\ThemeCompiler;

use Ghostwriter\Rendering\Theme;

/**
 * Compila theme.json nel CSS per mPDF.
 *
 * Nota sul mirroring dei margini: formato pagina e margini interno/esterno
 * NON vengono emessi qui ma configurati dal PdfExporter nel costruttore mPDF
 * (margin_left = interno, margin_right = esterno, mirrorMargins = true),
 * la strada già collaudata in BookCreator: la gestione @page :odd/:even di
 * mPDF è meno affidabile del mirroring nativo. Il compilatore emette tutto
 * il resto: tipografia, scala, stili per blocco, sillabazione,
 * widows/orphans, apertura capitolo, note, TOC.
 */
final class MpdfCssCompiler {

	public function compile( Theme $theme ): string {
		$resolver = new StyleResolver( $theme, false );

		$css  = "/* Ghostwriter — tema {$theme->name()} v{$theme->version()} — target: pdf */\n";
		$css .= $this->base_typography( $theme, $resolver );
		$css .= $this->pdf_directives( $theme );
		$css .= $this->chapter_opening( $theme );
		$css .= $this->structural_defaults( $theme, $resolver );
		$css .= $this->block_styles( $theme, $resolver );

		return $css;
	}

	private function base_typography( Theme $theme, StyleResolver $resolver ): string {
		$body_props = array(
			'font-size'   => $theme->body_size_pt() . 'pt',
			'line-height' => (string) $theme->leading(),
		);

		$body_family = $resolver->font_family( 'body' );
		if ( null !== $body_family ) {
			$body_props['font-family'] = $body_family;
		}

		$text_color = $theme->palette_color( 'text' );
		if ( null !== $text_color ) {
			$body_props['color'] = $text_color;
		}

		$css = StyleResolver::rule( 'body', $body_props );

		// Scala modulare: h4 = corpo × ratio, h3 = h4 × ratio, h2 = h3 × ratio.
		$heading_family = $resolver->font_family( 'heading' );
		$ratio          = $theme->scale_ratio();
		$size           = $theme->body_size_pt();
		foreach ( array( 4, 3, 2 ) as $level ) {
			$size *= $ratio;
			$props = array(
				'font-size'         => round( $size, 2 ) . 'pt',
				'line-height'       => '1.2',
				'page-break-after'  => 'avoid',
			);
			if ( null !== $heading_family ) {
				$props['font-family'] = $heading_family;
			}
			$css .= StyleResolver::rule( "h{$level}", $props );
		}

		// Titolo del capitolo (h1 della pagina di apertura).
		$title_props = array(
			'font-size'        => round( $theme->body_size_pt() * ( $ratio ** 4 ), 2 ) . 'pt',
			'line-height'      => '1.15',
			'page-break-after' => 'avoid',
		);
		if ( null !== $heading_family ) {
			$title_props['font-family'] = $heading_family;
		}
		$css .= StyleResolver::rule( '.gw-chapter-title', $title_props );

		return $css;
	}

	private function pdf_directives( Theme $theme ): string {
		$pdf = $theme->pdf();

		$props = array(
			'widows'  => (string) ( $pdf['widows'] ?? 2 ),
			'orphans' => (string) ( $pdf['orphans'] ?? 2 ),
		);

		if ( ! empty( $pdf['hyphenation_lang'] ) ) {
			$props['hyphens'] = 'auto';
		}

		return StyleResolver::rule( 'p, li, dd', $props );
	}

	private function chapter_opening( Theme $theme ): string {
		$start = (array) ( $theme->page()['chapter_start'] ?? array() );

		$props = array();
		if ( isset( $start['drop_mm'] ) ) {
			$props['margin-top'] = ( (float) $start['drop_mm'] ) . 'mm';
		}

		return StyleResolver::rule( '.gw-chapter-opening', $props );
	}

	/**
	 * Default strutturali indipendenti dal tema: didascalie, epigrafi, note,
	 * placeholder figura, tabelle. Il tema può sovrascriverli con blocks{}.
	 */
	private function structural_defaults( Theme $theme, StyleResolver $resolver ): string {
		$caption_family = $resolver->font_family( 'caption' );
		$mono_family    = $resolver->font_family( 'mono' );

		$caption_props = array(
			'font-size'  => $resolver->size_from_delta( -1.5 ),
			'font-style' => 'italic',
		);
		if ( null !== $caption_family ) {
			$caption_props['font-family'] = $caption_family;
		}

		$css  = StyleResolver::rule( '.gw-caption', $caption_props );
		$css .= StyleResolver::rule(
			'.gw-epigraph',
			array(
				'margin'     => '2em 3em',
				'font-style' => 'italic',
				'text-align' => 'right',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-notes',
			array(
				'margin-top'  => '2em',
				'font-size'   => $resolver->size_from_delta( -1.5 ),
				'border-top'  => '0.5pt solid #999999',
				'padding-top' => '0.5em',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-figura-placeholder',
			array(
				'border'     => '1pt dashed #999999',
				'padding'    => '1em',
				'font-style' => 'italic',
				'color'      => '#666666',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-tabella',
			array(
				'border-collapse' => 'collapse',
				'width'           => '100%',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-tabella th, .gw-tabella td',
			array(
				'border'  => '0.5pt solid #999999',
				'padding' => '0.35em 0.5em',
			)
		);

		if ( null !== $mono_family ) {
			$css .= StyleResolver::rule(
				'.gw-codice',
				array(
					'font-family' => $mono_family,
					'font-size'   => $resolver->size_from_delta( -1.5 ),
				)
			);
		}

		$css .= StyleResolver::rule(
			'.gw-figura img',
			array( 'max-width' => '100%' )
		);

		// Allineamento figure nella pagina: centrate, mai spezzate, larghezza
		// dalla size del blocco (small ½, medium ¾, full = larghezza pagina).
		$css .= StyleResolver::rule(
			'.gw-figura',
			array(
				'text-align'        => 'center',
				'margin'            => '1em auto',
				'page-break-inside' => 'avoid',
			)
		);
		$css .= StyleResolver::rule( '.gw-figura.gw-size-small img', array( 'max-width' => '50%' ) );
		$css .= StyleResolver::rule( '.gw-figura.gw-size-medium img', array( 'max-width' => '75%' ) );
		$css .= StyleResolver::rule( '.gw-figura.gw-size-full img', array( 'max-width' => '100%' ) );
		$css .= StyleResolver::rule(
			'.gw-figura .gw-caption',
			array(
				'text-align' => 'center',
				'margin-top' => '0.4em',
			)
		);

		return $css;
	}

	private function block_styles( Theme $theme, StyleResolver $resolver ): string {
		$css = '';

		foreach ( $theme->block_styles() as $type => $style ) {
			$resolved = $resolver->resolve_style( (array) $style );

			$css .= StyleResolver::rule( '.gw-' . $type, $resolved['props'] );
			foreach ( $resolved['nested'] as $child => $child_props ) {
				$css .= StyleResolver::rule( '.gw-' . $type . ' .gw-' . $child, $child_props );
			}
		}

		return $css;
	}
}

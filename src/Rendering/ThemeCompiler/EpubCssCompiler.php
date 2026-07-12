<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering\ThemeCompiler;

use Ghostwriter\Rendering\Theme;

/**
 * Compila theme.json nel CSS per l'ePub.
 *
 * Regola d'oro: si emette SOLO il sottoinsieme CSS sicuro (riferimento:
 * Kindle Previewer, Apple Books, Thorium/ADE); tutto il resto passa dai
 * fallback dichiarati (epub_fallback per blocco, epub.overrides).
 * Con respect_reader_settings non si impone font-size assoluto sul body:
 * l'utente comanda.
 */
final class EpubCssCompiler {

	/** Proprietà CSS ammesse nell'output ePub. */
	private const SAFE_PROPS = array(
		'font-family',
		'font-size',
		'font-style',
		'font-weight',
		'line-height',
		'color',
		'background-color',
		'text-align',
		'text-indent',
		'text-transform',
		'letter-spacing',
		'margin',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'border',
		'border-top',
		'border-right',
		'border-bottom',
		'border-left',
		'display',
		'page-break-before',
		'page-break-after',
		'page-break-inside',
		'max-width',
	);

	public function compile( Theme $theme ): string {
		$resolver = new StyleResolver( $theme, true );
		$epub     = $theme->epub();

		$css  = "/* Ghostwriter — tema {$theme->name()} v{$theme->version()} — target: epub */\n";
		$css .= $this->font_faces( $theme, $epub );
		$css .= $this->base_typography( $theme, $resolver, $epub );
		$css .= $this->structural_defaults( $resolver );
		$css .= $this->block_styles( $theme, $resolver, $epub );

		return $css;
	}

	/**
	 * @font-face solo se embed_fonts è attivo e il font dichiara file con
	 * licenza embeddabile. I file vengono copiati in OEBPS/fonts/ dall'exporter.
	 *
	 * @param array<string, mixed> $epub Direttive epub del tema.
	 */
	private function font_faces( Theme $theme, array $epub ): string {
		if ( empty( $epub['embed_fonts'] ) ) {
			return '';
		}

		$css    = '';
		$styles = array(
			'regular'     => array( 'normal', 'normal' ),
			'italic'      => array( 'italic', 'normal' ),
			'bold'        => array( 'normal', 'bold' ),
			'bold_italic' => array( 'italic', 'bold' ),
		);

		foreach ( $theme->fonts() as $font ) {
			if ( empty( $font['embeddable'] ) || empty( $font['files'] ) || empty( $font['family'] ) ) {
				continue;
			}
			foreach ( (array) $font['files'] as $variant => $file ) {
				if ( ! isset( $styles[ $variant ] ) || ! is_string( $file ) || '' === $file ) {
					continue;
				}
				[$font_style, $font_weight] = $styles[ $variant ];

				$css .= "@font-face {\n"
					. "\tfont-family: '{$font['family']}';\n"
					. "\tfont-style: {$font_style};\n"
					. "\tfont-weight: {$font_weight};\n"
					. "\tsrc: url('fonts/" . basename( (string) $file ) . "');\n"
					. "}\n";
			}
		}

		return $css;
	}

	/**
	 * @param array<string, mixed> $epub Direttive epub del tema.
	 */
	private function base_typography( Theme $theme, StyleResolver $resolver, array $epub ): string {
		$respect_reader = $epub['respect_reader_settings'] ?? true;

		$body_props = array(
			'line-height' => (string) $theme->leading(),
		);

		if ( ! $respect_reader ) {
			// Solo se il tema comanda sull'utente (sconsigliato).
			$body_props['font-size'] = $theme->body_size_pt() . 'pt';
		}

		$body_family = $resolver->font_family( 'body' );
		if ( null !== $body_family ) {
			$body_props['font-family'] = $body_family;
		}

		$text_color = $theme->palette_color( 'text', true );
		if ( null !== $text_color ) {
			$body_props['color'] = $text_color;
		}

		$css = StyleResolver::rule( 'body', $body_props );

		$heading_family = $resolver->font_family( 'heading' );
		$ratio          = $theme->scale_ratio();
		$em             = 1.0;
		foreach ( array( 4, 3, 2 ) as $level ) {
			$em   *= $ratio;
			$props = array(
				'font-size'   => round( $em, 3 ) . 'em',
				'line-height' => '1.2',
			);
			if ( null !== $heading_family ) {
				$props['font-family'] = $heading_family;
			}
			$css .= StyleResolver::rule( "h{$level}", $props );
		}

		$title_props = array(
			'font-size'   => round( $ratio ** 4, 3 ) . 'em',
			'line-height' => '1.15',
			'margin'      => '2em 0 1em 0',
		);
		if ( null !== $heading_family ) {
			$title_props['font-family'] = $heading_family;
		}
		$css .= StyleResolver::rule( '.gw-chapter-title', $title_props );

		return $css;
	}

	private function structural_defaults( StyleResolver $resolver ): string {
		$css  = StyleResolver::rule(
			'.gw-caption',
			array(
				'font-size'  => '0.85em',
				'font-style' => 'italic',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-epigraph',
			array(
				'margin'     => '2em 10%',
				'font-style' => 'italic',
				'text-align' => 'right',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-notes',
			array(
				'margin-top' => '2em',
				'font-size'  => '0.85em',
				'border-top' => '1px solid #999999',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-figura img',
			array( 'max-width' => '100%' )
		);
		// Allineamento figure: centrate nel flusso reflowable, larghezza dalla
		// size del blocco (i reader la rispettano come percentuale).
		$css .= StyleResolver::rule(
			'.gw-figura',
			array(
				'text-align' => 'center',
				'margin'     => '1em auto',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-figura img',
			array(
				'display' => 'inline-block',
				'height'  => 'auto',
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
		// Resa impilata delle tabelle (fallback stacked).
		$css .= StyleResolver::rule(
			'.gw-stacked .gw-stacked-row',
			array(
				'margin'        => '0 0 1em 0',
				'padding'       => '0 0 0.5em 0',
				'border-bottom' => '1px solid #cccccc',
			)
		);
		$css .= StyleResolver::rule(
			'.gw-stacked dt',
			array( 'font-weight' => 'bold' )
		);
		$css .= StyleResolver::rule(
			'.gw-stacked dd',
			array( 'margin' => '0 0 0.3em 0' )
		);
		$css .= StyleResolver::rule(
			'.gw-tabella th, .gw-tabella td',
			array(
				'border'  => '1px solid #999999',
				'padding' => '0.3em 0.5em',
			)
		);

		return $css;
	}

	/**
	 * Stili per blocco: base del tema + epub_fallback (sostituisce le proprietà
	 * non sicure) + epub.overrides, filtrati sul sottoinsieme sicuro.
	 *
	 * @param array<string, mixed> $epub Direttive epub del tema.
	 */
	private function block_styles( Theme $theme, StyleResolver $resolver, array $epub ): string {
		$overrides = (array) ( $epub['overrides'] ?? array() );
		$css       = '';

		foreach ( $theme->block_styles() as $type => $style ) {
			$style = (array) $style;

			// Il degrado esplicito per l'ePub vince sulle proprietà di base.
			if ( isset( $style['epub_fallback'] ) && is_array( $style['epub_fallback'] ) ) {
				$style = array_merge( $style, $style['epub_fallback'] );
			}
			// Override puntuali del tema per l'ePub.
			if ( isset( $overrides[ $type ] ) && is_array( $overrides[ $type ] ) ) {
				$style = array_merge( $style, $overrides[ $type ] );
			}
			unset( $style['epub_fallback'] );

			$resolved = $resolver->resolve_style( $style );

			$css .= StyleResolver::rule( '.gw-' . $type, self::safe_filter( $resolved['props'] ) );
			foreach ( $resolved['nested'] as $child => $child_props ) {
				$css .= StyleResolver::rule( '.gw-' . $type . ' .gw-' . $child, self::safe_filter( $child_props ) );
			}
		}

		return $css;
	}

	/**
	 * @param array<string, string> $props Proprietà risolte.
	 * @return array<string, string> Solo quelle del sottoinsieme sicuro; "none" rimuove la proprietà.
	 */
	private static function safe_filter( array $props ): array {
		$safe = array();
		foreach ( $props as $key => $value ) {
			if ( ! in_array( $key, self::SAFE_PROPS, true ) ) {
				continue;
			}
			if ( 'none' === $value && in_array( $key, array( 'background-color', 'border' ), true ) ) {
				continue; // Il fallback "background: none" significa: non emettere.
			}
			$safe[ $key ] = $value;
		}
		return $safe;
	}
}

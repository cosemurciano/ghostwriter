<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering\ThemeCompiler;

use Ghostwriter\Rendering\Theme;

/**
 * Risoluzione condivisa dai compilatori: design tokens referenziati per nome
 * (palette, font per ruolo) e chiavi shorthand del tema tradotte in CSS.
 */
final class StyleResolver {

	/** Traduzione delle chiavi shorthand del tema in proprietà CSS. */
	private const KEY_MAP = array(
		'indent'     => 'text-indent',
		'align'      => 'text-align',
		'background' => 'background-color',
		'style'      => 'font-style',
		'weight'     => 'font-weight',
	);

	public function __construct(
		private Theme $theme,
		private bool $for_epub
	) {
	}

	/**
	 * Converte la mappa di stile di un blocco in dichiarazioni CSS.
	 * Le chiavi con valore-oggetto sono regole annidate e vengono restituite
	 * separatamente (selettore figlio .gw-<chiave>).
	 *
	 * @param array<string, mixed> $style Stile del blocco da theme.json.
	 * @return array{props: array<string, string>, nested: array<string, array<string, string>>}
	 */
	public function resolve_style( array $style ): array {
		$props  = array();
		$nested = array();

		foreach ( $style as $key => $value ) {
			if ( 'epub_fallback' === $key ) {
				continue; // Gestito a monte dal compilatore ePub.
			}

			if ( is_array( $value ) ) {
				$nested[ $key ] = $this->resolve_style( $value )['props'];
				continue;
			}

			$css_key = self::KEY_MAP[ $key ] ?? $key;

			if ( 'font' === $key ) {
				$family = $this->font_family( (string) $value );
				if ( null !== $family ) {
					$props['font-family'] = $family;
				}
				continue;
			}

			if ( 'size_delta' === $key ) {
				$props['font-size'] = $this->size_from_delta( (float) $value );
				continue;
			}

			$props[ $css_key ] = $this->resolve_value( (string) $value );
		}

		return array(
			'props'  => $props,
			'nested' => $nested,
		);
	}

	/**
	 * Sostituisce i nomi dei colori della palette con il valore esadecimale
	 * (variante epub_value se si compila per ePub). Es: "2pt solid accent".
	 */
	public function resolve_value( string $value ): string {
		foreach ( $this->theme->palette_names() as $name ) {
			$color = $this->theme->palette_color( $name, $this->for_epub );
			if ( null === $color ) {
				continue;
			}
			$value = (string) preg_replace( '/\b' . preg_quote( $name, '/' ) . '\b/', $color, $value );
		}
		return $value;
	}

	/**
	 * font-family per un ruolo del tema (body|heading|mono|caption).
	 * Per mPDF la famiglia è normalizzata alla chiave fontdata (minuscolo,
	 * solo alfanumerico); per l'ePub si usa il nome reale con fallback generico.
	 */
	public function font_family( string $role ): ?string {
		$font = $this->theme->font( $role );
		if ( null === $font || empty( $font['family'] ) ) {
			return null;
		}

		$family = (string) $font['family'];

		if ( $this->for_epub ) {
			$generic = 'mono' === $role ? 'monospace' : 'serif';
			return "'" . $family . "', " . $generic;
		}

		return self::mpdf_family_key( $family );
	}

	/**
	 * Dimensione derivata dal corpo del testo: assoluta in pt per il PDF,
	 * relativa in em per l'ePub (l'utente comanda sul corpo).
	 */
	public function size_from_delta( float $delta ): string {
		$body = $this->theme->body_size_pt();
		$size = max( 1.0, $body + $delta );

		if ( $this->for_epub ) {
			return round( $size / $body, 3 ) . 'em';
		}
		return rtrim( rtrim( number_format( $size, 2, '.', '' ), '0' ), '.' ) . 'pt';
	}

	/**
	 * Emissione di una regola CSS.
	 *
	 * @param array<string, string> $props Proprietà già risolte.
	 */
	public static function rule( string $selector, array $props ): string {
		if ( empty( $props ) ) {
			return '';
		}
		$lines = '';
		foreach ( $props as $key => $value ) {
			$lines .= "\t{$key}: {$value};\n";
		}
		return "{$selector} {\n{$lines}}\n";
	}

	/**
	 * Chiave famiglia per mPDF: minuscolo, solo alfanumerico
	 * (es. "EB Garamond" → ebgaramond). Coerente con la fontdata
	 * costruita dal PdfExporter.
	 */
	public static function mpdf_family_key( string $family ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $family ) );
	}
}

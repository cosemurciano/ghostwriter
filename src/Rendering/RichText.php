<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

/**
 * Rendering del "Markdown ristretto" del formato intermedio: solo enfasi
 * (*corsivo*, **grassetto**), link e riferimenti nota [^note_id].
 * Niente heading, elenchi o HTML: la struttura è dei blocchi, non del testo.
 *
 * L'input è testo non fidato (prodotto dall'AI): viene sempre escapato
 * prima di applicare le poche trasformazioni ammesse.
 */
final class RichText {

	/**
	 * @param callable(string): string $note_ref_renderer Riceve il note_id, restituisce l'HTML del riferimento.
	 */
	public static function render( string $text, ?callable $note_ref_renderer = null ): string {
		$html = htmlspecialchars( $text, ENT_QUOTES | ENT_XHTML, 'UTF-8' );

		// Riferimenti nota [^n1] — prima dei link, la sintassi è disgiunta.
		$html = preg_replace_callback(
			'/\[\^([A-Za-z0-9_-]+)\]/',
			static function ( array $m ) use ( $note_ref_renderer ): string {
				if ( null !== $note_ref_renderer ) {
					return $note_ref_renderer( $m[1] );
				}
				return '<sup class="gw-noteref"><a href="#gw-note-' . $m[1] . '">' . $m[1] . '</a></sup>';
			},
			$html
		);

		// Link [testo](url) — solo http/https.
		$html = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
			static fn( array $m ): string => '<a href="' . $m[2] . '">' . $m[1] . '</a>',
			$html
		);

		// Grassetto prima del corsivo: ** è goloso rispetto a *.
		$html = preg_replace( '/\*\*(?!\s)(.+?)(?<!\s)\*\*/s', '<strong>$1</strong>', $html );
		$html = preg_replace( '/\*(?!\s)([^*]+?)(?<!\s)\*/s', '<em>$1</em>', $html );

		return $html;
	}

	/**
	 * Versione solo-testo (per alt, titoli TOC, conteggi): rimuove la sintassi.
	 */
	public static function to_plain( string $text ): string {
		$plain = preg_replace( '/\[\^([A-Za-z0-9_-]+)\]/', '', $text );
		$plain = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '$1', $plain );
		$plain = str_replace( array( '**', '*' ), '', $plain );
		return trim( $plain );
	}
}

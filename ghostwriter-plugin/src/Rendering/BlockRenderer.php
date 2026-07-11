<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

/**
 * Trasforma il formato intermedio in HTML con classi stabili
 * (gw-block gw-paragrafo, gw-block gw-citazione gw-display-pull, ...).
 *
 * Due profili:
 * - pdf: HTML pieno per mPDF (tabelle, tocentry per i segnalibri/TOC);
 * - epub: XHTML valido con fallback applicati (tabelle larghe → impilate se
 *   epub_fallback: stacked, pull quote → blockquote semplice).
 *
 * Il renderer è puro: nessuna chiamata WordPress. Le immagini arrivano già
 * risolte (attachment_id → src) tramite $options['image_src'].
 */
final class BlockRenderer {

	public const PROFILE_PDF  = 'pdf';
	public const PROFILE_EPUB = 'epub';

	/**
	 * Renderizza il corpo di un capitolo (blocchi + note endnote).
	 *
	 * @param array<string, mixed> $content Formato intermedio validato.
	 * @param string               $profile pdf|epub.
	 * @param array<string, mixed> $options Chiavi:
	 *   - image_src: callable(int|null $attachment_id, array $props): ?string — risolve la src dell'immagine;
	 *   - toc_depth: int — solo pdf, profondità delle tocentry per gli heading interni (0 = nessuna);
	 *   - heading_offset: int — offset dei livelli heading (per gerarchie profonde).
	 *
	 * @return string HTML del corpo capitolo (senza il titolo H1, che è del template capitolo).
	 */
	public function render_chapter( array $content, string $profile, array $options = array() ): string {
		self::assert_profile( $profile );

		$note_numbers = self::note_numbers( $content['notes'] ?? array() );

		$html = '';

		$epigraph = $content['meta']['epigraph'] ?? null;
		if ( is_array( $epigraph ) && ! empty( $epigraph['text'] ) ) {
			$html .= '<div class="gw-epigraph"><p class="gw-epigraph-text">' . RichText::render( (string) $epigraph['text'] ) . '</p>';
			if ( ! empty( $epigraph['attribution'] ) ) {
				$html .= '<p class="gw-epigraph-attribution">' . RichText::render( (string) $epigraph['attribution'] ) . '</p>';
			}
			$html .= '</div>' . "\n";
		}

		foreach ( $content['blocks'] ?? array() as $block ) {
			$html .= $this->render_block( $block, $profile, $options, $note_numbers );
		}

		$html .= $this->render_notes( $content['notes'] ?? array(), $profile, $note_numbers );

		return $html;
	}

	/**
	 * Renderizza un singolo blocco.
	 *
	 * @param array<string, mixed> $block        Blocco del formato intermedio.
	 * @param array<string, mixed> $options      Vedi render_chapter().
	 * @param array<string, int>   $note_numbers Mappa note_id → numero progressivo.
	 */
	public function render_block( array $block, string $profile, array $options = array(), array $note_numbers = array() ): string {
		self::assert_profile( $profile );

		$type  = (string) ( $block['type'] ?? '' );
		$props = (array) ( $block['props'] ?? array() );
		$id    = (string) ( $block['id'] ?? '' );

		$note_renderer = static function ( string $note_id ) use ( $note_numbers ): string {
			$n = $note_numbers[ $note_id ] ?? 0;
			$label = $n > 0 ? (string) $n : $note_id;
			return '<sup class="gw-noteref"><a href="#gw-note-' . self::esc_attr( $note_id ) . '">' . $label . '</a></sup>';
		};

		switch ( $type ) {
			case 'paragrafo':
				$role = (string) ( $props['role'] ?? 'normal' );
				return '<p class="' . self::classes( $type, $id, 'gw-role-' . $role ) . '">'
					. RichText::render( (string) ( $props['text'] ?? '' ), $note_renderer )
					. '</p>' . "\n";

			case 'heading':
				$level  = min( 6, max( 2, (int) ( $props['level'] ?? 2 ) + (int) ( $options['heading_offset'] ?? 0 ) ) );
				$text   = (string) ( $props['text'] ?? '' );
				$anchor = '' !== $id ? ' id="gw-h-' . self::esc_attr( $id ) . '"' : '';
				$out    = '';
				if ( self::PROFILE_PDF === $profile && ( $options['toc_depth'] ?? 0 ) >= $level - 1 ) {
					// Le tocentry alimentano TOC e segnalibri mPDF; il titolo capitolo (level 1) le aggiunge l'exporter.
					$out .= '<tocentry content="' . self::esc_attr( RichText::to_plain( $text ) ) . '" level="' . ( $level - 1 ) . '" />';
				}
				return $out . '<h' . $level . $anchor . ' class="' . self::classes( $type, $id ) . '">'
					. self::esc_html( $text ) . '</h' . $level . '>' . "\n";

			case 'citazione':
				$display = (string) ( $props['display'] ?? 'block' );
				// Fallback ePub: la pull quote degrada a blockquote semplice.
				$pull  = 'pull' === $display && self::PROFILE_PDF === $profile;
				$class = self::classes( $type, $id, $pull ? 'gw-display-pull' : 'gw-display-block' );
				$html  = '<blockquote class="' . $class . '"><p>' . RichText::render( (string) ( $props['text'] ?? '' ), $note_renderer ) . '</p>';
				if ( ! empty( $props['attribution'] ) ) {
					$html .= '<footer class="gw-attribution">' . RichText::render( (string) $props['attribution'] ) . '</footer>';
				}
				return $html . '</blockquote>' . "\n";

			case 'box_approfondimento':
				$variant = (string) ( $props['variant'] ?? 'approfondimento' );
				$html    = '<div class="' . self::classes( $type, $id, 'gw-variant-' . $variant ) . '">';
				$html   .= '<p class="gw-box-title">' . self::esc_html( (string) ( $props['title'] ?? '' ) ) . '</p>';
				foreach ( (array) ( $props['blocks'] ?? array() ) as $nested ) {
					$html .= $this->render_block( (array) $nested, $profile, $options, $note_numbers );
				}
				return $html . '</div>' . "\n";

			case 'figura':
				return $this->render_figure( $props, $id, $profile, $options );

			case 'tabella':
				return $this->render_table( $props, $id, $profile, $note_numbers );

			case 'elenco':
				$tag  = ! empty( $props['ordered'] ) ? 'ol' : 'ul';
				$html = '<' . $tag . ' class="' . self::classes( $type, $id ) . '">';
				foreach ( (array) ( $props['items'] ?? array() ) as $item ) {
					$html .= '<li>' . RichText::render( (string) $item, $note_renderer ) . '</li>';
				}
				return $html . '</' . $tag . '>' . "\n";

			case 'esercizio':
				$html = '<div class="' . self::classes( $type, $id ) . '">';
				if ( ! empty( $props['number'] ) ) {
					$html .= '<span class="gw-esercizio-numero">' . self::esc_html( (string) $props['number'] ) . '</span> ';
				}
				$html .= RichText::render( (string) ( $props['text'] ?? '' ), $note_renderer );
				// Le soluzioni non si stampano inline: il tema decide se raccoglierle in appendice (fase export).
				return $html . '</div>' . "\n";

			case 'codice':
				$lang  = ! empty( $props['language'] ) ? ' gw-lang-' . self::esc_attr( (string) $props['language'] ) : '';
				$html  = '<pre class="' . self::classes( $type, $id ) . $lang . '"><code>'
					. self::esc_html( (string) ( $props['code'] ?? '' ) )
					. '</code></pre>';
				if ( ! empty( $props['caption'] ) ) {
					$html .= '<p class="gw-caption gw-codice-caption">' . self::esc_html( (string) $props['caption'] ) . '</p>';
				}
				return $html . "\n";

			case 'separatore':
				return '<hr class="' . self::classes( $type, $id ) . '" />' . "\n";

			case 'blurb':
				// Il blurb non appartiene al corpo del libro: lo consuma la
				// composizione copertina (quarta) e il marketing.
				return '';

			default:
				// Tipo sconosciuto: la validazione a monte lo impedisce; in difesa non si emette nulla.
				return '';
		}
	}

	/**
	 * @param array<string, mixed> $props   Props della figura.
	 * @param array<string, mixed> $options Vedi render_chapter().
	 */
	private function render_figure( array $props, string $id, string $profile, array $options ): string {
		$attachment_id = isset( $props['attachment_id'] ) ? (int) $props['attachment_id'] : null;
		$resolver      = $options['image_src'] ?? null;
		$src           = is_callable( $resolver ) ? $resolver( $attachment_id ?: null, $props ) : null;

		$size  = (string) ( $props['size'] ?? 'medium' );
		$class = self::classes( 'figura', $id, 'gw-size-' . $size );

		$html = '<figure class="' . $class . '">';

		if ( null !== $src && '' !== $src ) {
			$alt   = self::esc_attr( (string) ( $props['alt'] ?? RichText::to_plain( (string) ( $props['caption'] ?? '' ) ) ) );
			$html .= '<img src="' . self::esc_attr( $src ) . '" alt="' . $alt . '" />';
		} else {
			// Placeholder: figura non ancora generata. Il preflight blocca
			// l'export finché tutti gli attachment_id non sono risolti.
			$html .= '<div class="gw-figura-placeholder">' . self::esc_html( (string) ( $props['image_brief'] ?? '' ) ) . '</div>';
		}

		$caption = (string) ( $props['caption'] ?? '' );
		if ( '' !== $caption || ! empty( $props['credit'] ) ) {
			$html .= '<figcaption class="gw-caption">' . RichText::render( $caption );
			if ( ! empty( $props['credit'] ) ) {
				$html .= ' <span class="gw-credit">' . self::esc_html( (string) $props['credit'] ) . '</span>';
			}
			$html .= '</figcaption>';
		}

		return $html . '</figure>' . "\n";
	}

	/**
	 * @param array<string, mixed> $props        Props della tabella.
	 * @param array<string, int>   $note_numbers Mappa note.
	 */
	private function render_table( array $props, string $id, string $profile, array $note_numbers ): string {
		$header = array_map( 'strval', (array) ( $props['header'] ?? array() ) );
		$rows   = (array) ( $props['rows'] ?? array() );

		// Fallback ePub: le tabelle degradano a elenco impilato ("header: valore")
		// se epub_fallback è stacked (default) — i reader piccoli non reggono le tabelle larghe.
		if ( self::PROFILE_EPUB === $profile && 'scroll' !== ( $props['epub_fallback'] ?? 'stacked' ) ) {
			$html = '<div class="' . self::classes( 'tabella', $id, 'gw-stacked' ) . '">';
			if ( ! empty( $props['caption'] ) ) {
				$html .= '<p class="gw-caption">' . RichText::render( (string) $props['caption'] ) . '</p>';
			}
			foreach ( $rows as $row ) {
				$html .= '<dl class="gw-stacked-row">';
				foreach ( array_values( (array) $row ) as $i => $cell ) {
					if ( isset( $header[ $i ] ) && '' !== $header[ $i ] ) {
						$html .= '<dt>' . self::esc_html( $header[ $i ] ) . '</dt>';
					}
					$html .= '<dd>' . RichText::render( (string) $cell ) . '</dd>';
				}
				$html .= '</dl>';
			}
			return $html . '</div>' . "\n";
		}

		$html = '<table class="' . self::classes( 'tabella', $id ) . '">';
		if ( ! empty( $props['caption'] ) ) {
			$html .= '<caption class="gw-caption">' . RichText::render( (string) $props['caption'] ) . '</caption>';
		}
		if ( ! empty( $header ) ) {
			$html .= '<thead><tr>';
			foreach ( $header as $cell ) {
				$html .= '<th>' . self::esc_html( $cell ) . '</th>';
			}
			$html .= '</tr></thead>';
		}
		$html .= '<tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( (array) $row as $cell ) {
				$html .= '<td>' . RichText::render( (string) $cell ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>' . "\n";
	}

	/**
	 * Note del capitolo come endnote numerate (le footnote mPDF native non
	 * esistono: la resa a piè di pagina, se richiesta dal tema, è un
	 * raffinamento successivo del compilatore).
	 *
	 * @param array<int, array<string, mixed>> $notes        Note del capitolo.
	 * @param array<string, int>               $note_numbers Mappa note_id → numero.
	 */
	private function render_notes( array $notes, string $profile, array $note_numbers ): string {
		if ( empty( $notes ) ) {
			return '';
		}

		$epub_type = self::PROFILE_EPUB === $profile ? ' epub:type="endnotes"' : '';

		$html = '<section class="gw-notes"' . $epub_type . '><ol class="gw-notes-list">';
		foreach ( $notes as $note ) {
			$note_id = (string) ( $note['note_id'] ?? '' );
			$html   .= '<li id="gw-note-' . self::esc_attr( $note_id ) . '" value="' . ( $note_numbers[ $note_id ] ?? 1 ) . '">'
				. RichText::render( (string) ( $note['text'] ?? '' ) )
				. '</li>';
		}
		return $html . '</ol></section>' . "\n";
	}

	/**
	 * @param array<int, array<string, mixed>> $notes Note del capitolo.
	 * @return array<string, int> note_id → numero progressivo (ordine di dichiarazione).
	 */
	private static function note_numbers( array $notes ): array {
		$numbers = array();
		$n       = 0;
		foreach ( $notes as $note ) {
			if ( isset( $note['note_id'] ) ) {
				$numbers[ (string) $note['note_id'] ] = ++$n;
			}
		}
		return $numbers;
	}

	private static function classes( string $type, string $id, string ...$extra ): string {
		$classes = array_merge( array( 'gw-block', 'gw-' . $type ), $extra );
		return implode( ' ', $classes );
	}

	private static function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XHTML, 'UTF-8' );
	}

	private static function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XHTML, 'UTF-8' );
	}

	private static function assert_profile( string $profile ): void {
		if ( ! in_array( $profile, array( self::PROFILE_PDF, self::PROFILE_EPUB ), true ) ) {
			throw new \InvalidArgumentException( "Profilo di rendering sconosciuto: {$profile}" );
		}
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

/**
 * Proiezione del formato intermedio nell'editor classico di WordPress e
 * riconversione al salvataggio.
 *
 * Andata (blocchi → HTML): ogni blocco di primo livello diventa un elemento
 * con data-gw-id/data-gw-type, così id (chiave per traduzioni e revisioni)
 * e tipo sopravvivono al giro in TinyMCE dove possibile.
 *
 * Ritorno (HTML → blocchi): gli elementi si mappano sui tipi del contratto
 * (p→paragrafo, h2-h4→heading, ul/ol→elenco, blockquote→citazione,
 * figure/img→figura, table→tabella, pre→codice, hr→separatore). I blocchi
 * complessi (box_approfondimento, esercizio, blurb) viaggiano come div
 * "bloccati" e vengono ripristinati VERBATIM dal contenuto precedente:
 * si modificano dalla scheda Capitoli (riscrittura AI), non dall'editor.
 *
 * Il testo inline usa il markdown ristretto del contratto: *, **, link e
 * riferimenti nota [^id] restano tali e quali nel testo dell'editor.
 */
final class EditorProjection {

	private const LOCKED_TYPES = array( 'box_approfondimento', 'esercizio', 'blurb' );

	// ------------------------------------------------------------------ //
	// Blocchi → HTML per l'editor
	// ------------------------------------------------------------------ //

	/**
	 * @param array<string, mixed> $content Formato intermedio.
	 */
	public static function to_html( array $content ): string {
		$html = '';
		foreach ( (array) ( $content['blocks'] ?? array() ) as $block ) {
			$html .= self::block_to_html( (array) $block ) . "\n\n";
		}
		return trim( $html );
	}

	/**
	 * @param array<string, mixed> $block Blocco del formato intermedio.
	 */
	private static function block_to_html( array $block ): string {
		$type  = (string) ( $block['type'] ?? 'paragrafo' );
		$props = (array) ( $block['props'] ?? array() );
		$attrs = ' data-gw-id="' . esc_attr( (string) ( $block['id'] ?? '' ) ) . '" data-gw-type="' . esc_attr( $type ) . '"';

		switch ( $type ) {
			case 'paragrafo':
				return '<p' . $attrs . '>' . self::inline_html( (string) ( $props['text'] ?? '' ) ) . '</p>';

			case 'heading':
				$level = min( 4, max( 2, (int) ( $props['level'] ?? 2 ) ) );
				return "<h{$level}{$attrs}>" . esc_html( (string) ( $props['text'] ?? '' ) ) . "</h{$level}>";

			case 'citazione':
				return '<blockquote' . $attrs . '><p>' . self::inline_html( (string) ( $props['text'] ?? '' ) ) . '</p>'
					. ( ! empty( $props['attribution'] ) ? '<cite>' . esc_html( (string) $props['attribution'] ) . '</cite>' : '' )
					. '</blockquote>';

			case 'elenco':
				$tag   = ! empty( $props['ordered'] ) ? 'ol' : 'ul';
				$items = '';
				foreach ( (array) ( $props['items'] ?? array() ) as $item ) {
					$items .= '<li>' . self::inline_html( (string) $item ) . '</li>';
				}
				return "<{$tag}{$attrs}>{$items}</{$tag}>";

			case 'figura':
				$attachment_id = (int) ( $props['attachment_id'] ?? 0 );
				$img           = '';
				if ( $attachment_id > 0 ) {
					$src = (string) wp_get_attachment_url( $attachment_id );
					$img = '<img class="wp-image-' . $attachment_id . '" src="' . esc_url( $src ) . '" alt="' . esc_attr( (string) ( $props['alt'] ?? '' ) ) . '"/>';
				} else {
					$img = '<p class="gw-figura-placeholder"><em>' . esc_html( '[Figura da generare/caricare' . ( ! empty( $props['image_brief'] ) ? ': ' . (string) $props['image_brief'] : '' ) . ']' ) . '</em></p>';
				}
				return '<figure' . $attrs . '>' . $img
					. '<figcaption>' . self::inline_html( (string) ( $props['caption'] ?? '' ) ) . '</figcaption></figure>';

			case 'tabella':
				$out = '<table' . $attrs . '>';
				if ( ! empty( $props['header'] ) ) {
					$out .= '<thead><tr>';
					foreach ( (array) $props['header'] as $cell ) {
						$out .= '<th>' . esc_html( (string) $cell ) . '</th>';
					}
					$out .= '</tr></thead>';
				}
				$out .= '<tbody>';
				foreach ( (array) ( $props['rows'] ?? array() ) as $row ) {
					$out .= '<tr>';
					foreach ( (array) $row as $cell ) {
						$out .= '<td>' . esc_html( (string) $cell ) . '</td>';
					}
					$out .= '</tr>';
				}
				return $out . '</tbody></table>';

			case 'codice':
				return '<pre' . $attrs . ( ! empty( $props['language'] ) ? ' data-gw-language="' . esc_attr( (string) $props['language'] ) . '"' : '' ) . '><code>'
					. esc_html( (string) ( $props['code'] ?? '' ) ) . '</code></pre>';

			case 'separatore':
				return '<hr' . $attrs . '/>';

			default:
				// Blocchi complessi: rappresentazione leggibile ma bloccata.
				return '<div' . $attrs . ' class="gw-locked-block" contenteditable="false">'
					. '<p><strong>[' . esc_html( $type ) . ( ! empty( $props['title'] ) ? ': ' . esc_html( (string) $props['title'] ) : '' ) . ']</strong> '
					. '<em>' . esc_html__( 'Blocco strutturato: si modifica dalla scheda Capitoli (riscrittura AI), non da qui.', 'ghostwriter' ) . '</em></p>'
					. self::locked_preview( $type, $props )
					. '</div>';
		}
	}

	/**
	 * @param array<string, mixed> $props Props del blocco complesso.
	 */
	private static function locked_preview( string $type, array $props ): string {
		if ( 'esercizio' === $type || 'blurb' === $type ) {
			return '<p>' . self::inline_html( (string) ( $props['text'] ?? '' ) ) . '</p>';
		}
		$out = '';
		foreach ( (array) ( $props['blocks'] ?? array() ) as $nested ) {
			$nested = (array) $nested;
			$out   .= '<p>' . self::inline_html( (string) ( ( (array) ( $nested['props'] ?? array() ) )['text'] ?? '' ) ) . '</p>';
		}
		return $out;
	}

	// ------------------------------------------------------------------ //
	// HTML dell'editor → blocchi
	// ------------------------------------------------------------------ //

	/**
	 * @param string               $html     HTML dell'editor (già passato da wpautop).
	 * @param array<string, mixed> $previous Formato intermedio precedente.
	 * @return array<string, mixed> Nuovo formato intermedio (senza chapter_id).
	 */
	public static function to_blocks( string $html, array $previous ): array {
		$prev_blocks = array();
		foreach ( (array) ( $previous['blocks'] ?? array() ) as $block ) {
			$block = (array) $block;
			if ( ! empty( $block['id'] ) ) {
				$prev_blocks[ (string) $block['id'] ] = $block;
			}
		}

		$blocks = array();
		foreach ( self::top_level_elements( $html ) as $node ) {
			$block = self::element_to_block( $node, $prev_blocks );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		$content = array(
			'schema_version' => '1.0',
			'blocks'         => $blocks,
		);
		if ( ! empty( $previous['meta'] ) ) {
			$content['meta'] = $previous['meta'];
		}
		if ( ! empty( $previous['notes'] ) ) {
			$content['notes'] = $previous['notes'];
		}
		return $content;
	}

	/**
	 * @return \DOMElement[]
	 */
	private static function top_level_elements( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return array();
		}

		$elements = array();
		foreach ( $body->childNodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$elements[] = $node;
			}
		}
		return $elements;
	}

	/**
	 * @param array<string, array<string, mixed>> $prev_blocks Blocchi precedenti per id.
	 * @return array<string, mixed>|null
	 */
	private static function element_to_block( \DOMElement $el, array $prev_blocks ): ?array {
		$tag     = strtolower( $el->tagName );
		$prev_id = (string) $el->getAttribute( 'data-gw-id' );
		$decl    = (string) $el->getAttribute( 'data-gw-type' );

		// Blocchi complessi: ripristino verbatim dal contenuto precedente.
		if ( in_array( $decl, self::LOCKED_TYPES, true ) || 'gw-locked-block' === trim( (string) $el->getAttribute( 'class' ) ) ) {
			return $prev_blocks[ $prev_id ] ?? null;
		}

		$type  = null;
		$props = array();

		switch ( true ) {
			case 'p' === $tag:
				$text = self::inline_md( $el );
				// Un <p> che contiene solo un'immagine è una figura inserita col media uploader.
				$img = self::first_child_img( $el );
				if ( null !== $img && '' === trim( wp_strip_all_tags( $text ) ) ) {
					return self::img_to_figura( $img, $prev_blocks, $prev_id );
				}
				if ( '' === trim( $text ) ) {
					return null;
				}
				$type  = 'paragrafo';
				$props = array( 'text' => $text );
				break;

			case in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ):
				$type  = 'heading';
				$props = array(
					'text'  => trim( $el->textContent ),
					'level' => min( 4, max( 2, (int) substr( $tag, 1 ) ) ),
				);
				break;

			case 'blockquote' === $tag:
				$attribution = '';
				foreach ( $el->getElementsByTagName( 'cite' ) as $cite ) {
					$attribution = trim( $cite->textContent );
					$cite->parentNode?->removeChild( $cite );
					break;
				}
				$type  = 'citazione';
				$props = array( 'text' => self::inline_md( $el ) );
				if ( '' !== $attribution ) {
					$props['attribution'] = $attribution;
				}
				break;

			case 'ul' === $tag || 'ol' === $tag:
				$items = array();
				foreach ( $el->getElementsByTagName( 'li' ) as $li ) {
					$items[] = self::inline_md( $li );
				}
				$type  = 'elenco';
				$props = array( 'ordered' => 'ol' === $tag, 'items' => $items );
				break;

			case 'figure' === $tag:
				$img     = self::first_child_img( $el );
				$caption = '';
				foreach ( $el->getElementsByTagName( 'figcaption' ) as $figcaption ) {
					$caption = self::inline_md( $figcaption );
					break;
				}
				return self::img_to_figura( $img, $prev_blocks, $prev_id, $caption );

			case 'img' === $tag:
				return self::img_to_figura( $el, $prev_blocks, $prev_id );

			case 'table' === $tag:
				$header = array();
				$rows   = array();
				foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
					$cells    = array();
					$isHeader = false;
					foreach ( $tr->childNodes as $cell ) {
						if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
							$cells[]  = trim( $cell->textContent );
							$isHeader = $isHeader || 'th' === strtolower( $cell->tagName );
						}
					}
					if ( $isHeader && array() === $header ) {
						$header = $cells;
					} elseif ( array() !== $cells ) {
						$rows[] = $cells;
					}
				}
				$type  = 'tabella';
				$props = array( 'rows' => $rows );
				if ( array() !== $header ) {
					$props['header'] = $header;
				}
				break;

			case 'pre' === $tag:
				$type  = 'codice';
				$props = array( 'code' => rtrim( $el->textContent ) );
				$lang  = (string) $el->getAttribute( 'data-gw-language' );
				if ( '' !== $lang ) {
					$props['language'] = $lang;
				}
				break;

			case 'hr' === $tag:
				$type  = 'separatore';
				$props = array();
				break;

			default:
				// Elemento non riconosciuto con testo → paragrafo.
				$text = self::inline_md( $el );
				if ( '' === trim( $text ) ) {
					return null;
				}
				$type  = 'paragrafo';
				$props = array( 'text' => $text );
		}

		return self::finalize_block( $type, $props, $prev_blocks, $prev_id );
	}

	/**
	 * Conserva id/versione/fonti quando il blocco esiste ancora: version
	 * invariata se il contenuto non è cambiato, +1 se modificato.
	 *
	 * @param array<string, mixed>                $props       Props del nuovo blocco.
	 * @param array<string, array<string, mixed>> $prev_blocks Blocchi precedenti per id.
	 * @return array<string, mixed>
	 */
	private static function finalize_block( string $type, array $props, array $prev_blocks, string $prev_id ): array {
		$previous = ( '' !== $prev_id && isset( $prev_blocks[ $prev_id ] ) && ( $prev_blocks[ $prev_id ]['type'] ?? '' ) === $type )
			? $prev_blocks[ $prev_id ]
			: null;

		if ( null !== $previous ) {
			$prev_props = (array) ( $previous['props'] ?? array() );
			// Le props non rappresentabili nell'editor (es. image_brief, size,
			// role, epub_fallback) si conservano dal blocco precedente.
			$props = array_merge( $prev_props, $props );

			$changed = wp_json_encode( $props ) !== wp_json_encode( $prev_props );
			return array(
				'id'      => $prev_id,
				'type'    => $type,
				'version' => (int) ( $previous['version'] ?? 1 ) + ( $changed ? 1 : 0 ),
				'props'   => $props,
			) + ( isset( $previous['sources'] ) ? array( 'sources' => $previous['sources'] ) : array() );
		}

		return array(
			'id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) ),
			'type'    => $type,
			'version' => 1,
			'props'   => $props,
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $prev_blocks Blocchi precedenti per id.
	 * @return array<string, mixed>|null
	 */
	private static function img_to_figura( ?\DOMElement $img, array $prev_blocks, string $prev_id, string $caption = '' ): ?array {
		$props = array( 'caption' => $caption );

		if ( null !== $img ) {
			$attachment_id = 0;
			if ( preg_match( '/wp-image-(\d+)/', (string) $img->getAttribute( 'class' ), $m ) ) {
				$attachment_id = (int) $m[1];
			} elseif ( function_exists( 'attachment_url_to_postid' ) ) {
				$attachment_id = (int) attachment_url_to_postid( (string) $img->getAttribute( 'src' ) );
			}
			if ( $attachment_id > 0 ) {
				$props['attachment_id'] = $attachment_id;
			}
			$alt = (string) $img->getAttribute( 'alt' );
			if ( '' !== $alt ) {
				$props['alt'] = $alt;
			}
		}

		return self::finalize_block( 'figura', $props, $prev_blocks, $prev_id );
	}

	private static function first_child_img( \DOMElement $el ): ?\DOMElement {
		foreach ( $el->getElementsByTagName( 'img' ) as $img ) {
			return $img;
		}
		return null;
	}

	// ------------------------------------------------------------------ //
	// Inline: markdown ristretto ⇄ HTML
	// ------------------------------------------------------------------ //

	/**
	 * Markdown ristretto → HTML per l'editor (enfasi, link; [^id] resta testo).
	 */
	private static function inline_html( string $md ): string {
		$html = esc_html( $md );
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
		$html = (string) preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/s', '<em>$1</em>', $html );
		$html = (string) preg_replace( '/\[([^\]^][^\]]*)\]\((https?:[^)\s]+)\)/', '<a href="$2">$1</a>', $html );
		return $html;
	}

	/**
	 * HTML dell'editor → markdown ristretto del contratto.
	 */
	private static function inline_md( \DOMNode $node ): string {
		$md = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$md .= $child->textContent;
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$inner = self::inline_md( $child );
			$md   .= match ( strtolower( $child->tagName ) ) {
				'strong', 'b' => '**' . $inner . '**',
				'em', 'i'     => '*' . $inner . '*',
				'a'           => '[' . $inner . '](' . (string) $child->getAttribute( 'href' ) . ')',
				'br'          => "\n",
				'img'         => '',
				default       => $inner,
			};
		}
		return trim( (string) preg_replace( '/[ \t]+/', ' ', $md ) );
	}
}

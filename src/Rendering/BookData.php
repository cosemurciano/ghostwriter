<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

/**
 * Snapshot immutabile di un libro pronto per il rendering: gli exporter
 * consumano QUESTO, mai WordPress direttamente. L'assemblaggio da CPT,
 * meta e Media Library è compito del BookAssembler.
 */
final class BookData {

	/**
	 * @param array<int, array{id: int, title: string, depth: int, content: array<string, mixed>}> $chapters
	 *        Capitoli in ordine di lettura; depth 0 = capitolo/parte radice, 1+ = sottocapitoli.
	 * @param array<int, string> $images       Mappa attachment_id → path file locale.
	 * @param string[]           $bibliography Citazioni formattate (dal SourceRegistry).
	 */
	public function __construct(
		public readonly string $title,
		public readonly ?string $subtitle,
		public readonly string $author,
		public readonly string $language,
		public readonly float $trim_width_mm,
		public readonly float $trim_height_mm,
		public readonly array $chapters,
		public readonly array $images = array(),
		public readonly array $bibliography = array(),
		public readonly string $publisher = '',
		public readonly string $isbn = '',
		public readonly string $year = '',
		public readonly string $edition = '',
		public readonly ?string $identifier = null,
		public readonly ?string $cover_path = null
	) {
	}

	/**
	 * Path locale dell'immagine di un blocco figura, null se non risolta.
	 */
	public function image_path( ?int $attachment_id ): ?string {
		if ( null === $attachment_id || $attachment_id <= 0 ) {
			return null;
		}
		return $this->images[ $attachment_id ] ?? null;
	}

	/**
	 * Valori per i placeholder dei frammenti pagina del tema:
	 * {title} {subtitle} {author} {year} {publisher} {isbn} {edition} {book_title}.
	 *
	 * @return array<string, string>
	 */
	public function placeholders(): array {
		return array(
			'{title}'      => $this->title,
			'{subtitle}'   => $this->subtitle ?? '',
			'{author}'     => $this->author,
			'{year}'       => $this->year,
			'{publisher}'  => $this->publisher,
			'{isbn}'       => $this->isbn,
			'{edition}'    => $this->edition,
			'{book_title}' => $this->title,
		);
	}

	/**
	 * Applica i placeholder a un frammento HTML del tema (i valori vengono escapati).
	 */
	public function fill_placeholders( string $fragment ): string {
		$replacements = array();
		foreach ( $this->placeholders() as $key => $value ) {
			$replacements[ $key ] = htmlspecialchars( $value, ENT_QUOTES | ENT_XHTML, 'UTF-8' );
		}
		return strtr( $fragment, $replacements );
	}
}

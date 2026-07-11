<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

/**
 * Tema grafico dichiarativo (theme.json + fonts/ + assets/ + pages/).
 * Value object di sola lettura sul contenuto validato del pacchetto.
 */
final class Theme {

	/**
	 * @param array<string, mixed> $data     Contenuto di theme.json (validato contro theme.schema.json).
	 * @param string               $base_dir Cartella del pacchetto tema (contiene theme.json).
	 */
	public function __construct(
		private array $data,
		private string $base_dir
	) {
		$this->base_dir = rtrim( $base_dir, '/' );
	}

	public function id(): string {
		return self::slugify( (string) $this->data['meta']['name'] );
	}

	public function name(): string {
		return (string) $this->data['meta']['name'];
	}

	public function version(): string {
		return (string) $this->data['meta']['version'];
	}

	public function base_dir(): string {
		return $this->base_dir;
	}

	/** @return string[] */
	public function supports_blocks(): array {
		return array_map( 'strval', $this->data['meta']['supports_blocks'] ?? array() );
	}

	/**
	 * Verifica che il tema dichiari il formato pagina del progetto
	 * (tolleranza 1mm per gli arrotondamenti).
	 */
	public function supports_format( float $width_mm, float $height_mm ): bool {
		foreach ( $this->data['meta']['supports_formats'] ?? array() as $format ) {
			if (
				abs( (float) ( $format['width_mm'] ?? 0 ) - $width_mm ) <= 1.0
				&& abs( (float) ( $format['height_mm'] ?? 0 ) - $height_mm ) <= 1.0
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, mixed>|null Definizione font per ruolo (body|heading|mono|caption).
	 */
	public function font( string $role ): ?array {
		$font = $this->data['tokens']['fonts'][ $role ] ?? null;
		return is_array( $font ) ? $font : null;
	}

	/** @return array<string, array<string, mixed>> */
	public function fonts(): array {
		return (array) ( $this->data['tokens']['fonts'] ?? array() );
	}

	/**
	 * Colore dalla palette. Per l'ePub preferisce la variante ad alto
	 * contrasto epub_value se dichiarata.
	 */
	public function palette_color( string $name, bool $for_epub = false ): ?string {
		$entry = $this->data['tokens']['palette'][ $name ] ?? null;
		if ( ! is_array( $entry ) ) {
			return null;
		}
		if ( $for_epub && ! empty( $entry['epub_value'] ) ) {
			return (string) $entry['epub_value'];
		}
		return isset( $entry['value'] ) ? (string) $entry['value'] : null;
	}

	/** @return string[] Nomi dei colori della palette. */
	public function palette_names(): array {
		return array_keys( (array) ( $this->data['tokens']['palette'] ?? array() ) );
	}

	public function body_size_pt(): float {
		return (float) ( $this->data['tokens']['scale']['body_size_pt'] ?? 11 );
	}

	public function leading(): float {
		return (float) ( $this->data['tokens']['scale']['leading'] ?? 1.45 );
	}

	public function scale_ratio(): float {
		return (float) ( $this->data['tokens']['scale']['ratio'] ?? 1.25 );
	}

	/** @return array<string, mixed> Direttive di pagina (solo PDF). */
	public function page(): array {
		return (array) ( $this->data['page'] ?? array() );
	}

	/** @return array<string, array<string, mixed>> Mapping tipo blocco → stile. */
	public function block_styles(): array {
		return (array) ( $this->data['blocks'] ?? array() );
	}

	/** @return array<string, mixed> Direttive solo-PDF. */
	public function pdf(): array {
		return (array) ( $this->data['pdf'] ?? array() );
	}

	/** @return array<string, mixed> Direttive solo-ePub. */
	public function epub(): array {
		return (array) ( $this->data['epub'] ?? array() );
	}

	/**
	 * Frammento HTML parametrico da pages/ (half_title, title_page, colophon,
	 * cover_composition.front/back/spine). Null se non dichiarato o mancante.
	 */
	public function page_fragment( string $key ): ?string {
		$special = (array) ( $this->data['special_pages'] ?? array() );

		$path = match ( $key ) {
			'half_title', 'title_page', 'colophon' => $special[ $key ] ?? null,
			'cover_front' => $special['cover_composition']['front'] ?? null,
			'cover_back'  => $special['cover_composition']['back'] ?? null,
			'cover_spine' => $special['cover_composition']['spine'] ?? null,
			default       => null,
		};

		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$file = $this->base_dir . '/' . ltrim( $path, '/' );
		// Il path dichiarato non può uscire dal pacchetto tema.
		$real = realpath( $file );
		if ( false === $real || ! str_starts_with( $real, $this->base_dir . '/' ) ) {
			return null;
		}

		$content = file_get_contents( $real );
		return false !== $content ? $content : null;
	}

	/** @return array<string, mixed> */
	public function raw(): array {
		return $this->data;
	}

	public static function slugify( string $name ): string {
		$slug = strtolower( trim( $name ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug, '-' );
	}
}

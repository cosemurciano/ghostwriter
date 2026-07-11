<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

use Ghostwriter\Queue\Jobs\GenerateImageJob;
use Ghostwriter\Schema\SchemaValidator;

/**
 * Verifiche pre-export (ARCHITECTURE.md §8). Gli errori bloccano l'export;
 * i warning vengono riportati in UI ma non fermano.
 */
final class Preflight {

	/** @var callable(int): ?string */
	private $attachment_path;

	/** @var callable(string): ?array */
	private $image_size;

	/**
	 * @param callable|null $attachment_path attachment_id → path locale (default: get_attached_file).
	 * @param callable|null $image_size      path → [width, height] (default: getimagesize).
	 */
	public function __construct(
		private SchemaValidator $validator,
		?callable $attachment_path = null,
		?callable $image_size = null
	) {
		$this->attachment_path = $attachment_path ?? static function ( int $id ): ?string {
			$path = get_attached_file( $id );
			return is_string( $path ) && '' !== $path ? $path : null;
		};
		$this->image_size      = $image_size ?? static function ( string $path ): ?array {
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false !== $size ? array( (int) $size[0], (int) $size[1] ) : null;
		};
	}

	/**
	 * Esegue il preflight completo.
	 *
	 * @param array<int, array<string, mixed>> $chapters chapter_id → formato intermedio.
	 * @param array<string, mixed>             $config   Config progetto.
	 * @param array<string, mixed>             $dossier  Dossier progetto.
	 * @param string                           $target   pdf|epub.
	 *
	 * @return array{errors: string[], warnings: string[]}
	 */
	public function run( array $chapters, array $config, array $dossier, Theme $theme, string $target ): array {
		$errors   = array();
		$warnings = array();

		// 1. Compatibilità formato progetto ↔ tema.
		$width  = (float) ( $config['format']['trim_width_mm'] ?? 0 );
		$height = (float) ( $config['format']['trim_height_mm'] ?? 0 );
		if ( 'pdf' === $target && ! $theme->supports_format( $width, $height ) ) {
			$errors[] = sprintf( 'Il tema %s non supporta il formato %s×%s mm.', $theme->name(), $width, $height );
		}

		// 2. Copertura blocchi: allowed_blocks ⊆ supports_blocks.
		$missing = array_diff(
			array_map( 'strval', (array) ( $config['structural_profile']['allowed_blocks'] ?? array() ) ),
			$theme->supports_blocks()
		);
		if ( ! empty( $missing ) ) {
			$errors[] = 'Il tema non copre i blocchi del profilo strutturale: ' . implode( ', ', $missing ) . '.';
		}

		// 3-6. Verifiche per capitolo.
		$registry_ids = array_map(
			static fn( array $s ): string => (string) ( $s['source_id'] ?? '' ),
			(array) ( $config['sources']['registry'] ?? array() )
		);
		$print_ready  = ! empty( $config['format']['print_ready'] );

		foreach ( $chapters as $chapter_id => $content ) {
			$label  = (string) ( $content['meta']['title'] ?? "capitolo {$chapter_id}" );
			$blocks = (array) ( $content['blocks'] ?? array() );

			// Validazione schema.
			$schema_errors = $this->validator->get_validation_errors( $content, SchemaValidator::CHAPTER_CONTENT );
			if ( ! empty( $schema_errors ) ) {
				$errors[] = "«{$label}»: contenuto non conforme allo schema (" . $schema_errors[0]['property'] . ': ' . $schema_errors[0]['message'] . ').';
			}

			// Figure risolte + risoluzione.
			foreach ( GenerateImageJob::unresolved_figure_ids( $blocks ) as $block_id ) {
				$errors[] = "«{$label}»: figura {$block_id} senza immagine (attachment_id nullo).";
			}
			foreach ( self::resolved_figures( $blocks ) as $figure ) {
				$path = ( $this->attachment_path )( $figure['attachment_id'] );
				if ( null === $path || ! file_exists( $path ) ) {
					$errors[] = "«{$label}»: il file dell'immagine {$figure['attachment_id']} non esiste più.";
					continue;
				}
				if ( $print_ready ) {
					$size = ( $this->image_size )( $path );
					[$needed] = \Ghostwriter\Media\ImageService::target_resolution( $config, $figure['size'] );
					if ( null !== $size && $size[0] < (int) ( $needed * 0.75 ) ) {
						$warnings[] = "«{$label}»: immagine {$figure['attachment_id']} a bassa risoluzione per la stampa ({$size[0]}px, attesi ~{$needed}px).";
					}
				}
			}

			// Note referenziate esistenti (e viceversa).
			$note_ids = array_map( static fn( array $n ): string => (string) ( $n['note_id'] ?? '' ), (array) ( $content['notes'] ?? array() ) );
			$refs     = self::note_refs( $blocks );
			foreach ( array_diff( $refs, $note_ids ) as $missing_note ) {
				$errors[] = "«{$label}»: riferimento alla nota [{$missing_note}] senza nota corrispondente.";
			}
			foreach ( array_diff( $note_ids, $refs ) as $orphan ) {
				$warnings[] = "«{$label}»: la nota [{$orphan}] non è referenziata da nessun blocco.";
			}

			// Provenienza: i source_id dei blocchi devono esistere nel registry.
			foreach ( array_diff( self::used_source_ids( $blocks ), $registry_ids ) as $unknown ) {
				$errors[] = "«{$label}»: fonte {$unknown} citata ma assente dal registry del progetto.";
			}
		}

		// 4. Promesse del dossier mantenute.
		foreach ( (array) ( $dossier['continuity']['promises'] ?? array() ) as $promise ) {
			if ( empty( $promise['fulfilled'] ) ) {
				$errors[] = 'Promessa al lettore non mantenuta: «' . (string) ( $promise['text'] ?? '' ) . '».';
			}
		}

		// 6. Fonti con obbligo di attribuzione: metadati sufficienti per la bibliografia.
		foreach ( (array) ( $config['sources']['registry'] ?? array() ) as $source ) {
			if ( ! empty( $source['attribution_required'] ) && empty( $source['citation'] ) && empty( $source['authors'] ) ) {
				$warnings[] = 'Fonte «' . (string) ( $source['title'] ?? $source['source_id'] ?? '' ) . '»: attribuzione richiesta ma senza citazione né autori.';
			}
		}

		// 7. Font embeddabili per l'ePub.
		if ( 'epub' === $target && ! empty( $theme->epub()['embed_fonts'] ) ) {
			foreach ( $theme->fonts() as $role => $font ) {
				if ( ! empty( $font['files'] ) && is_array( $font['files'] ) && count( $font['files'] ) > 0 && empty( $font['embeddable'] ) ) {
					$errors[] = "Font «{$font['family']}» ({$role}): licenza non embeddabile con embed_fonts attivo.";
				}
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi (anche annidati).
	 * @return array<int, array{attachment_id: int, size: string}>
	 */
	public static function resolved_figures( array $blocks ): array {
		$figures = array();
		foreach ( $blocks as $block ) {
			if ( 'figura' === ( $block['type'] ?? '' ) && ! empty( $block['props']['attachment_id'] ) ) {
				$figures[] = array(
					'attachment_id' => (int) $block['props']['attachment_id'],
					'size'          => (string) ( $block['props']['size'] ?? 'medium' ),
				);
			}
			if ( ! empty( $block['props']['blocks'] ) && is_array( $block['props']['blocks'] ) ) {
				$figures = array_merge( $figures, self::resolved_figures( $block['props']['blocks'] ) );
			}
		}
		return $figures;
	}

	/**
	 * Tutti i note_id referenziati nei testi ([^id]), anche annidati.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocchi.
	 * @return string[]
	 */
	public static function note_refs( array $blocks ): array {
		$refs = array();
		foreach ( $blocks as $block ) {
			$props = (array) ( $block['props'] ?? array() );
			foreach ( array( 'text', 'caption' ) as $key ) {
				if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) && preg_match_all( '/\[\^([A-Za-z0-9_-]+)\]/', $props[ $key ], $m ) ) {
					$refs = array_merge( $refs, $m[1] );
				}
			}
			foreach ( (array) ( $props['items'] ?? array() ) as $item ) {
				if ( is_string( $item ) && preg_match_all( '/\[\^([A-Za-z0-9_-]+)\]/', $item, $m ) ) {
					$refs = array_merge( $refs, $m[1] );
				}
			}
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] ) ) {
				$refs = array_merge( $refs, self::note_refs( $props['blocks'] ) );
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi.
	 * @return string[]
	 */
	public static function used_source_ids( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			foreach ( (array) ( $block['sources'] ?? array() ) as $ref ) {
				if ( ! empty( $ref['source_id'] ) ) {
					$ids[] = (string) $ref['source_id'];
				}
			}
			if ( ! empty( $block['props']['blocks'] ) && is_array( $block['props']['blocks'] ) ) {
				$ids = array_merge( $ids, self::used_source_ids( $block['props']['blocks'] ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}
}

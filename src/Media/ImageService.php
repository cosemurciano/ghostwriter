<?php
declare(strict_types=1);

namespace Ghostwriter\Media;

use Ghostwriter\Ai\ImageResult;

/**
 * Dal binario generato alla Media Library: brief → immagine → attachment_id.
 * La risoluzione target dipende dal formato fisico del progetto (300dpi se
 * print_ready, 150dpi altrimenti) e dalla size del blocco figura.
 */
final class ImageService {

	/**
	 * Risoluzione target in pixel per una figura.
	 *
	 * @param array<string, mixed> $config Config progetto (chiave format).
	 * @param string               $size   small|medium|full (dal blocco figura).
	 * @return array{0: int, 1: int} [width_px, height_px]
	 */
	public static function target_resolution( array $config, string $size = 'medium' ): array {
		$format = (array) ( $config['format'] ?? array() );
		$dpi    = ! empty( $format['print_ready'] ) ? 300 : 150;

		$fraction = match ( $size ) {
			'small' => 0.5,
			'full'  => 1.0,
			default => 0.75,
		};

		$width_mm = (float) ( $format['trim_width_mm'] ?? 150 ) * $fraction;
		$width_px = (int) round( $width_mm / 25.4 * $dpi );
		// Proporzione 4:3, sufficiente come default: il tema gestisce il resto.
		$height_px = (int) round( $width_px * 0.75 );

		return array( max( 256, $width_px ), max( 256, $height_px ) );
	}

	/**
	 * Salva l'immagine generata nella Media Library del progetto.
	 *
	 * @return int attachment_id
	 * @throws \RuntimeException Se il salvataggio fallisce.
	 */
	public function save_to_media_library( ImageResult $image, int $project_id, string $block_id, string $alt = '' ): int {
		$filename = sanitize_file_name( sprintf( 'gw-%d-%s.%s', $project_id, substr( md5( $block_id ), 0, 8 ), $image->extension() ) );

		$upload = wp_upload_bits( $filename, null, $image->binary );
		if ( ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( 'Upload immagine fallito: ' . $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $image->mime,
				'post_title'     => $filename,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			throw new \RuntimeException( 'Creazione attachment fallita: ' . $attachment_id->get_error_message() );
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $attachment_id, '_gw_project_id', $project_id );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return (int) $attachment_id;
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Immagine generata: binario + mime, pronta per la Media Library.
 */
final class ImageResult {

	public function __construct(
		public readonly string $binary,
		public readonly string $mime = 'image/png',
		public readonly string $model = ''
	) {
	}

	public function extension(): string {
		return match ( $this->mime ) {
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			default      => 'png',
		};
	}
}

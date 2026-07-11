<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Richiesta di generazione immagine da un image_brief del formato intermedio.
 */
final class ImageRequest {

	public function __construct(
		public readonly string $brief,
		public readonly int $width_px,
		public readonly int $height_px,
		public readonly int $project_id,
		public readonly ?int $chapter_id = null,
		public readonly ?string $block_id = null
	) {
	}
}

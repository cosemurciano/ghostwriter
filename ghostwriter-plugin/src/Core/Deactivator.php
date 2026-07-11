<?php
declare(strict_types=1);

namespace Ghostwriter\Core;

/**
 * Disattivazione: solo pulizia leggera. Tabelle, contenuti e ruoli restano
 * (la rimozione definitiva è compito di uninstall.php, non della disattivazione).
 */
final class Deactivator {

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

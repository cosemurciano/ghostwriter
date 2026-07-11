<?php
declare(strict_types=1);

namespace Ghostwriter\Rendering;

use Ghostwriter\Schema\SchemaValidator;

/**
 * Registry dei temi grafici: temi di serie (themes-bundled/ nel plugin) e
 * temi importati da zip (uploads/ghostwriter/themes/{id}/{version}/).
 *
 * Gli zip importati vengono estratti in staging, validati (schema + niente
 * PHP nel bundle) e solo poi registrati.
 */
final class ThemeRegistry {

	public function __construct(
		private SchemaValidator $validator,
		private string $bundled_dir,
		private ?string $imported_dir = null
	) {
		$this->bundled_dir = rtrim( $bundled_dir, '/' );
		if ( null !== $imported_dir ) {
			$this->imported_dir = rtrim( $imported_dir, '/' );
		}
	}

	/**
	 * Tutti i temi disponibili. Chiave: "{id}@{version}".
	 * A parità di id e versione, il tema importato vince sul bundled.
	 *
	 * @return array<string, Theme>
	 */
	public function all(): array {
		$themes = array();

		foreach ( $this->theme_dirs() as $dir ) {
			try {
				$theme = $this->load_dir( $dir );
			} catch ( \Throwable ) {
				continue; // Un tema rotto non deve rompere il registry.
			}
			$themes[ $theme->id() . '@' . $theme->version() ] = $theme;
		}

		return $themes;
	}

	/**
	 * Tema per id, opzionalmente a versione esatta (i progetti avviati
	 * referenziano sempre la versione bloccata). Senza versione: la più recente.
	 */
	public function get( string $id, ?string $version = null ): ?Theme {
		$best = null;
		foreach ( $this->all() as $theme ) {
			if ( $theme->id() !== $id ) {
				continue;
			}
			if ( null !== $version ) {
				if ( $theme->version() === $version ) {
					return $theme;
				}
				continue;
			}
			if ( null === $best || version_compare( $theme->version(), $best->version(), '>' ) ) {
				$best = $theme;
			}
		}
		return $best;
	}

	/**
	 * Carica e valida un pacchetto tema da una cartella con theme.json.
	 *
	 * @throws \RuntimeException Se theme.json manca o non valida.
	 */
	public function load_dir( string $dir ): Theme {
		$dir  = rtrim( $dir, '/' );
		$file = $dir . '/theme.json';

		if ( ! file_exists( $file ) ) {
			throw new \RuntimeException( "theme.json mancante in {$dir}" );
		}

		$json = (string) file_get_contents( $file );

		// La validazione avviene sulla forma a oggetti del JSON originale:
		// il round-trip per array associativi degrada gli oggetti vuoti
		// (es. "files": {}) in array e falserebbe il tipo.
		$as_object = json_decode( $json );
		$data      = json_decode( $json, true );
		if ( ! is_object( $as_object ) || ! is_array( $data ) ) {
			throw new \RuntimeException( "theme.json non parsabile in {$dir}" );
		}

		$this->validator->validate( $as_object, SchemaValidator::THEME );

		return new Theme( $data, $dir );
	}

	/**
	 * Importa un tema da zip: estrazione in staging, validazione, poi
	 * registrazione in imported_dir/{id}/{version}/.
	 *
	 * @throws \RuntimeException In caso di zip invalido, schema non conforme o PHP nel bundle.
	 */
	public function import_zip( string $zip_path ): Theme {
		if ( null === $this->imported_dir ) {
			throw new \RuntimeException( 'Cartella temi importati non configurata.' );
		}

		$staging = sys_get_temp_dir() . '/gw-theme-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $staging, 0700, true ) ) {
			throw new \RuntimeException( 'Impossibile creare la cartella di staging.' );
		}

		try {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				throw new \RuntimeException( 'Zip non apribile.' );
			}

			// Difesa da zip-slip: nessun entry può uscire dallo staging.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$entry = (string) $zip->getNameIndex( $i );
				if ( str_contains( $entry, '..' ) || str_starts_with( $entry, '/' ) ) {
					throw new \RuntimeException( "Percorso non ammesso nello zip: {$entry}" );
				}
			}

			$zip->extractTo( $staging );
			$zip->close();

			// theme.json alla radice o in un'unica sottocartella.
			$root = $staging;
			if ( ! file_exists( $root . '/theme.json' ) ) {
				$entries = array_values( array_diff( scandir( $root ) ?: array(), array( '.', '..' ) ) );
				if ( 1 === count( $entries ) && is_dir( $root . '/' . $entries[0] ) ) {
					$root .= '/' . $entries[0];
				}
			}

			// Niente codice eseguibile nei bundle.
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( preg_match( '/\.(php|phtml|phar)$/i', $file->getFilename() ) ) {
					throw new \RuntimeException( 'Il pacchetto tema contiene file PHP: import rifiutato.' );
				}
			}

			$theme = $this->load_dir( $root );

			$target = "{$this->imported_dir}/{$theme->id()}/{$theme->version()}";
			if ( is_dir( $target ) ) {
				throw new \RuntimeException( "Tema {$theme->id()}@{$theme->version()} già registrato: aumentare la versione." );
			}
			if ( ! mkdir( $target, 0755, true ) ) {
				throw new \RuntimeException( 'Impossibile creare la cartella del tema.' );
			}
			self::copy_dir( $root, $target );

			return $this->load_dir( $target );
		} finally {
			self::remove_dir( $staging );
		}
	}

	/**
	 * @return string[] Cartelle candidate (bundled prima, importate dopo).
	 */
	private function theme_dirs(): array {
		$dirs = array();

		foreach ( glob( $this->bundled_dir . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			$dirs[] = $dir;
		}

		if ( null !== $this->imported_dir && is_dir( $this->imported_dir ) ) {
			// Struttura: {id}/{version}/theme.json
			foreach ( glob( $this->imported_dir . '/*/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
				$dirs[] = $dir;
			}
		}

		return $dirs;
	}

	private static function copy_dir( string $from, string $to ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$dest = $to . '/' . $iterator->getSubPathname();
			if ( $item->isDir() ) {
				if ( ! is_dir( $dest ) ) {
					mkdir( $dest, 0755, true );
				}
			} else {
				copy( $item->getPathname(), $dest );
			}
		}
	}

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}

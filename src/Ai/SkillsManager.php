<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Skills come bundle locali: uploads/ghostwriter/skills/{skill}/{version}/SKILL.md.
 *
 * Per Anthropic il contenuto viene composto nel contesto dal ContextComposer;
 * la sincronizzazione remota su OpenAI (POST /v1/skills) arriverà quando
 * servirà: stessa sorgente, due strategie di consegna (§6).
 */
final class SkillsManager {

	public function __construct( private string $skills_dir ) {
		$this->skills_dir = rtrim( $skills_dir, '/' );
	}

	/**
	 * Contenuto di una skill a versione esatta (versione bloccata per progetto).
	 */
	public function get_content( string $skill_id, string|int $version ): ?string {
		$file = sprintf( '%s/%s/%s/SKILL.md', $this->skills_dir, self::sanitize_id( $skill_id ), self::sanitize_id( (string) $version ) );
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$content = file_get_contents( $file );
		return false !== $content ? $content : null;
	}

	/**
	 * Skills montate su una fase, con contenuto (per il ContextComposer).
	 * Le skill dichiarate ma assenti dal registry locale vengono segnalate,
	 * non ignorate in silenzio.
	 *
	 * @param array<string, mixed> $config Config progetto (chiave skills).
	 * @return array<int, array{skill_id: string, version: string, content: string}>
	 * @throws \RuntimeException Se una skill montata non esiste nel registry locale.
	 */
	public function mounted_for_phase( array $config, string $phase ): array {
		$mounted = array();

		foreach ( (array) ( $config['skills'] ?? array() ) as $skill ) {
			$phases = array_map( 'strval', (array) ( $skill['phases'] ?? array() ) );
			if ( ! in_array( $phase, $phases, true ) ) {
				continue;
			}

			$skill_id = (string) ( $skill['skill_id'] ?? '' );
			$version  = (string) ( $skill['version'] ?? '' );
			$content  = $this->get_content( $skill_id, $version );

			if ( null === $content ) {
				throw new \RuntimeException( "Skill {$skill_id}@{$version} non presente nel registry locale." );
			}

			$mounted[] = array(
				'skill_id' => $skill_id,
				'version'  => $version,
				'content'  => $content,
			);
		}

		return $mounted;
	}

	/**
	 * Registra (o aggiorna) una skill nel registry locale.
	 */
	public function put( string $skill_id, string|int $version, string $content ): void {
		$dir = sprintf( '%s/%s/%s', $this->skills_dir, self::sanitize_id( $skill_id ), self::sanitize_id( (string) $version ) );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			throw new \RuntimeException( "Impossibile creare la cartella skill {$dir}." );
		}
		file_put_contents( $dir . '/SKILL.md', $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @return array<string, string[]> skill_id → versioni disponibili.
	 */
	public function registry(): array {
		$registry = array();
		foreach ( glob( $this->skills_dir . '/*/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( ! file_exists( $dir . '/SKILL.md' ) ) {
				continue;
			}
			$version  = basename( $dir );
			$skill_id = basename( dirname( $dir ) );
			$registry[ $skill_id ][] = $version;
		}
		return $registry;
	}

	/**
	 * Importa una skill da zip (cartella con SKILL.md in stile Claude skills:
	 * frontmatter YAML con name/description/metadata.version). Rifiuta PHP.
	 *
	 * @return array{skill_id: string, version: string}
	 */
	public function import_zip( string $zip_path ): array {
		$staging = sys_get_temp_dir() . '/gw-skill-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $staging, 0700, true ) ) {
			throw new \RuntimeException( 'Staging non creabile.' );
		}

		try {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				throw new \RuntimeException( 'Zip non apribile.' );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$entry = (string) $zip->getNameIndex( $i );
				if ( str_contains( $entry, '..' ) || str_starts_with( $entry, '/' ) || preg_match( '/\.(php|phtml|phar)$/i', $entry ) ) {
					throw new \RuntimeException( "Voce non ammessa nello zip: {$entry}" );
				}
			}
			$zip->extractTo( $staging );
			$zip->close();

			// SKILL.md alla radice o in un'unica sottocartella.
			$root = $staging;
			if ( ! file_exists( $root . '/SKILL.md' ) ) {
				$entries = array_values( array_diff( scandir( $root ) ?: array(), array( '.', '..' ) ) );
				if ( 1 === count( $entries ) && is_dir( $root . '/' . $entries[0] ) ) {
					$root .= '/' . $entries[0];
				}
			}
			if ( ! file_exists( $root . '/SKILL.md' ) ) {
				throw new \RuntimeException( 'SKILL.md mancante nel pacchetto.' );
			}

			$meta     = self::parse_frontmatter( (string) file_get_contents( $root . '/SKILL.md' ) );
			$skill_id = self::sanitize_id( (string) ( $meta['name'] ?: basename( $root ) ) );
			$version  = self::sanitize_id( (string) ( $meta['version'] ?: '1' ) );

			$target = sprintf( '%s/%s/%s', $this->skills_dir, $skill_id, $version );
			if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
				throw new \RuntimeException( 'Cartella skill non creabile.' );
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iterator as $item ) {
				$dest = $target . '/' . $iterator->getSubPathname();
				$item->isDir() ? ( is_dir( $dest ) || mkdir( $dest, 0755, true ) ) : copy( $item->getPathname(), $dest );
			}

			return array( 'skill_id' => $skill_id, 'version' => $version );
		} finally {
			$rm = static function ( string $dir ) use ( &$rm ): void {
				if ( ! is_dir( $dir ) ) {
					return;
				}
				foreach ( array_diff( scandir( $dir ) ?: array(), array( '.', '..' ) ) as $item ) {
					$path = $dir . '/' . $item;
					is_dir( $path ) ? $rm( $path ) : unlink( $path );
				}
				rmdir( $dir );
			};
			$rm( $staging );
		}
	}

	/**
	 * Metadati dal frontmatter YAML di una skill installata (per la UI).
	 *
	 * @return array{name: string, version: string, description: string, default_phases: string[]}
	 */
	public function describe( string $skill_id, string $version ): array {
		$meta = self::parse_frontmatter( (string) $this->get_content( $skill_id, $version ) );
		return array(
			'name'           => $meta['name'] ?: $skill_id,
			'version'        => $version,
			'description'    => $meta['description'],
			'default_phases' => $meta['default_phases'],
		);
	}

	public function delete( string $skill_id, string $version ): void {
		$dir = sprintf( '%s/%s/%s', $this->skills_dir, self::sanitize_id( $skill_id ), self::sanitize_id( $version ) );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
		$parent = dirname( $dir );
		if ( is_dir( $parent ) && 2 === count( scandir( $parent ) ?: array() ) ) {
			rmdir( $parent );
		}
	}

	/**
	 * Parser minimale del frontmatter (name, description anche multiriga
	 * indentata, metadata.version, x-ghostwriter.default_phases).
	 *
	 * @return array{name: string, version: string, description: string, default_phases: string[]}
	 */
	public static function parse_frontmatter( string $content ): array {
		$out = array( 'name' => '', 'version' => '', 'description' => '', 'default_phases' => array() );
		if ( ! preg_match( '/^---\s*\n(.*?)\n---/s', $content, $m ) ) {
			return $out;
		}
		$yaml = $m[1];
		if ( preg_match( '/^name:\s*(.+)$/m', $yaml, $mm ) ) {
			$out['name'] = trim( $mm[1], " \"'" );
		}
		if ( preg_match( '/^\s*version:\s*(.+)$/m', $yaml, $mm ) ) {
			$out['version'] = trim( $mm[1], " \"'" );
		}
		if ( preg_match( '/^description:\s*(.*(?:\n[ \t]+.*)*)/m', $yaml, $mm ) ) {
			$out['description'] = trim( (string) preg_replace( '/\s+/', ' ', $mm[1] ) );
		}
		if ( preg_match( '/default_phases:\s*\[([^\]]*)\]/', $yaml, $mm ) ) {
			$out['default_phases'] = array_values( array_filter( array_map( static fn( $p ) => trim( $p, " \"'" ), explode( ',', $mm[1] ) ) ) );
		}
		return $out;
	}

	private static function sanitize_id( string $id ): string {
		$clean = (string) preg_replace( '/[^a-zA-Z0-9._-]/', '', $id );
		if ( '' === $clean || str_contains( $id, '..' ) ) {
			throw new \InvalidArgumentException( "Identificatore skill non valido: {$id}" );
		}
		return $clean;
	}
}

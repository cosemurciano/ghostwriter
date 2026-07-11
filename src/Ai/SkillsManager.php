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

	private static function sanitize_id( string $id ): string {
		$clean = (string) preg_replace( '/[^a-zA-Z0-9._-]/', '', $id );
		if ( '' === $clean || str_contains( $id, '..' ) ) {
			throw new \InvalidArgumentException( "Identificatore skill non valido: {$id}" );
		}
		return $clean;
	}
}

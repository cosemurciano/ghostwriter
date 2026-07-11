<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Chiavi API dei provider: SOLO da costanti in wp-config.php, mai nel
 * database e mai nei log.
 *
 * In wp-config.php:
 *
 *     define( 'GHOSTWRITER_ANTHROPIC_API_KEY', 'sk-ant-...' );
 *     define( 'GHOSTWRITER_OPENAI_API_KEY',    'sk-...' );
 *
 * (In assenza delle costanti GHOSTWRITER_* si accettano le convenzionali
 * ANTHROPIC_API_KEY / OPENAI_API_KEY.)
 */
final class ApiKeys {

	public static function anthropic(): ?string {
		return self::from_constants( array( 'GHOSTWRITER_ANTHROPIC_API_KEY', 'ANTHROPIC_API_KEY' ) );
	}

	public static function openai(): ?string {
		return self::from_constants( array( 'GHOSTWRITER_OPENAI_API_KEY', 'OPENAI_API_KEY' ) );
	}

	public static function for_provider( string $provider ): ?string {
		return match ( $provider ) {
			'anthropic' => self::anthropic(),
			'openai'    => self::openai(),
			default     => null,
		};
	}

	/**
	 * @param string[] $names Costanti candidate in ordine di priorità.
	 */
	private static function from_constants( array $names ): ?string {
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$value = constant( $name );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					return trim( $value );
				}
			}
		}
		return null;
	}

	/**
	 * Maschera una chiave per i messaggi d'errore in UI (mai la chiave intera).
	 */
	public static function mask( string $key ): string {
		if ( strlen( $key ) <= 8 ) {
			return '****';
		}
		return substr( $key, 0, 6 ) . '…' . substr( $key, -4 );
	}
}

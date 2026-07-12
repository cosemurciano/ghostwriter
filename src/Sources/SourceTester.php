<?php
declare(strict_types=1);

namespace Ghostwriter\Sources;

use Ghostwriter\Ai\ApiKeys;

/**
 * Verifica di raggiungibilità delle fonti PRIMA dell'ingestione:
 * URL (HTTP), media WP (file su disco), path PDF, articoli del sito,
 * vector store remoto (in base al provider configurato).
 */
final class SourceTester {

	/** @var callable(string, array<string, mixed>): mixed */
	private $http;

	public function __construct( ?callable $http = null ) {
		$this->http = $http ?? static fn( string $url, array $args = array() ) => wp_remote_get( $url, $args + array( 'timeout' => 20 ) );
	}

	/**
	 * @param array<string, mixed> $source Voce fonte (anche non ancora registrata).
	 * @return array{ok: bool, message: string}
	 */
	public function test( array $source ): array {
		if ( ! empty( $source['site_posts'] ) ) {
			$count = (int) ( wp_count_posts( 'post' )->publish ?? 0 );
			return $count > 0
				? self::ok( sprintf( '%d articoli pubblicati pronti per l\'ingestione.', $count ) )
				: self::fail( 'Nessun articolo pubblicato sul sito.' );
		}

		if ( ! empty( $source['attachment_id'] ) ) {
			$path = get_attached_file( (int) $source['attachment_id'] );
			if ( ! is_string( $path ) || ! file_exists( $path ) ) {
				return self::fail( 'Media #' . (int) $source['attachment_id'] . ': file non trovato.' );
			}
			return self::ok( basename( $path ) . ' (' . size_format( (int) filesize( $path ) ) . ') raggiungibile.' );
		}

		if ( ! empty( $source['file_path'] ) ) {
			$path = (string) $source['file_path'];
			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return self::fail( "File {$path} inesistente o non leggibile dal server." );
			}
			return self::ok( basename( $path ) . ' (' . size_format( (int) filesize( $path ) ) . ') leggibile.' );
		}

		if ( ! empty( $source['url'] ) ) {
			$url = (string) $source['url'];
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return self::fail( 'URL non valido: ammessi solo http(s).' );
			}
			$response = ( $this->http )( $url );
			if ( is_wp_error( $response ) ) {
				return self::fail( 'Irraggiungibile: ' . $response->get_error_message() );
			}
			$code = (int) ( $response['response']['code'] ?? 0 );
			$size = strlen( (string) ( $response['body'] ?? '' ) );
			return $code >= 200 && $code < 400
				? self::ok( "HTTP {$code}, " . size_format( $size ) . ' di contenuto.' )
				: self::fail( "HTTP {$code} dalla fonte." );
		}

		return self::fail( 'Fonte senza URL, media o path: nulla da testare.' );
	}

	/**
	 * Verifica un vector store remoto in base al provider configurato.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function test_vector_store( string $vector_store_id, string $provider ): array {
		if ( '' === trim( $vector_store_id ) ) {
			return self::fail( 'ID vector store vuoto.' );
		}

		if ( 'mock' === $provider ) {
			return self::ok( 'Provider mock: ID accettato senza verifica.' );
		}

		if ( 'openai' === $provider ) {
			$key = ApiKeys::openai();
			if ( null === $key ) {
				return self::fail( 'Chiave OpenAI assente in wp-config.php.' );
			}
			$response = ( $this->http )(
				'https://api.openai.com/v1/vector_stores/' . rawurlencode( trim( $vector_store_id ) ),
				array( 'headers' => array( 'authorization' => 'Bearer ' . $key ) )
			);
			if ( is_wp_error( $response ) ) {
				return self::fail( 'OpenAI irraggiungibile: ' . $response->get_error_message() );
			}
			$code = (int) ( $response['response']['code'] ?? 0 );
			$data = json_decode( (string) ( $response['body'] ?? '' ), true );
			if ( 200 === $code && is_array( $data ) ) {
				return self::ok( sprintf( 'Vector store «%s»: %d file, stato %s.', (string) ( $data['name'] ?? $vector_store_id ), (int) ( $data['file_counts']['total'] ?? 0 ), (string) ( $data['status'] ?? '?' ) ) );
			}
			return self::fail( 'OpenAI HTTP ' . $code . ': ' . (string) ( $data['error']['message'] ?? 'vector store non trovato' ) );
		}

		return self::fail( sprintf( 'Il provider %s non espone vector store remoti: usare le fonti locali (URL, media, articoli).', $provider ) );
	}

	/** @return array{ok: bool, message: string} */
	private static function ok( string $message ): array {
		return array( 'ok' => true, 'message' => $message );
	}

	/** @return array{ok: bool, message: string} */
	private static function fail( string $message ): array {
		return array( 'ok' => false, 'message' => $message );
	}
}

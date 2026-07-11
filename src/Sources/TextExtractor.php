<?php
declare(strict_types=1);

namespace Ghostwriter\Sources;

use Smalot\PdfParser\Parser;

/**
 * Estrazione testo dalle fonti per l'ingestione RAG.
 * Supporto v1: PDF (smalot/pdfparser — BookCreator non aveva alcun parser),
 * testo semplice/markdown, URL http(s) con strip dei tag.
 */
final class TextExtractor {

	/** @var callable(string): mixed */
	private $http_get;

	/**
	 * @param callable|null $http_get Default: wp_remote_get (iniettabile per i test).
	 */
	public function __construct( ?callable $http_get = null ) {
		$this->http_get = $http_get ?? static fn( string $url ) => wp_remote_get( $url, array( 'timeout' => 60 ) );
	}

	/**
	 * Estrae il testo di una fonte.
	 *
	 * @param array<string, mixed> $source Voce del registry (type, url, file_path...).
	 * @return string Testo normalizzato.
	 * @throws \RuntimeException Se il tipo non è estraibile o il file manca.
	 */
	public function extract( array $source ): string {
		$type = (string) ( $source['type'] ?? '' );
		$path = (string) ( $source['file_path'] ?? '' );
		$url  = (string) ( $source['url'] ?? '' );

		$text = match ( true ) {
			'pdf' === $type && '' !== $path      => $this->from_pdf( $path ),
			'url' === $type && '' !== $url       => $this->from_url( $url ),
			'' !== $path && self::is_text( $path ) => $this->from_text_file( $path ),
			default => throw new \RuntimeException( "Fonte non estraibile: type={$type}." ),
		};

		return self::normalize( $text );
	}

	public function from_pdf( string $path ): string {
		if ( ! file_exists( $path ) ) {
			throw new \RuntimeException( "File PDF assente: {$path}" );
		}
		$document = ( new Parser() )->parseFile( $path );
		return $document->getText();
	}

	public function from_text_file( string $path ): string {
		if ( ! file_exists( $path ) ) {
			throw new \RuntimeException( "File assente: {$path}" );
		}
		return (string) file_get_contents( $path );
	}

	public function from_url( string $url ): string {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			throw new \RuntimeException( "URL non ammesso: {$url}" );
		}

		$response = ( $this->http_get )( $url );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Fetch fonte fallito: ' . $response->get_error_message() );
		}
		$body = is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
		if ( '' === $body ) {
			throw new \RuntimeException( "Fonte {$url} senza contenuto." );
		}

		// HTML → testo: via script/style, poi tag.
		$body = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $body );
		$body = (string) preg_replace( '/<[^>]+>/', ' ', $body );

		return html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function is_text( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'txt', 'md', 'markdown', 'csv' ), true );
	}

	/**
	 * Normalizzazione: spazi compattati, righe vuote multiple ridotte.
	 */
	public static function normalize( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}
}

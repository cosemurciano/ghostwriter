<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Client Anthropic Messages API implementato su wp_remote_post (niente SDK:
 * meno superficie, streaming non necessario in background).
 *
 * Structured output via tool-use forzato: un unico tool "emit" il cui
 * input_schema è lo schema della fase; la risposta è l'input del tool.
 * I blocchi system stabili portano cache_control per il prompt caching.
 */
final class AnthropicProvider implements ProviderInterface {

	private const VERSION = '2023-06-01';

	/** @var callable(string, array<string, mixed>): mixed */
	private $transport;

	/**
	 * @param string        $model      Modello (dalla config di progetto).
	 * @param string        $api_key    Chiave API (da wp-config.php).
	 * @param ContextComposer $composer Composizione contesto per fase.
	 * @param PhaseSchemas  $schemas    Schemi di output per fase.
	 * @param callable|null $transport  Default: wp_remote_post.
	 * @param string        $base_url   Endpoint API.
	 * @param int           $max_tokens Limite output.
	 */
	public function __construct(
		private string $model,
		#[\SensitiveParameter] private string $api_key,
		private ContextComposer $composer,
		private PhaseSchemas $schemas,
		?callable $transport = null,
		private string $base_url = 'https://api.anthropic.com',
		private int $max_tokens = 16384
	) {
		$this->transport = $transport ?? static fn( string $url, array $args ) => wp_remote_post( $url, $args );
	}

	public function complete( AiRequest $request ): AiResult {
		$prompt = $this->composer->compose( $request );
		$schema = $this->schemas->for_phase( $request->phase );

		// I blocchi system stabili (istruzioni + skills) sono cache-friendly:
		// il breakpoint di cache va sull'ultimo blocco.
		$system = array();
		foreach ( array_values( $prompt['system'] ) as $i => $text ) {
			$block = array(
				'type' => 'text',
				'text' => $text,
			);
			if ( count( $prompt['system'] ) - 1 === $i ) {
				$block['cache_control'] = array( 'type' => 'ephemeral' );
			}
			$system[] = $block;
		}

		$body = array(
			'model'       => $this->model,
			'max_tokens'  => $this->max_tokens,
			'system'      => $system,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $prompt['user'],
				),
			),
			'tools'       => array(
				array(
					'name'         => 'emit',
					'description'  => 'Emette il risultato strutturato della fase, conforme allo schema.',
					'input_schema' => $schema,
				),
			),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'emit',
			),
		);

		$response = ( $this->transport )(
			rtrim( $this->base_url, '/' ) . '/v1/messages',
			array(
				'timeout' => 300,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = self::parse_http_response( $response );

		$content = null;
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'tool_use' === ( $block['type'] ?? '' ) && 'emit' === ( $block['name'] ?? '' ) ) {
				$content = (array) ( $block['input'] ?? array() );
				break;
			}
		}
		if ( null === $content ) {
			throw new AiProviderException( 'Risposta Anthropic senza tool_use "emit".', null, 'anthropic' );
		}

		return new AiResult(
			content: $content,
			input_tokens: (int) ( $data['usage']['input_tokens'] ?? 0 ),
			output_tokens: (int) ( $data['usage']['output_tokens'] ?? 0 ),
			model: (string) ( $data['model'] ?? $this->model )
		);
	}

	public function generate_image( ImageRequest $request ): ImageResult {
		throw new AiProviderException(
			'Anthropic non offre generazione immagini: impostare ai.image_provider (es. openai) nella config di progetto.',
			null,
			'anthropic'
		);
	}

	/**
	 * @param mixed $response Ritorno del transport (formato WP HTTP API).
	 * @return array<string, mixed> Body decodificato.
	 */
	private static function parse_http_response( mixed $response ): array {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			throw new AiProviderException( 'Errore di rete verso Anthropic: ' . $response->get_error_message(), null, 'anthropic' );
		}
		if ( ! is_array( $response ) ) {
			throw new AiProviderException( 'Risposta HTTP inattesa dal transport.', null, 'anthropic' );
		}

		$code = (int) ( $response['response']['code'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) ? (string) ( $data['error']['message'] ?? $body ) : $body;
			throw new AiProviderException( "Anthropic HTTP {$code}: " . substr( $detail, 0, 500 ), $code, 'anthropic' );
		}
		if ( ! is_array( $data ) ) {
			throw new AiProviderException( 'Body Anthropic non parsabile come JSON.', $code, 'anthropic' );
		}

		return $data;
	}
}

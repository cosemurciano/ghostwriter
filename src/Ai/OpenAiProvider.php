<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

/**
 * Client OpenAI Chat Completions su wp_remote_post, con structured outputs
 * nativi (response_format json_schema).
 */
final class OpenAiProvider implements ProviderInterface {

	/** @var callable(string, array<string, mixed>): mixed */
	private $transport;

	public function __construct(
		private string $model,
		#[\SensitiveParameter] private string $api_key,
		private ContextComposer $composer,
		private PhaseSchemas $schemas,
		?callable $transport = null,
		private string $base_url = 'https://api.openai.com',
		private int $max_tokens = 16384
	) {
		$this->transport = $transport ?? static fn( string $url, array $args ) => wp_remote_post( $url, $args );
	}

	public function complete( AiRequest $request ): AiResult {
		$prompt = $this->composer->compose( $request );
		$schema = $this->schemas->for_phase( $request->phase );

		$body = array(
			'model'                 => $this->model,
			'max_completion_tokens' => $this->max_tokens,
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => implode( "\n\n---\n\n", $prompt['system'] ),
				),
				array(
					'role'    => 'user',
					'content' => $prompt['user'],
				),
			),
			'response_format'       => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'gw_' . $request->phase,
					// strict: false — gli schemi del contratto usano
					// costrutti (if/then, $ref) fuori dal sottoinsieme strict.
					'strict' => false,
					'schema' => $schema,
				),
			),
		);

		$response = ( $this->transport )(
			rtrim( $this->base_url, '/' ) . '/v1/chat/completions',
			array(
				'timeout' => 300,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = self::parse_http_response( $response );

		$text = $data['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $text ) || '' === $text ) {
			throw new AiProviderException( 'Risposta OpenAI senza contenuto.', null, 'openai' );
		}

		$content = json_decode( $text, true );
		if ( ! is_array( $content ) ) {
			throw new AiProviderException( 'Contenuto OpenAI non parsabile come JSON.', null, 'openai' );
		}

		return new AiResult(
			content: $content,
			input_tokens: (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
			output_tokens: (int) ( $data['usage']['completion_tokens'] ?? 0 ),
			model: (string) ( $data['model'] ?? $this->model )
		);
	}

	/**
	 * @param mixed $response Ritorno del transport (formato WP HTTP API).
	 * @return array<string, mixed> Body decodificato.
	 */
	private static function parse_http_response( mixed $response ): array {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			throw new AiProviderException( 'Errore di rete verso OpenAI: ' . $response->get_error_message(), null, 'openai' );
		}
		if ( ! is_array( $response ) ) {
			throw new AiProviderException( 'Risposta HTTP inattesa dal transport.', null, 'openai' );
		}

		$code = (int) ( $response['response']['code'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) ? (string) ( $data['error']['message'] ?? $body ) : $body;
			throw new AiProviderException( "OpenAI HTTP {$code}: " . substr( $detail, 0, 500 ), $code, 'openai' );
		}
		if ( ! is_array( $data ) ) {
			throw new AiProviderException( 'Body OpenAI non parsabile come JSON.', $code, 'openai' );
		}

		return $data;
	}
}

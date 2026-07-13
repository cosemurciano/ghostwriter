<?php
declare(strict_types=1);

namespace Ghostwriter\Ai;

use Ghostwriter\Repository\ProjectRepository;

/**
 * Sceglie il provider per progetto dalla config (ai.provider, ai.model):
 * i job dipendono da un solo ProviderInterface e questo router delega al
 * client giusto. La chiave API arriva SEMPRE da wp-config.php (ApiKeys):
 * se manca, errore esplicito — mai fallback silenzioso al mock.
 *
 * Il filtro gw_ai_provider permette di sostituire il provider risolto
 * (sviluppo, test di integrazione).
 */
final class ProviderRouter implements ProviderInterface {

	/** @var array<string, ProviderInterface> Cache per provider+modello. */
	private array $resolved = array();

	public function __construct(
		private ProjectRepository $projects,
		private ContextComposer $composer,
		private PhaseSchemas $schemas
	) {
	}

	public function complete( AiRequest $request ): AiResult {
		return $this->resolve( $request->project_id )->complete( $request );
	}

	public function generate_image( ImageRequest $request ): ImageResult {
		return $this->resolve_image( $request->project_id )->generate_image( $request );
	}

	/**
	 * Provider per le immagini: ai.image_provider/ai.image_model se dichiarati,
	 * altrimenti il provider testuale (che può non supportare le immagini:
	 * errore esplicito, mai degrado silenzioso). Il modello NON ricade mai su
	 * ai.model: un modello testuale (es. gpt-5) non esiste sull'endpoint
	 * immagini; senza image_model si usa il default di catalogo del provider.
	 */
	public function resolve_image( int $project_id ): ProviderInterface {
		$config   = $this->projects->get_config( $project_id );
		$provider = (string) ( $config['ai']['image_provider'] ?? '' );
		if ( '' === $provider ) {
			$provider = (string) ( $config['ai']['provider'] ?? 'mock' );
		}
		$model = (string) ( $config['ai']['image_model'] ?? '' );
		if ( '' === $model ) {
			$model = ModelCatalog::default_for( $provider, 'image' );
		}

		$override = apply_filters( 'gw_ai_image_provider', null, $provider, $model, $project_id );
		if ( $override instanceof ProviderInterface ) {
			return $override;
		}

		$key = 'img|' . $provider . '|' . $model;
		if ( ! isset( $this->resolved[ $key ] ) ) {
			$this->resolved[ $key ] = $this->build( $provider, $model );
		}
		return $this->resolved[ $key ];
	}

	public function resolve( int $project_id ): ProviderInterface {
		$config   = $this->projects->get_config( $project_id );
		$provider = (string) ( $config['ai']['provider'] ?? 'mock' );
		$model    = (string) ( $config['ai']['model'] ?? '' );

		/**
		 * Override completo del provider (usato in sviluppo/test).
		 *
		 * @param ProviderInterface|null $override Istanza sostitutiva o null.
		 * @param string                 $provider Provider dalla config.
		 * @param string                 $model    Modello dalla config.
		 * @param int                    $project_id Progetto.
		 */
		$override = apply_filters( 'gw_ai_provider', null, $provider, $model, $project_id );
		if ( $override instanceof ProviderInterface ) {
			return $override;
		}

		$key = $provider . '|' . $model;
		if ( isset( $this->resolved[ $key ] ) ) {
			return $this->resolved[ $key ];
		}

		$this->resolved[ $key ] = $this->build( $provider, $model );
		return $this->resolved[ $key ];
	}

	private function build( string $provider, string $model ): ProviderInterface {
		if ( 'mock' === $provider ) {
			return new MockProvider();
		}

		$api_key = ApiKeys::for_provider( $provider );
		if ( null === $api_key ) {
			throw new AiProviderException(
				sprintf(
					'Chiave API %1$s assente: definire la costante GHOSTWRITER_%2$s_API_KEY in wp-config.php.',
					$provider,
					strtoupper( $provider )
				),
				null,
				$provider
			);
		}

		return match ( $provider ) {
			'anthropic' => new AnthropicProvider( $model, $api_key, $this->composer, $this->schemas ),
			'openai'    => new OpenAiProvider( $model, $api_key, $this->composer, $this->schemas ),
			default     => throw new AiProviderException( "Provider AI sconosciuto: {$provider}.", null, $provider ),
		};
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Queue\Jobs;

use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\ProviderInterface;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Schema\SchemaValidator;
use Ghostwriter\Translation\DerivedProjectFactory;
use Ghostwriter\Translation\GlossaryService;

/**
 * Traduce un capitolo derivato blocco per blocco: il mapping col sorgente
 * sono gli id dei blocchi, che NON cambiano. Il glossario approvato viaggia
 * nel contesto di ogni chiamata. Un solo retry con l'errore (validazione o
 * mapping) nel contesto, poi failed.
 */
final class TranslateChapterJob implements JobInterface {

	public function __construct(
		private ProviderInterface $provider,
		private ProjectRepository $projects,
		private ChapterRepository $chapters,
		private DerivedProjectFactory $factory,
		private GlossaryService $glossary,
		private StateMachine $states,
		private SchemaValidator $validator,
		private UsageMeter $usage,
		private LogRepository $log
	) {
	}

	public static function name(): string {
		return 'translate_chapter';
	}

	public function handle( array $args ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		$project_id = $this->chapters->get_project_id( $chapter_id );

		// Idempotenza: capitolo già tradotto (o oltre).
		$state = $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER );
		if ( ! in_array( $state, array( 'planned', 'drafting' ), true ) ) {
			return;
		}

		$source_chapter_id = $this->factory->source_chapter_id( $chapter_id );
		$source_content    = $source_chapter_id > 0 ? $this->chapters->get_content( $source_chapter_id ) : null;
		if ( null === $source_content ) {
			throw new \RuntimeException( "Capitolo derivato {$chapter_id} senza contenuto sorgente." );
		}

		if ( 'planned' === $state ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'draft_started', array( 'job' => self::name() ) );
		}

		$config        = $this->projects->get_config( $project_id );
		$source_config = $this->projects->get_config( (int) $config['derived_from'] );

		$context = array(
			'source_content'  => $source_content,
			'glossary'        => $this->glossary->for_context( $project_id ),
			'source_language' => (string) ( $source_config['language'] ?? '' ),
			'target_language' => (string) ( $config['language'] ?? '' ),
			'dossier'         => $this->projects->get_dossier( $project_id ),
		);

		$translated = $this->complete_validated( $project_id, $chapter_id, $config, $context, $source_content );

		$this->chapters->save_content( $chapter_id, $translated );

		// Il titolo del post segue il titolo tradotto (alimenta TOC ed export).
		$title = (string) ( $translated['meta']['title'] ?? '' );
		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $chapter_id,
					'post_title' => $title,
				)
			);
		}

		// La traduzione non ripassa dalla pipeline di stesura: il capitolo
		// derivato va dritto a complete (la revisione è a livello progetto).
		$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'draft_ready', array( 'job' => self::name() ) );
		$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'completed', array( 'job' => self::name() ) );
	}

	/**
	 * Chiamata AI + validazione schema + verifica mapping id, con un retry.
	 *
	 * @param array<string, mixed> $config         Config derivata.
	 * @param array<string, mixed> $context        Contesto di fase.
	 * @param array<string, mixed> $source_content Formato intermedio sorgente.
	 * @return array<string, mixed> Contenuto tradotto valido.
	 */
	private function complete_validated( int $project_id, int $chapter_id, array $config, array $context, array $source_content ): array {
		$attempts = 0;
		do {
			$result = $this->provider->complete(
				new AiRequest( AiRequest::PHASE_TRANSLATION, $context, $project_id, $chapter_id )
			);

			$this->usage->record(
				$project_id,
				self::name(),
				(string) ( $config['ai']['provider'] ?? 'mock' ),
				$result->model,
				$result->input_tokens,
				$result->output_tokens
			);

			$translated               = ChapterRepository::normalize_content( $result->content );
			$translated['chapter_id'] = $chapter_id;

			$errors = $this->validator->get_validation_errors( $translated, SchemaValidator::CHAPTER_CONTENT );
			if ( empty( $errors ) && ! self::block_mapping_intact( $source_content, $translated ) ) {
				$errors = array(
					array(
						'property' => '/blocks',
						'message'  => 'Il mapping dei blocchi è rotto: gli id (e i type) devono coincidere 1:1 col sorgente, nello stesso ordine.',
					),
				);
			}

			if ( empty( $errors ) ) {
				return $translated;
			}

			$context['validation_errors'] = $errors;
			++$attempts;
		} while ( $attempts < 2 );

		throw new \RuntimeException( 'Traduzione non conforme dopo il retry: ' . wp_json_encode( $errors ) );
	}

	/**
	 * Verifica che la traduzione conservi il mapping: stessi id e type,
	 * stesso ordine, anche nei blocchi annidati.
	 *
	 * @param array<string, mixed> $source     Contenuto sorgente.
	 * @param array<string, mixed> $translated Contenuto tradotto.
	 */
	public static function block_mapping_intact( array $source, array $translated ): bool {
		return self::signature( (array) ( $source['blocks'] ?? array() ) )
			=== self::signature( (array) ( $translated['blocks'] ?? array() ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocchi.
	 * @return array<int, string> Coppie id|type in ordine (ricorsivo).
	 */
	private static function signature( array $blocks ): array {
		$signature = array();
		foreach ( $blocks as $block ) {
			$block = (array) $block;
			// Props vuote = stdClass (normalizzazione schema): cast obbligato.
			$props       = (array) ( $block['props'] ?? array() );
			$signature[] = ( $block['id'] ?? '' ) . '|' . ( $block['type'] ?? '' );
			if ( ! empty( $props['blocks'] ) && is_array( $props['blocks'] ) ) {
				$signature = array_merge( $signature, self::signature( $props['blocks'] ) );
			}
		}
		return $signature;
	}

	public function on_failure( array $args, \Throwable $e ): void {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( StateMachine::can( StateMachine::TYPE_CHAPTER, $this->states->state_of( $chapter_id, StateMachine::TYPE_CHAPTER ), 'failed' ) ) {
			$this->states->transition( $chapter_id, StateMachine::TYPE_CHAPTER, 'failed', array( 'job' => self::name(), 'error' => $e->getMessage() ) );
		}
	}
}

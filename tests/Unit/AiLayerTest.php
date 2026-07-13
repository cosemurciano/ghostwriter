<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Ai\AiProviderException;
use Ghostwriter\Ai\AiRequest;
use Ghostwriter\Ai\AnthropicProvider;
use Ghostwriter\Ai\ApiKeys;
use Ghostwriter\Ai\ContextComposer;
use Ghostwriter\Ai\NullRagService;
use Ghostwriter\Ai\OpenAiProvider;
use Ghostwriter\Ai\PhaseSchemas;
use Ghostwriter\Ai\ProviderRouter;
use Ghostwriter\Ai\SkillsManager;
use Ghostwriter\Repository\ProjectRepository;
use PHPUnit\Framework\TestCase;

final class AiLayerTest extends TestCase {

	private string $skills_dir;
	private ContextComposer $composer;
	private PhaseSchemas $schemas;

	/** @var array<string, mixed> */
	private array $config;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->skills_dir = sys_get_temp_dir() . '/gw-skills-' . bin2hex( random_bytes( 4 ) );
		$skills           = new SkillsManager( $this->skills_dir );
		$skills->put( 'stile-divulgazione', 3, "# Stile divulgativo\nFrasi brevi, esempi concreti." );

		$this->config = array(
			'schema_version'     => '1.0',
			'language'           => 'it',
			'brief'              => array( 'thesis' => 'Le masserie del Salento' ),
			'format'             => array( 'trim_width_mm' => 150, 'trim_height_mm' => 230 ),
			'structural_profile' => array( 'allowed_blocks' => array( 'paragrafo', 'heading' ) ),
			'skills'             => array(
				array( 'skill_id' => 'stile-divulgazione', 'version' => 3, 'phases' => array( 'draft' ) ),
			),
			'ai'                 => array( 'provider' => 'anthropic', 'model' => 'claude-opus-4-8' ),
		);

		$projects = new class( $this->config ) extends ProjectRepository {
			public function __construct( private array $config ) { // phpcs:ignore
			}
			public function get_config( int $project_id ): array {
				return $this->config;
			}
		};

		$this->composer = new ContextComposer( $skills, $projects, new NullRagService() );
		$this->schemas  = new PhaseSchemas( dirname( __DIR__, 2 ) . '/schemas' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- ContextComposer -------------------------------------------------

	public function test_composer_mounts_skills_in_system_blocks_for_draft(): void {
		$prompt = $this->composer->compose(
			new AiRequest( AiRequest::PHASE_DRAFT, array( 'chapter_brief' => array( 'title' => 'Cap 1' ) ), 7, 12 )
		);

		// Istruzioni di fase in testa (parte stabile), poi profilo, poi skill.
		self::assertStringContainsString( 'FASE STESURA', $prompt['system'][0] );
		self::assertStringContainsString( 'JSON conforme allo schema', $prompt['system'][0] );
		self::assertStringContainsString( 'Profilo strutturale', $prompt['system'][1] );
		self::assertStringContainsString( 'Stile divulgativo', implode( "\n", $prompt['system'] ) );
		self::assertStringContainsString( 'BRIEF DEL CAPITOLO', $prompt['user'] );
	}

	public function test_composer_rewrite_reuses_draft_skills_and_carries_feedback(): void {
		$prompt = $this->composer->compose(
			new AiRequest(
				AiRequest::PHASE_REWRITE,
				array(
					'block'    => array( 'id' => 'b1', 'type' => 'paragrafo', 'props' => array( 'text' => 'x' ) ),
					'feedback' => 'troppo tecnico',
				),
				7,
				12
			)
		);

		self::assertStringContainsString( 'Stile divulgativo', implode( "\n", $prompt['system'] ) );
		self::assertStringContainsString( 'FEEDBACK DELL\'UTENTE', $prompt['user'] );
		self::assertStringContainsString( 'troppo tecnico', $prompt['user'] );
	}

	public function test_composer_fails_loudly_on_missing_skill(): void {
		$this->config['skills'][0]['version'] = 99; // Versione inesistente.
		$projects                             = new class( $this->config ) extends ProjectRepository {
			public function __construct( private array $config ) { // phpcs:ignore
			}
			public function get_config( int $project_id ): array {
				return $this->config;
			}
		};
		$composer = new ContextComposer( new SkillsManager( $this->skills_dir ), $projects, new NullRagService() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'stile-divulgazione@99' );
		$composer->compose( new AiRequest( AiRequest::PHASE_DRAFT, array(), 7, 12 ) );
	}

	// --- SkillsManager: import da zip --------------------------------------

	public function test_import_zip_reads_frontmatter_and_installs_bundle(): void {
		$zip_path = $this->make_skill_zip(
			'stile-manuale-pratico',
			<<<'MD'
			---
			name: stile-manuale-pratico
			description: >-
			  Stile per manuali pratici: frasi brevi,
			  esempi concreti dopo ogni concetto.
			license: Proprietary.
			metadata:
			  version: 1.0.0
			  x-ghostwriter:
			    default_phases: [draft, review, rewrite]
			---
			# Stile manuale pratico
			Corpo della skill.
			MD,
			array( 'examples/apertura-capitolo.md' => '# Esempio' )
		);

		$skills = new SkillsManager( $this->skills_dir );
		$result = $skills->import_zip( $zip_path );

		self::assertSame( array( 'skill_id' => 'stile-manuale-pratico', 'version' => '1.0.0' ), $result );
		self::assertStringContainsString( 'Corpo della skill.', (string) $skills->get_content( 'stile-manuale-pratico', '1.0.0' ) );
		self::assertFileExists( $this->skills_dir . '/stile-manuale-pratico/1.0.0/examples/apertura-capitolo.md' );

		$meta = $skills->describe( 'stile-manuale-pratico', '1.0.0' );
		self::assertSame( 'stile-manuale-pratico', $meta['name'] );
		self::assertSame( array( 'draft', 'review', 'rewrite' ), $meta['default_phases'] );
		self::assertStringContainsString( 'frasi brevi', $meta['description'] );

		$skills->delete( 'stile-manuale-pratico', '1.0.0' );
		self::assertNull( $skills->get_content( 'stile-manuale-pratico', '1.0.0' ) );
	}

	public function test_import_zip_rejects_php_payloads(): void {
		$zip_path = $this->make_skill_zip( 'malizia', "---\nname: malizia\n---\nCorpo", array( 'shell.php' => '<?php evil();' ) );

		$this->expectException( \RuntimeException::class );
		( new SkillsManager( $this->skills_dir ) )->import_zip( $zip_path );
	}

	/** @param array<string, string> $extra_files */
	private function make_skill_zip( string $folder, string $skill_md, array $extra_files = array() ): string {
		$zip_path = sys_get_temp_dir() . '/gw-zip-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE );
		$zip->addFromString( $folder . '/SKILL.md', $skill_md );
		foreach ( $extra_files as $name => $content ) {
			$zip->addFromString( $folder . '/' . $name, $content );
		}
		$zip->close();
		return $zip_path;
	}

	// --- PhaseSchemas -----------------------------------------------------

	public function test_block_schema_is_self_contained(): void {
		$schema = $this->schemas->for_phase( AiRequest::PHASE_REWRITE );

		self::assertSame( '#/definitions/block', $schema['$ref'] );
		self::assertArrayHasKey( 'block', $schema['definitions'] );
		self::assertArrayHasKey( 'richText', $schema['definitions'] );
	}

	public function test_draft_schema_is_full_chapter_contract(): void {
		$schema = $this->schemas->for_phase( AiRequest::PHASE_DRAFT );

		self::assertContains( 'blocks', $schema['required'] );
		self::assertArrayNotHasKey( '$id', $schema );
	}

	// --- Provider Anthropic ----------------------------------------------

	public function test_anthropic_request_shape_and_response_parsing(): void {
		$captured = array();

		$transport = function ( string $url, array $args ) use ( &$captured ): array {
			$captured = array( 'url' => $url, 'args' => $args );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'model'   => 'claude-opus-4-8',
						'content' => array(
							array(
								'type'  => 'tool_use',
								'name'  => 'emit',
								'input' => array( 'chapters' => array( array( 'title' => 'T', 'brief' => 'B' ) ) ),
							),
						),
						'usage'   => array( 'input_tokens' => 900, 'output_tokens' => 120 ),
					)
				),
			);
		};

		$provider = new AnthropicProvider( 'claude-opus-4-8', 'sk-ant-test', $this->composer, $this->schemas, $transport );
		$result   = $provider->complete( new AiRequest( AiRequest::PHASE_OUTLINE, array( 'brief' => array() ), 7 ) );

		self::assertSame( 'https://api.anthropic.com/v1/messages', $captured['url'] );
		self::assertSame( 'sk-ant-test', $captured['args']['headers']['x-api-key'] );

		$body = json_decode( $captured['args']['body'], true );
		self::assertSame( 'claude-opus-4-8', $body['model'] );
		self::assertSame( 'emit', $body['tool_choice']['name'] );
		self::assertSame( 'tool', $body['tool_choice']['type'] );
		// Prompt caching sull'ultimo blocco system.
		$last_system = end( $body['system'] );
		self::assertSame( array( 'type' => 'ephemeral' ), $last_system['cache_control'] );
		// Lo schema outline viaggia come input_schema del tool.
		self::assertSame( 'object', $body['tools'][0]['input_schema']['type'] );

		self::assertSame( 'T', $result->content['chapters'][0]['title'] );
		self::assertSame( 900, $result->input_tokens );
		self::assertSame( 120, $result->output_tokens );
	}

	public function test_anthropic_http_error_raises_with_status(): void {
		$transport = static fn(): array => array(
			'response' => array( 'code' => 429 ),
			'body'     => json_encode( array( 'error' => array( 'message' => 'rate limited' ) ) ),
		);

		$provider = new AnthropicProvider( 'claude-opus-4-8', 'sk-ant-test', $this->composer, $this->schemas, $transport );

		try {
			$provider->complete( new AiRequest( AiRequest::PHASE_OUTLINE, array(), 7 ) );
			self::fail( 'Attesa AiProviderException' );
		} catch ( AiProviderException $e ) {
			self::assertSame( 429, $e->status_code );
			self::assertStringContainsString( 'rate limited', $e->getMessage() );
			self::assertStringNotContainsString( 'sk-ant-test', $e->getMessage() );
		}
	}

	// --- Provider OpenAI ---------------------------------------------------

	public function test_openai_request_shape_and_response_parsing(): void {
		$captured = array();

		$transport = function ( string $url, array $args ) use ( &$captured ): array {
			$captured = array( 'url' => $url, 'args' => $args );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'model'   => 'gpt-5',
						'choices' => array(
							array( 'message' => array( 'content' => json_encode( array( 'synopsis' => 'Riassunto.' ) ) ) ),
						),
						'usage'   => array( 'prompt_tokens' => 500, 'completion_tokens' => 60 ),
					)
				),
			);
		};

		$provider = new OpenAiProvider( 'gpt-5', 'sk-test', $this->composer, $this->schemas, $transport );
		$result   = $provider->complete( new AiRequest( AiRequest::PHASE_SYNOPSIS, array( 'chapter_title' => 'Cap' ), 7, 12 ) );

		self::assertSame( 'https://api.openai.com/v1/chat/completions', $captured['url'] );
		self::assertSame( 'Bearer sk-test', $captured['args']['headers']['authorization'] );

		$body = json_decode( $captured['args']['body'], true );
		self::assertSame( 'json_schema', $body['response_format']['type'] );
		self::assertSame( 'gw_synopsis', $body['response_format']['json_schema']['name'] );

		self::assertSame( 'Riassunto.', $result->content['synopsis'] );
		self::assertSame( 500, $result->input_tokens );
	}

	// --- ApiKeys -----------------------------------------------------------

	public function test_api_keys_come_from_wp_config_constants(): void {
		// Le costanti di questo test restano definite per il processo: nomi dedicati.
		self::assertNull( ApiKeys::for_provider( 'sconosciuto' ) );

		if ( ! defined( 'GHOSTWRITER_ANTHROPIC_API_KEY' ) ) {
			define( 'GHOSTWRITER_ANTHROPIC_API_KEY', 'sk-ant-da-wpconfig' );
		}
		self::assertSame( 'sk-ant-da-wpconfig', ApiKeys::anthropic() );
		self::assertSame( 'sk-ant…nfig', ApiKeys::mask( 'sk-ant-da-wpconfig' ) );
	}

	// --- ProviderRouter ----------------------------------------------------

	public function test_image_router_never_falls_back_to_text_model(): void {
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( '__' )->returnArg();
		if ( ! defined( 'GHOSTWRITER_OPENAI_API_KEY' ) ) {
			define( 'GHOSTWRITER_OPENAI_API_KEY', 'sk-openai-test' );
		}

		// Provider immagini dichiarato ma image_model assente: il modello NON
		// deve ricadere su ai.model (gpt-5 non esiste sull'endpoint immagini),
		// bensì sul default di catalogo del provider.
		$config                          = $this->config;
		$config['ai']['provider']        = 'openai';
		$config['ai']['model']           = 'gpt-5';
		$config['ai']['image_provider']  = 'openai';

		$projects = new class( $config ) extends ProjectRepository {
			public function __construct( private array $config ) { // phpcs:ignore
			}
			public function get_config( int $project_id ): array {
				return $this->config;
			}
		};

		$router   = new ProviderRouter( $projects, $this->composer, $this->schemas );
		$provider = $router->resolve_image( 1 );

		self::assertInstanceOf( OpenAiProvider::class, $provider );
		$model = new \ReflectionProperty( OpenAiProvider::class, 'model' );
		self::assertSame( 'gpt-image-1', $model->getValue( $provider ) );
	}
}

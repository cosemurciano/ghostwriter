<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Queue\Dispatcher;
use Ghostwriter\Queue\Jobs\DraftChapterJob;
use Ghostwriter\Queue\Jobs\MaterializeChaptersJob;
use Ghostwriter\Queue\Jobs\ReviewChapterJob;
use Ghostwriter\Queue\Jobs\RewriteBlockJob;
use Ghostwriter\Queue\Jobs\SynopsisJob;
use Ghostwriter\Queue\PipelineRouter;
use Ghostwriter\Repository\ChapterRepository;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Il router accoda il job giusto a ogni cambio di stato: meta WordPress
 * simulati in memoria, scheduler spia, nessun database.
 */
final class PipelineRouterTest extends TestCase {

	public const PROJECT = 7;
	public const CH1     = 101;
	public const CH2     = 102;

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array<int, array{hook: string, args: array}> */
	private array $enqueued = array();

	private PipelineRouter $router;
	private StateMachine $states;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->meta     = array();
		$this->enqueued = array();

		// Meta WordPress in memoria.
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key, bool $single = false ) {
			return $this->meta[ $id ][ $key ] ?? '';
		} );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ): bool {
			$this->meta[ $id ][ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( int $id, string $key ): bool {
			unset( $this->meta[ $id ][ $key ] );
			return true;
		} );

		$null_log = new class() extends LogRepository {
			public function log( int $project_id, ?int $chapter_id, string $level, string $event, array $context = array() ): void {
			}
		};

		$this->states = new StateMachine( $null_log );

		$projects = new class() extends ProjectRepository {
			/** @var int[] */
			public array $chapter_ids = array();

			public function __construct() { // phpcs:ignore
			}

			public function get_chapter_ids( int $project_id ): array {
				return $this->chapter_ids;
			}
		};
		$projects->chapter_ids = array( self::CH1, self::CH2 );

		$chapters = new class() extends ChapterRepository {
			/** @var array<string, array<string, mixed>> */
			public array $blocks = array();

			public function __construct() { // phpcs:ignore
			}

			public function get_project_id( int $chapter_id ): int {
				return PipelineRouterTest::PROJECT;
			}

			public function find_block( int $chapter_id, string $block_id ): ?array {
				return $this->blocks[ $block_id ] ?? null;
			}
		};
		$chapters->blocks['b1'] = array( 'id' => 'b1', 'type' => 'paragrafo', 'version' => 4 );

		$dispatcher = new Dispatcher(
			static fn( string $class ): object => new $class(),
			$null_log,
			function ( string $hook, array $args, string $group ): void {
				$this->enqueued[] = array( 'hook' => $hook, 'args' => $args[0] );
			},
			static function (): void {
			},
			static fn(): bool => false
		);
		foreach ( array( MaterializeChaptersJob::class, DraftChapterJob::class, SynopsisJob::class, ReviewChapterJob::class, RewriteBlockJob::class ) as $job ) {
			$dispatcher->register_job( $job );
		}

		$this->router = new PipelineRouter( $dispatcher, $this->states, $projects, $chapters );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return string[] Hook accodati. */
	private function hooks(): array {
		return array_column( $this->enqueued, 'hook' );
	}

	public function test_outline_approved_materializes_chapters(): void {
		$this->router->on_state_changed( self::PROJECT, 'project', 'outline_proposed', 'outline_approved', 'outline_approved' );

		self::assertSame( array( 'gw_job_materialize_chapters' ), $this->hooks() );
		self::assertSame( self::PROJECT, $this->enqueued[0]['args']['project_id'] );
	}

	public function test_generating_dispatches_first_planned_chapter(): void {
		// CH1 già completo, CH2 ancora planned → parte CH2.
		$this->meta[ self::CH1 ][ StateMachine::META_STATE ] = 'complete';

		$this->router->on_state_changed( self::PROJECT, 'project', 'outline_approved', 'generating', 'generation_started' );

		self::assertSame( array( 'gw_job_draft_chapter' ), $this->hooks() );
		self::assertSame( self::CH2, $this->enqueued[0]['args']['chapter_id'] );
	}

	public function test_draft_ready_dispatches_synopsis(): void {
		$this->router->on_state_changed( self::CH1, 'chapter', 'drafting', 'draft_ready', 'draft_ready' );

		self::assertSame( array( 'gw_job_synopsis' ), $this->hooks() );
	}

	public function test_in_review_dispatches_review(): void {
		$this->router->on_state_changed( self::CH1, 'chapter', 'draft_ready', 'in_review', 'review_started' );

		self::assertSame( array( 'gw_job_review_chapter' ), $this->hooks() );
	}

	public function test_revised_skips_images_and_completes_chapter(): void {
		$this->meta[ self::CH1 ][ StateMachine::META_STATE ] = 'revised';

		$this->router->on_state_changed( self::CH1, 'chapter', 'in_review', 'revised', 'review_completed' );

		// Nessun job: transizione diretta a complete (immagini in fase 5).
		self::assertSame( array(), $this->hooks() );
		self::assertSame( 'complete', $this->meta[ self::CH1 ][ StateMachine::META_STATE ] );
	}

	public function test_chapter_complete_starts_next_planned(): void {
		$this->meta[ self::CH1 ][ StateMachine::META_STATE ] = 'complete';
		// CH2 senza stato → planned.

		$this->router->on_state_changed( self::CH1, 'chapter', 'revised', 'complete', 'completed' );

		self::assertSame( array( 'gw_job_draft_chapter' ), $this->hooks() );
		self::assertSame( self::CH2, $this->enqueued[0]['args']['chapter_id'] );
	}

	public function test_last_chapter_complete_moves_project_to_review(): void {
		$this->meta[ self::CH1 ][ StateMachine::META_STATE ]     = 'complete';
		$this->meta[ self::CH2 ][ StateMachine::META_STATE ]     = 'complete';
		$this->meta[ self::PROJECT ][ StateMachine::META_STATE ] = 'generating';

		$this->router->on_state_changed( self::CH2, 'chapter', 'revised', 'complete', 'completed' );

		self::assertSame( array(), $this->hooks() );
		self::assertSame( 'review', $this->meta[ self::PROJECT ][ StateMachine::META_STATE ] );
	}

	public function test_rewrite_request_dispatches_rewrite_job_with_expected_version(): void {
		$this->router->on_rewrite_requested( self::CH1, 'b1', 'accorcia', 5, true );

		self::assertSame( array( 'gw_job_rewrite_block' ), $this->hooks() );
		$args = $this->enqueued[0]['args'];
		self::assertSame( 'b1', $args['block_id'] );
		self::assertSame( 'accorcia', $args['feedback'] );
		self::assertSame( 4, $args['expected_version'] );
		self::assertTrue( $args['refresh_synopsis'] );
	}
}

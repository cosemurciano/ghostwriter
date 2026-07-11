<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Queue\Dispatcher;
use Ghostwriter\Queue\JobInterface;
use Ghostwriter\Repository\LogRepository;
use PHPUnit\Framework\TestCase;

/**
 * Job di prova controllabile dai test.
 */
final class SpyJob implements JobInterface {

	/** @var array<int, array<string, mixed>> */
	public static array $handled = array();
	public static ?\Throwable $throws = null;

	/** @var array<int, array<string, mixed>> */
	public static array $failures = array();

	public static function reset(): void {
		self::$handled  = array();
		self::$failures = array();
		self::$throws   = null;
	}

	public static function name(): string {
		return 'spy';
	}

	public function handle( array $args ): void {
		self::$handled[] = $args;
		if ( null !== self::$throws ) {
			throw self::$throws;
		}
	}

	public function on_failure( array $args, \Throwable $e ): void {
		self::$failures[] = $args;
	}
}

final class DispatcherTest extends TestCase {

	/** @var array<int, array{hook: string, args: array, group: string}> */
	private array $enqueued = array();

	/** @var array<int, array{ts: int, hook: string, args: array, group: string}> */
	private array $scheduled = array();

	private Dispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		SpyJob::reset();
		$this->enqueued  = array();
		$this->scheduled = array();

		$null_log = new class() extends LogRepository {
			public function log( int $project_id, ?int $chapter_id, string $level, string $event, array $context = array() ): void {
				// Niente DB nei test.
			}
		};

		$this->dispatcher = new Dispatcher(
			static fn( string $class ): JobInterface => new $class(),
			$null_log,
			function ( string $hook, array $args, string $group ): void {
				$this->enqueued[] = compact( 'hook', 'args', 'group' );
			},
			function ( int $ts, string $hook, array $args, string $group ): void {
				$this->scheduled[] = compact( 'ts', 'hook', 'args', 'group' );
			},
			fn( string $hook, array $args, string $group ): bool =>
				(bool) array_filter( $this->enqueued, static fn( array $e ): bool => $e['hook'] === $hook && $e['args'] === $args )
		);
		$this->dispatcher->register_job( SpyJob::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dispatch_enqueues_with_group(): void {
		self::assertTrue( $this->dispatcher->dispatch( SpyJob::class, array( 'project_id' => 7 ) ) );

		self::assertCount( 1, $this->enqueued );
		self::assertSame( 'gw_job_spy', $this->enqueued[0]['hook'] );
		self::assertSame( array( array( 'project_id' => 7 ) ), $this->enqueued[0]['args'] );
		self::assertSame( Dispatcher::GROUP, $this->enqueued[0]['group'] );
	}

	public function test_duplicate_dispatch_is_skipped(): void {
		self::assertTrue( $this->dispatcher->dispatch( SpyJob::class, array( 'project_id' => 7, 'chapter_id' => 12 ) ) );
		self::assertFalse( $this->dispatcher->dispatch( SpyJob::class, array( 'project_id' => 7, 'chapter_id' => 12 ) ) );
		self::assertTrue( $this->dispatcher->dispatch( SpyJob::class, array( 'project_id' => 7, 'chapter_id' => 13 ) ) );

		self::assertCount( 2, $this->enqueued );
	}

	public function test_unregistered_job_throws(): void {
		$other = new class() implements JobInterface {
			public static function name(): string {
				return 'altro';
			}
			public function handle( array $args ): void {
			}
			public function on_failure( array $args, \Throwable $e ): void {
			}
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->dispatcher->dispatch( $other::class, array() );
	}

	public function test_dedup_key_format(): void {
		self::assertSame(
			'draft_chapter:7:12',
			Dispatcher::dedup_key( 'draft_chapter', array( 'project_id' => 7, 'chapter_id' => 12, 'attempt' => 2 ) )
		);
		self::assertSame(
			'rewrite_block:7:12:block_id=b1:feedback=accorcia',
			Dispatcher::dedup_key( 'rewrite_block', array( 'chapter_id' => 12, 'project_id' => 7, 'feedback' => 'accorcia', 'block_id' => 'b1' ) )
		);
	}

	public function test_run_executes_job(): void {
		$this->dispatcher->run( 'spy', array( 'project_id' => 7 ) );

		self::assertCount( 1, SpyJob::$handled );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_failed_run_schedules_retry_with_exponential_backoff(): void {
		SpyJob::$throws = new \RuntimeException( 'provider giù' );

		$this->dispatcher->run( 'spy', array( 'project_id' => 7 ) );

		self::assertCount( 1, $this->scheduled );
		self::assertSame( 2, $this->scheduled[0]['args'][0]['attempt'] );
		self::assertEqualsWithDelta( time() + 60, $this->scheduled[0]['ts'], 2 );

		$this->dispatcher->run( 'spy', $this->scheduled[0]['args'][0] );
		self::assertCount( 2, $this->scheduled );
		self::assertSame( 3, $this->scheduled[1]['args'][0]['attempt'] );
		self::assertEqualsWithDelta( time() + 240, $this->scheduled[1]['ts'], 2 );

		self::assertSame( array(), SpyJob::$failures );
	}

	public function test_exhausted_attempts_call_on_failure(): void {
		SpyJob::$throws = new \RuntimeException( 'provider giù' );

		$this->dispatcher->run( 'spy', array( 'project_id' => 7, 'attempt' => 3 ) );

		self::assertCount( 1, SpyJob::$failures );
		self::assertSame( array(), $this->scheduled );
	}
}

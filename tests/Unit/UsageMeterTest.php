<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Ai\UsageMeter;
use Ghostwriter\Domain\StateMachine;
use Ghostwriter\Repository\LogRepository;
use Ghostwriter\Repository\ProjectRepository;
use Ghostwriter\Repository\UsageRepository;
use PHPUnit\Framework\TestCase;

final class UsageMeterTest extends TestCase {

	private const PROJECT = 7;

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array{input_tokens: int, output_tokens: int, images: int, cost_estimate: float} */
	private array $totals;

	/** @var array<string, mixed> */
	private array $budget;

	/** @var array<int, array<string, mixed>> */
	private array $recorded = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );

		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias( function ( int $id, string $key, $value ): bool {
			$this->meta[ $id ][ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_post_meta' )->alias( function ( int $id, string $key ): bool {
			unset( $this->meta[ $id ][ $key ] );
			return true;
		} );

		$this->meta     = array();
		$this->recorded = array();
		$this->totals   = array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'images'        => 0,
			'cost_estimate' => 0.0,
		);
		$this->budget   = array( 'max_cost_eur' => 10.0, 'max_images' => 5 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function meter(): UsageMeter {
		$usage = new class( $this ) extends UsageRepository {
			public function __construct( private UsageMeterTest $test ) { // phpcs:ignore
			}
			public function record( int $project_id, string $job, string $provider, string $model, int $input_tokens, int $output_tokens, int $images = 0, float $cost_estimate = 0.0 ): void {
				$this->test->on_record( compact( 'job', 'model', 'input_tokens', 'output_tokens', 'images', 'cost_estimate' ) );
			}
			public function totals( int $project_id ): array {
				return $this->test->totals();
			}
		};

		$projects = new class( $this ) extends ProjectRepository {
			public function __construct( private UsageMeterTest $test ) { // phpcs:ignore
			}
			public function get_config( int $project_id ): array {
				return array( 'ai' => array( 'budget' => $this->test->budget() ) );
			}
		};

		$null_log = new class() extends LogRepository {
			public function log( int $project_id, ?int $chapter_id, string $level, string $event, array $context = array() ): void {
			}
		};

		return new UsageMeter( $usage, $projects, new StateMachine( $null_log ) );
	}

	/** @param array<string, mixed> $row Riga registrata. */
	public function on_record( array $row ): void {
		$this->recorded[] = $row;
	}

	/** @return array{input_tokens: int, output_tokens: int, images: int, cost_estimate: float} */
	public function totals(): array {
		return $this->totals;
	}

	/** @return array<string, mixed> */
	public function budget(): array {
		return $this->budget;
	}

	public function test_cost_estimation_uses_model_rates(): void {
		// claude-opus: 4,7 EUR/Mtok in, 23,5 EUR/Mtok out.
		$cost = UsageMeter::estimate_cost( 'claude-opus-4-8', 1_000_000, 100_000 );
		self::assertEqualsWithDelta( 4.7 + 2.35, $cost, 0.001 );

		// claude-fable ha una tariffa propria, più alta della famiglia opus.
		self::assertGreaterThan( $cost, UsageMeter::estimate_cost( 'claude-fable-5', 1_000_000, 100_000 ) );

		// Modello non mappato: costo 0 (il cap sul costo non scatta).
		self::assertSame( 0.0, UsageMeter::estimate_cost( 'modello-ignoto', 1_000_000, 1_000_000 ) );
	}

	public function test_record_within_budget_does_not_pause(): void {
		$this->meta[ self::PROJECT ][ StateMachine::META_STATE ] = 'generating';
		$this->totals['cost_estimate']                           = 3.0;

		$this->meter()->record( self::PROJECT, 'draft_chapter', 'anthropic', 'claude-opus-4-8', 1000, 500 );

		self::assertCount( 1, $this->recorded );
		self::assertGreaterThan( 0, $this->recorded[0]['cost_estimate'] );
		self::assertSame( 'generating', $this->meta[ self::PROJECT ][ StateMachine::META_STATE ] );
	}

	public function test_budget_exceeded_pauses_project_and_remembers_state(): void {
		$this->meta[ self::PROJECT ][ StateMachine::META_STATE ] = 'generating';
		$this->totals['cost_estimate']                           = 12.5; // > max 10.

		$this->meter()->record( self::PROJECT, 'draft_chapter', 'anthropic', 'claude-opus-4-8', 1000, 500 );

		self::assertSame( 'paused_budget', $this->meta[ self::PROJECT ][ StateMachine::META_STATE ] );
		self::assertSame( 'generating', $this->meta[ self::PROJECT ][ StateMachine::META_PREVIOUS_STATE ] );
	}

	public function test_images_cap(): void {
		$this->totals['images'] = 5;

		self::assertSame( 'max_images', $this->meter()->exceeded_limit( self::PROJECT ) );
		self::assertFalse( $this->meter()->within_budget( self::PROJECT ) );
	}

	public function test_no_budget_means_no_cap(): void {
		$this->budget                  = array();
		$this->totals['cost_estimate'] = 1000.0;

		self::assertTrue( $this->meter()->within_budget( self::PROJECT ) );
	}

	public function test_report_alerts_at_threshold(): void {
		$this->budget                  = array( 'max_cost_eur' => 10.0, 'alert_at_pct' => 80 );
		$this->totals['cost_estimate'] = 8.5;

		$report = $this->meter()->report( self::PROJECT );

		self::assertSame( 85, $report['cost_pct'] );
		self::assertTrue( $report['alert'] );
		self::assertNull( $report['exceeded'] );
	}
}

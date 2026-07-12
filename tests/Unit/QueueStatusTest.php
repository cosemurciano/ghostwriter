<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Queue\QueueStatus;
use PHPUnit\Framework\TestCase;

final class QueueStatusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function fake_action( string $hook, array $args, ?\DateTimeImmutable $date = null ): object {
		return new class( $hook, $args, $date ) {
			public function __construct( private string $hook, private array $args, private ?\DateTimeImmutable $date ) {
			}
			public function get_hook(): string {
				return $this->hook;
			}
			public function get_args(): array {
				return array( $this->args );
			}
			public function get_schedule(): object {
				return new class( $this->date ) {
					public function __construct( private ?\DateTimeImmutable $date ) {
					}
					public function get_date(): ?\DateTimeImmutable {
						return $this->date;
					}
				};
			}
		};
	}

	public function test_reports_only_project_jobs_with_attempt_and_next_run(): void {
		$retry_at = new \DateTimeImmutable( '2026-07-12 08:42:00' );
		$actions  = array(
			'in-progress' => array(
				self::fake_action( 'gw_job_propose_outline', array( 'project_id' => 7, 'attempt' => 2 ) ),
			),
			'pending'     => array(
				self::fake_action( 'gw_job_draft_chapter', array( 'project_id' => 7, 'chapter_id' => 3 ), $retry_at ),
				self::fake_action( 'gw_job_draft_chapter', array( 'project_id' => 99 ) ), // Altro progetto.
				self::fake_action( 'some_other_plugin_hook', array( 'project_id' => 7 ) ), // Non Ghostwriter.
			),
		);

		$status = new QueueStatus( static fn( array $query ): array => $actions[ $query['status'] ] );
		$jobs   = $status->for_project( 7 );

		self::assertCount( 2, $jobs );

		self::assertSame( 'propose_outline', $jobs[0]['job'] );
		self::assertSame( 'in-progress', $jobs[0]['status'] );
		self::assertSame( 2, $jobs[0]['attempt'] );
		self::assertSame( 'Proposta indice', $jobs[0]['label'] );

		self::assertSame( 'draft_chapter', $jobs[1]['job'] );
		self::assertSame( 'pending', $jobs[1]['status'] );
		self::assertSame( 1, $jobs[1]['attempt'] );
		self::assertSame( '08:42', $jobs[1]['next_run'] );
	}

	public function test_empty_queue_yields_no_jobs(): void {
		$status = new QueueStatus( static fn(): array => array() );
		self::assertSame( array(), $status->for_project( 7 ) );
	}
}

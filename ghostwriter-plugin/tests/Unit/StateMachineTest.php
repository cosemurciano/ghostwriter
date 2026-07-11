<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Domain\InvalidTransitionException;
use Ghostwriter\Domain\StateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase {

	public function test_initial_states(): void {
		self::assertSame( 'setup', StateMachine::initial_state( StateMachine::TYPE_PROJECT ) );
		self::assertSame( 'planned', StateMachine::initial_state( StateMachine::TYPE_CHAPTER ) );
		self::assertSame( 'pending', StateMachine::initial_state( StateMachine::TYPE_COVER ) );
		self::assertSame( 'setup', StateMachine::initial_state( StateMachine::TYPE_TRANSLATION ) );
	}

	public function test_unknown_entity_type_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		StateMachine::initial_state( 'nope' );
	}

	/**
	 * Percorso felice del progetto: setup → ... → exported.
	 */
	public function test_project_happy_path(): void {
		$type  = StateMachine::TYPE_PROJECT;
		$state = 'setup';

		$path = array(
			'sources_ingest_started' => 'sources_ingesting',
			'outline_proposed'       => 'outline_proposed',
			'outline_approved'       => 'outline_approved',
			'generation_started'     => 'generating',
			'generation_completed'   => 'review',
			'review_completed'       => 'cover_pending',
			'cover_approved'         => 'ready_to_export',
			'exported'               => 'exported',
		);

		foreach ( $path as $event => $expected ) {
			$state = StateMachine::apply( $type, $state, $event );
			self::assertSame( $expected, $state, "Evento {$event}" );
		}
	}

	public function test_project_can_skip_sources_ingestion(): void {
		self::assertSame(
			'outline_proposed',
			StateMachine::apply( StateMachine::TYPE_PROJECT, 'setup', 'outline_proposed' )
		);
	}

	public function test_chapter_happy_path(): void {
		$type  = StateMachine::TYPE_CHAPTER;
		$state = 'planned';

		foreach ( array(
			'draft_started'    => 'drafting',
			'draft_ready'      => 'draft_ready',
			'review_started'   => 'in_review',
			'review_completed' => 'revised',
			'images_requested' => 'images_pending',
			'completed'        => 'complete',
		) as $event => $expected ) {
			$state = StateMachine::apply( $type, $state, $event );
			self::assertSame( $expected, $state, "Evento {$event}" );
		}
	}

	public function test_cover_happy_path(): void {
		$type  = StateMachine::TYPE_COVER;
		$state = 'pending';

		foreach ( array( 'brief_ready', 'artwork_ready', 'composed', 'approved' ) as $event ) {
			$state = StateMachine::apply( $type, $state, $event );
		}
		self::assertSame( 'approved', $state );
	}

	public function test_translation_happy_path(): void {
		$type  = StateMachine::TYPE_TRANSLATION;
		$state = 'setup';

		foreach ( array(
			'glossary_proposed'     => 'glossary_proposed',
			'glossary_approved'     => 'glossary_approved',
			'translation_started'   => 'translating',
			'translation_completed' => 'review',
			'review_completed'      => 'ready_to_export',
		) as $event => $expected ) {
			$state = StateMachine::apply( $type, $state, $event );
			self::assertSame( $expected, $state, "Evento {$event}" );
		}
	}

	#[DataProvider( 'invalid_transitions' )]
	public function test_invalid_transitions_throw( string $type, string $state, string $event ): void {
		$this->expectException( InvalidTransitionException::class );
		StateMachine::apply( $type, $state, $event );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_transitions(): array {
		return array(
			'export prima dell\'approvazione copertina' => array( StateMachine::TYPE_PROJECT, 'generating', 'exported' ),
			'approvazione outline mai proposto'         => array( StateMachine::TYPE_PROJECT, 'setup', 'outline_approved' ),
			'capitolo completo senza bozza'             => array( StateMachine::TYPE_CHAPTER, 'planned', 'completed' ),
			'revisione su capitolo complete'            => array( StateMachine::TYPE_CHAPTER, 'complete', 'review_started' ),
			'copertina approvata senza composizione'    => array( StateMachine::TYPE_COVER, 'pending', 'approved' ),
			'traduzione senza glossario approvato'      => array( StateMachine::TYPE_TRANSLATION, 'glossary_proposed', 'translation_started' ),
			'retry senza failed'                        => array( StateMachine::TYPE_CHAPTER, 'drafting', 'retry' ),
		);
	}

	public function test_budget_exceeded_parks_project_from_any_state(): void {
		foreach ( array( 'setup', 'generating', 'review', 'ready_to_export' ) as $from ) {
			self::assertSame(
				'paused_budget',
				StateMachine::apply( StateMachine::TYPE_PROJECT, $from, 'budget_exceeded' ),
				"Da {$from}"
			);
		}
	}

	public function test_budget_exceeded_not_allowed_when_already_paused(): void {
		$this->expectException( InvalidTransitionException::class );
		StateMachine::apply( StateMachine::TYPE_PROJECT, 'paused_budget', 'budget_exceeded' );
	}

	public function test_budget_resume_returns_to_previous_state(): void {
		self::assertSame(
			'generating',
			StateMachine::apply( StateMachine::TYPE_PROJECT, 'paused_budget', 'budget_resumed', 'generating' )
		);
	}

	public function test_budget_resume_without_memory_throws(): void {
		$this->expectException( InvalidTransitionException::class );
		StateMachine::apply( StateMachine::TYPE_PROJECT, 'paused_budget', 'budget_resumed', null );
	}

	public function test_chapter_failure_and_retry_from_failed_step(): void {
		self::assertSame(
			'failed',
			StateMachine::apply( StateMachine::TYPE_CHAPTER, 'drafting', 'failed' )
		);
		self::assertSame(
			'drafting',
			StateMachine::apply( StateMachine::TYPE_CHAPTER, 'failed', 'retry', 'drafting' )
		);
	}

	public function test_can_is_side_effect_free(): void {
		self::assertTrue( StateMachine::can( StateMachine::TYPE_PROJECT, 'setup', 'outline_proposed' ) );
		self::assertFalse( StateMachine::can( StateMachine::TYPE_PROJECT, 'setup', 'exported' ) );
	}
}

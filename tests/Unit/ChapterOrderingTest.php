<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Repository\ChapterRepository;
use PHPUnit\Framework\TestCase;

/**
 * Sequenze di ordinamento capitoli: inserimento "dopo X" e spostamenti
 * di un passo. La rinumerazione menu_order e l'indice del libro derivano
 * da queste sequenze.
 */
final class ChapterOrderingTest extends TestCase {

	public function test_insert_after_places_new_chapter_correctly(): void {
		self::assertSame( array( 1, 9, 2, 3 ), ChapterRepository::sequence_insert_after( array( 1, 2, 3 ), 9, 1 ) );
		self::assertSame( array( 1, 2, 3, 9 ), ChapterRepository::sequence_insert_after( array( 1, 2, 3 ), 9, 3 ) );
	}

	public function test_insert_after_defaults_to_end_when_anchor_unknown(): void {
		self::assertSame( array( 1, 2, 3, 9 ), ChapterRepository::sequence_insert_after( array( 1, 2, 3 ), 9, 0 ) );
		self::assertSame( array( 1, 2, 3, 9 ), ChapterRepository::sequence_insert_after( array( 1, 2, 3 ), 9, 777 ) );
	}

	public function test_move_swaps_with_neighbour(): void {
		self::assertSame( array( 2, 1, 3 ), ChapterRepository::sequence_move( array( 1, 2, 3 ), 2, 'up' ) );
		self::assertSame( array( 1, 3, 2 ), ChapterRepository::sequence_move( array( 1, 2, 3 ), 2, 'down' ) );
	}

	public function test_move_is_noop_at_boundaries(): void {
		self::assertSame( array( 1, 2, 3 ), ChapterRepository::sequence_move( array( 1, 2, 3 ), 1, 'up' ) );
		self::assertSame( array( 1, 2, 3 ), ChapterRepository::sequence_move( array( 1, 2, 3 ), 3, 'down' ) );
		self::assertSame( array( 1, 2, 3 ), ChapterRepository::sequence_move( array( 1, 2, 3 ), 99, 'down' ) );
	}
}

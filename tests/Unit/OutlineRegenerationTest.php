<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Queue\Jobs\ProposeOutlineJob;
use PHPUnit\Framework\TestCase;

/**
 * La garanzia deterministica sui capitoli bloccati nella rigenerazione
 * dell'indice: le voci "Mantieni" tornano intatte qualunque cosa faccia l'AI.
 */
final class OutlineRegenerationTest extends TestCase {

	/**
	 * @param array<int, array<string, mixed>> $proposed
	 * @param array<int, array<string, mixed>> $current
	 * @param int[]                            $keep
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge( array $proposed, array $current, array $keep ): array {
		$method = new \ReflectionMethod( ProposeOutlineJob::class, 'enforce_locked' );
		return $method->invoke( null, $proposed, $current, $keep );
	}

	public function test_locked_chapter_kept_verbatim_when_ai_rewrites_it(): void {
		$current = array(
			array( 'title' => 'Le fondamenta', 'brief' => 'Brief originale.', 'planned_sources' => array( 'src-a' ) ),
			array( 'title' => 'Capitolo debole', 'brief' => 'Da rifare.' ),
		);
		$proposed = array(
			array( 'title' => 'le fondamenta ', 'brief' => 'Brief riscritto dall\'AI.' ),
			array( 'title' => 'Nuovo capitolo forte', 'brief' => 'Nuovo brief.' ),
		);

		$merged = self::merge( $proposed, $current, array( 0 ) );

		self::assertCount( 2, $merged );
		self::assertSame( 'Le fondamenta', $merged[0]['title'] );
		self::assertSame( 'Brief originale.', $merged[0]['brief'] );
		self::assertSame( array( 'src-a' ), $merged[0]['planned_sources'] );
		self::assertSame( 'Nuovo capitolo forte', $merged[1]['title'] );
	}

	public function test_locked_chapter_reinserted_if_ai_dropped_it(): void {
		$current = array(
			array( 'title' => 'Introduzione', 'brief' => 'A.' ),
			array( 'title' => 'Il capitolo intoccabile', 'brief' => 'B.' ),
			array( 'title' => 'Chiusura', 'brief' => 'C.' ),
		);
		// L'AI ha ignorato il vincolo e l'ha rimosso.
		$proposed = array(
			array( 'title' => 'Apertura nuova', 'brief' => 'X.' ),
			array( 'title' => 'Finale nuovo', 'brief' => 'Y.' ),
		);

		$merged = self::merge( $proposed, $current, array( 1 ) );

		self::assertCount( 3, $merged );
		self::assertSame( 'Il capitolo intoccabile', $merged[1]['title'] );
		self::assertSame( 'B.', $merged[1]['brief'] );
	}

	public function test_no_locks_returns_ai_proposal_untouched(): void {
		$proposed = array( array( 'title' => 'Solo AI', 'brief' => 'Z.' ) );

		self::assertSame( $proposed, self::merge( $proposed, array( array( 'title' => 'Vecchio', 'brief' => 'W.' ) ), array() ) );
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Domain\Dossier;
use Ghostwriter\Repository\ProjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * Test del merge e del lock ottimistico del dossier, con un repository
 * in-memory al posto dei meta WordPress.
 */
final class DossierTest extends TestCase {

	/**
	 * Repository fittizio: dossier in memoria, nessuna chiamata WP.
	 */
	private function repository_with( ?array $dossier ): ProjectRepository {
		return new class( $dossier ) extends ProjectRepository {
			public ?array $dossier;
			public int $reads = 0;

			public function __construct( ?array $dossier ) { // phpcs:ignore
				$this->dossier = $dossier; // Niente parent: il validator non serve qui.
			}

			public function get_dossier( int $project_id ): ?array {
				++$this->reads;
				return $this->dossier;
			}

			public function save_dossier( int $project_id, array $dossier ): void {
				$this->dossier = $dossier;
			}
		};
	}

	private function base_dossier(): array {
		return array(
			'schema_version' => '1.0',
			'project_id'     => 7,
			'updated_at'     => '2026-07-11T10:00:00+00:00',
			'brief'          => array( 'thesis' => 'T', 'audience' => 'A', 'genre' => 'g', 'language' => 'it' ),
			'outline'        => array(
				array( 'chapter_id' => 12, 'title' => 'Cap 1', 'status' => 'drafting' ),
			),
			'continuity'     => array(
				'terminology'      => array(),
				'concepts_covered' => array(),
				'promises'         => array(),
				'style_decisions'  => array(),
			),
		);
	}

	public function test_update_applies_mutation_and_bumps_updated_at(): void {
		$repo    = $this->repository_with( $this->base_dossier() );
		$dossier = new Dossier( $repo );

		$result = $dossier->update_outline_entry( 7, 12, array( 'status' => 'draft_ready', 'word_count' => 3200 ) );

		self::assertSame( 'draft_ready', $result['outline'][0]['status'] );
		self::assertSame( 3200, $result['outline'][0]['word_count'] );
		self::assertNotSame( '2026-07-11T10:00:00+00:00', $result['updated_at'] );
	}

	public function test_update_adds_entry_for_new_chapter(): void {
		$repo    = $this->repository_with( $this->base_dossier() );
		$dossier = new Dossier( $repo );

		$result = $dossier->update_outline_entry( 7, 99, array( 'title' => 'Nuovo capitolo', 'status' => 'planned' ) );

		self::assertCount( 2, $result['outline'] );
		self::assertSame( 99, $result['outline'][1]['chapter_id'] );
	}

	public function test_record_synopsis_merges_continuity(): void {
		$repo    = $this->repository_with( $this->base_dossier() );
		$dossier = new Dossier( $repo );

		$result = $dossier->record_synopsis(
			7,
			12,
			'Sinossi del capitolo in cento parole.',
			array(
				'terminology' => array(
					array( 'term' => 'masseria', 'definition' => 'Azienda agricola fortificata', 'introduced_in' => 12 ),
				),
				'promises'    => array(
					array( 'text' => 'Approfondiremo le torri costiere', 'made_in' => 12, 'target_chapter' => null, 'fulfilled' => false ),
				),
			)
		);

		self::assertSame( 'Sinossi del capitolo in cento parole.', $result['outline'][0]['synopsis'] );
		self::assertCount( 1, $result['continuity']['terminology'] );
		self::assertCount( 1, $result['continuity']['promises'] );
	}

	public function test_update_on_missing_dossier_throws(): void {
		$dossier = new Dossier( $this->repository_with( null ) );

		$this->expectException( \RuntimeException::class );
		$dossier->update( 7, static fn( array $d ): array => $d );
	}
}

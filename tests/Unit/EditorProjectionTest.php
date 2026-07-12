<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Rendering\EditorProjection;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip formato intermedio → HTML editor → formato intermedio:
 * id conservati, versioni incrementate solo sui blocchi modificati,
 * blocchi complessi intatti, nuovi elementi mappati sul tipo giusto.
 */
final class EditorProjectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/img.jpg' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function chapter(): array {
		return array(
			'schema_version' => '1.0',
			'chapter_id'     => 7,
			'meta'           => array( 'title' => 'Capitolo uno' ),
			'blocks'         => array(
				array( 'id' => 'p-1', 'type' => 'paragrafo', 'version' => 2, 'props' => array( 'text' => 'Testo con **grassetto** e nota [^n1].', 'role' => 'lead' ), 'sources' => array( array( 'source_id' => 'src-a' ) ) ),
				array( 'id' => 'h-1', 'type' => 'heading', 'version' => 1, 'props' => array( 'text' => 'Sezione', 'level' => 2 ) ),
				array( 'id' => 'l-1', 'type' => 'elenco', 'version' => 1, 'props' => array( 'ordered' => false, 'items' => array( 'uno', 'due' ) ) ),
				array( 'id' => 'x-1', 'type' => 'esercizio', 'version' => 3, 'props' => array( 'text' => 'Prova tu.', 'number' => '1.1' ) ),
				array( 'id' => 'f-1', 'type' => 'figura', 'version' => 1, 'props' => array( 'attachment_id' => 42, 'caption' => 'Una figura', 'alt' => 'descrizione', 'image_brief' => 'brief originario' ) ),
			),
			'notes'          => array( array( 'note_id' => 'n1', 'text' => 'La nota.' ) ),
		);
	}

	public function test_round_trip_preserves_ids_versions_and_locked_blocks(): void {
		$original = self::chapter();
		$html     = EditorProjection::to_html( $original );

		self::assertStringContainsString( 'data-gw-id="p-1"', $html );
		self::assertStringContainsString( '<strong>grassetto</strong>', $html );
		self::assertStringContainsString( 'gw-locked-block', $html );
		self::assertStringContainsString( 'wp-image-42', $html );

		$roundtrip = EditorProjection::to_blocks( $html, $original );
		$blocks    = $roundtrip['blocks'];

		self::assertCount( 5, $blocks );
		// Nulla è cambiato: id e versioni identici, markdown ricostruito.
		self::assertSame( 'p-1', $blocks[0]['id'] );
		self::assertSame( 2, $blocks[0]['version'] );
		self::assertSame( 'Testo con **grassetto** e nota [^n1].', $blocks[0]['props']['text'] );
		self::assertSame( 'lead', $blocks[0]['props']['role'], 'le props non rappresentabili si conservano' );
		self::assertSame( array( array( 'source_id' => 'src-a' ) ), $blocks[0]['sources'] );
		// Il blocco complesso torna verbatim.
		self::assertSame( $original['blocks'][3], $blocks[3] );
		// La figura conserva attachment e brief.
		self::assertSame( 42, $blocks[4]['props']['attachment_id'] );
		self::assertSame( 'brief originario', $blocks[4]['props']['image_brief'] );
		// Meta e note sopravvivono.
		self::assertSame( $original['notes'], $roundtrip['notes'] );
	}

	public function test_edited_paragraph_bumps_version_and_new_elements_become_blocks(): void {
		$original = self::chapter();
		$html     = EditorProjection::to_html( $original );

		// L'utente modifica il primo paragrafo e aggiunge un H3 e un'immagine dal media uploader.
		$html = str_replace( 'Testo con <strong>grassetto</strong>', 'Testo riscritto con <em>corsivo</em>', $html );
		$html .= "\n\n<h3>Nuova sezione</h3>\n\n<p><img class=\"alignnone wp-image-99\" src=\"https://example.test/nuova.jpg\" alt=\"nuova\"/></p>";

		$roundtrip = EditorProjection::to_blocks( $html, $original );
		$blocks    = $roundtrip['blocks'];

		self::assertCount( 7, $blocks );
		self::assertSame( 'p-1', $blocks[0]['id'] );
		self::assertSame( 3, $blocks[0]['version'], 'blocco modificato: version +1' );
		self::assertSame( 'Testo riscritto con *corsivo* e nota [^n1].', $blocks[0]['props']['text'] );

		self::assertSame( 'heading', $blocks[5]['type'] );
		self::assertSame( array( 'text' => 'Nuova sezione', 'level' => 3 ), array_intersect_key( $blocks[5]['props'], array( 'text' => 1, 'level' => 1 ) ) );
		self::assertSame( 1, $blocks[5]['version'] );
		self::assertNotEmpty( $blocks[5]['id'] );

		self::assertSame( 'figura', $blocks[6]['type'] );
		self::assertSame( 99, $blocks[6]['props']['attachment_id'] );
		self::assertSame( 'nuova', $blocks[6]['props']['alt'] );
	}
}

<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Ghostwriter\Rendering\RichText;
use PHPUnit\Framework\TestCase;

final class RichTextTest extends TestCase {

	public function test_emphasis(): void {
		self::assertSame(
			'Testo <strong>forte</strong> e <em>corsivo</em>.',
			RichText::render( 'Testo **forte** e *corsivo*.' )
		);
	}

	public function test_html_is_escaped(): void {
		self::assertSame(
			'&lt;script&gt;alert(1)&lt;/script&gt;',
			RichText::render( '<script>alert(1)</script>' )
		);
	}

	public function test_link(): void {
		self::assertSame(
			'Vedi <a href="https://example.org/x">la fonte</a>.',
			RichText::render( 'Vedi [la fonte](https://example.org/x).' )
		);
	}

	public function test_javascript_link_is_not_rendered(): void {
		$out = RichText::render( '[clic](javascript:alert(1))' );
		self::assertStringNotContainsString( '<a ', $out );
	}

	public function test_note_ref_default(): void {
		self::assertSame(
			'I registri catastali<sup class="gw-noteref"><a href="#gw-note-n1">n1</a></sup>.',
			RichText::render( 'I registri catastali[^n1].' )
		);
	}

	public function test_note_ref_custom_renderer(): void {
		$out = RichText::render( 'Testo[^n1]', static fn( string $id ): string => "<sup>{$id}!</sup>" );
		self::assertSame( 'Testo<sup>n1!</sup>', $out );
	}

	public function test_to_plain_strips_syntax(): void {
		self::assertSame(
			'Vedi la fonte e nota',
			RichText::to_plain( 'Vedi [la fonte](https://example.org) e *nota*[^n1]' )
		);
	}
}

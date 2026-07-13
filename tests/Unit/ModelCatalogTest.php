<?php
declare(strict_types=1);

namespace Ghostwriter\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Ghostwriter\Ai\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_are_first_catalog_entry(): void {
		self::assertSame( 'claude-opus-4-8', ModelCatalog::default_for( 'anthropic' ) );
		self::assertSame( 'gpt-5', ModelCatalog::default_for( 'openai' ) );
		self::assertSame( 'gpt-image-1', ModelCatalog::default_for( 'openai', 'image' ) );
		self::assertSame( '', ModelCatalog::default_for( '' ) );
		self::assertSame( '', ModelCatalog::default_for( 'sconosciuto' ) );
	}

	public function test_model_ids_have_no_date_suffix(): void {
		foreach ( ModelCatalog::text_models() as $models ) {
			foreach ( array_keys( $models ) as $id ) {
				self::assertDoesNotMatchRegularExpression( '/\d{8}$/', $id );
			}
		}
	}

	public function test_picker_marks_known_model_selected(): void {
		$html = ModelCatalog::picker( 'text', 'model', 'anthropic', 'claude-sonnet-5', 'provider' );

		self::assertStringContainsString( 'value="claude-sonnet-5" selected', $html );
		// Il select degli altri provider non deve finire nel FormData.
		self::assertStringContainsString( 'data-gw-models-for="openai" disabled hidden', $html );
		// Campo custom vuoto e nascosto.
		self::assertStringContainsString( 'name="model_custom" class="gw-model-custom" value=""', $html );
	}

	public function test_picker_falls_back_to_custom_for_unknown_model(): void {
		$html = ModelCatalog::picker( 'text', 'model', 'anthropic', 'modello-di-domani', 'provider' );

		self::assertStringContainsString( 'value="' . ModelCatalog::CUSTOM . '" selected', $html );
		self::assertStringContainsString( 'value="modello-di-domani"', $html );
		self::assertStringNotContainsString( 'value="modello-di-domani" hidden', $html );
	}

	public function test_picker_without_provider_hides_everything(): void {
		$html = ModelCatalog::picker( 'image', 'image_model', '', '', 'image_provider' );

		self::assertStringNotContainsString( '" selected', $html );
		self::assertStringContainsString( 'data-gw-models-for="openai" disabled hidden', $html );
		self::assertStringContainsString( 'data-gw-models-for="mock" disabled hidden', $html );
	}
}

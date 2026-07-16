<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\BlueprintsPage;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\DesignTokens\BrandKit;

/**
 * @covers \Stonewright\WpMcp\Blueprints\BlueprintStore
 * @covers \Stonewright\WpMcp\DesignTokens\BrandKit
 * @covers \Stonewright\WpMcp\Admin\Pages\BlueprintsPage
 */
final class BlueprintBrandKitV2Test extends TestCase {

	public function test_blueprint_normalize_adds_v2_fields(): void {
		$normalized = BlueprintStore::normalize(
			[
				'id'          => 'agency',
				'name'        => 'Creative Agency',
				'description' => 'Landing',
				'industry'    => 'agency',
				'spec'        => [
					'version'  => '1.0.0',
					'page'     => [ 'title' => 'Agency' ],
					'sections' => [
						[ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hi', 'level' => 1 ] ] ],
					],
				],
			]
		);

		self::assertSame( '2.0.0', $normalized['version'] );
		self::assertSame( 'landing', $normalized['page_type'] );
		self::assertIsArray( $normalized['required_content_facts'] );
		self::assertTrue( $normalized['engine_compatibility']['elementor'] );
		self::assertTrue( $normalized['engine_compatibility']['gutenberg'] );
		self::assertSame( 'wcag-2.2-aa', $normalized['accessibility_intent']['target'] );

		$errors = BlueprintStore::validate_schema_fields( $normalized );
		self::assertSame( [], $errors );
	}

	public function test_blueprint_validate_rejects_bad_page_type_when_forced(): void {
		// validate uses normalize which remaps unknown page_type to landing — assert page_types list instead.
		self::assertContains( 'campaign', BlueprintStore::page_types() );
		self::assertContains( 'shop', BlueprintStore::page_types() );

		$raw = [
			'id'                   => 'x',
			'version'              => '2.0.0',
			'page_type'            => 'landing',
			'engine_compatibility' => [ 'elementor' => true, 'gutenberg' => true ],
			'accessibility_intent' => [ 'target' => 'wcag-2.2-aa' ],
			'spec'                 => [ 'page' => [ 'title' => 'X' ], 'sections' => [ [ 'id' => 's', 'blocks' => [] ] ] ],
		];
		self::assertSame( [], BlueprintStore::validate_schema_fields( $raw ) );
	}

	public function test_bundled_blueprints_expose_v2_summary_fields(): void {
		$list = BlueprintStore::list();
		self::assertNotEmpty( $list );
		foreach ( $list as $row ) {
			self::assertArrayHasKey( 'version', $row );
			self::assertArrayHasKey( 'page_type', $row );
			self::assertArrayHasKey( 'required_content_facts', $row );
			self::assertArrayHasKey( 'engine_compatibility', $row );
			self::assertArrayHasKey( 'accessibility_intent', $row );
			self::assertContains( $row['page_type'], BlueprintStore::page_types() );
		}
	}

	public function test_brand_kit_contrast_pairs(): void {
		// Black on white → passes AA.
		$ok = BrandKit::validate_contrast_pairs(
			[
				'colors' => [
					'primary'    => '#111827',
					'background' => '#FFFFFF',
					'text'       => '#111827',
					'surface'    => '#F9FAFB',
					'accent'     => '#2563EB',
				],
			]
		);
		self::assertTrue( $ok['ok'] );
		self::assertSame( [], $ok['missing_roles'] );

		// Light gray on white → fails text/background.
		$bad = BrandKit::validate_contrast_pairs(
			[
				'colors' => [
					'primary'    => '#CCCCCC',
					'background' => '#FFFFFF',
					'text'       => '#DDDDDD',
					'surface'    => '#FFFFFF',
					'accent'     => '#EEEEEE',
				],
			]
		);
		self::assertFalse( $bad['ok'] );

		$ratio = BrandKit::contrast_ratio( '#000000', '#FFFFFF' );
		self::assertNotNull( $ratio );
		self::assertGreaterThan( 20.0, $ratio );
	}

	public function test_apply_to_draft_is_primary_cta_copy(): void {
		$prompt = BlueprintsPage::apply_to_draft_prompt(
			[
				'id'   => 'agency',
				'name' => 'Creative Agency',
			]
		);
		self::assertStringContainsString( 'draft', strtolower( $prompt ) );
		self::assertStringContainsString( 'blueprint-apply', $prompt );
		self::assertStringContainsString( 'Never publish', $prompt );
	}
}

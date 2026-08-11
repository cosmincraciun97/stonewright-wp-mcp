<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * @covers \Stonewright\WpMcp\Support\PromptCatalog
 */
final class PromptCatalogTest extends TestCase {

	public function test_catalog_loads_core_prompts_without_design_library_starters(): void {
		$catalog = PromptCatalog::load();
		// Design Library admin prompts were removed; keep a solid core catalog only.
		self::assertGreaterThanOrEqual( 15, count( $catalog['prompts'] ) );
		self::assertGreaterThanOrEqual( 1, (int) $catalog['version'] );

		$ids = array_map(
			static fn( array $prompt ): string => (string) ( $prompt['id'] ?? '' ),
			$catalog['prompts']
		);
		self::assertContains( 'figma-to-native-pixel', $ids );
		foreach (
			[
				'brand-kit-apply',
				'blueprint-industry-site',
				'blueprint-engine-elementor',
				'blueprint-engine-gutenberg',
				'blueprint-engine-fse',
				'brand-kit-preview-restore',
			] as $removed
		) {
			self::assertNotContains( $removed, $ids );
		}
	}

	public function test_each_prompt_has_required_fields(): void {
		foreach ( PromptCatalog::all() as $prompt ) {
			self::assertNotSame( '', $prompt['id'] );
			self::assertNotSame( '', $prompt['title'] );
			self::assertNotSame( '', $prompt['outcome'] );
			self::assertNotSame( '', $prompt['prompt'] );
			self::assertIsArray( $prompt['tools'] );
			self::assertNotEmpty( $prompt['tools'] );
			self::assertContains( 'stonewright-task-start', $prompt['tools'] );
			self::assertStringContainsString( 'stonewright-task-start', $prompt['prompt'] );
			self::assertNotSame( '', $prompt['verification'] );
		}
	}

	public function test_search_filters_by_outcome_and_query(): void {
		$elementor = PromptCatalog::search( '', 'elementor' );
		self::assertNotEmpty( $elementor );
		foreach ( $elementor as $prompt ) {
			self::assertSame( 'elementor', $prompt['outcome'] );
		}

		$snapshot = PromptCatalog::search( 'site-snapshot' );
		self::assertNotEmpty( $snapshot );
		self::assertStringContainsString( 'snapshot', strtolower( $snapshot[0]['id'] . $snapshot[0]['prompt'] ) );
	}

	public function test_outcomes_are_non_empty(): void {
		$outcomes = PromptCatalog::outcomes();
		self::assertContains( 'inspect', $outcomes );
		self::assertContains( 'elementor', $outcomes );
		self::assertContains( 'gutenberg', $outcomes );
	}
}

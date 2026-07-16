<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * @covers \Stonewright\WpMcp\Support\PromptCatalog
 */
final class PromptCatalogTest extends TestCase {

	public function test_catalog_loads_at_least_ten_prompts(): void {
		$catalog = PromptCatalog::load();
		self::assertGreaterThanOrEqual( 10, count( $catalog['prompts'] ) );
		self::assertGreaterThanOrEqual( 1, (int) $catalog['version'] );
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

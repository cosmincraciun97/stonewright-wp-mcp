<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;

/**
 * @covers \Stonewright\WpMcp\Admin\AbilitiesPage
 */
final class AbilitiesPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled'            => true,
			'stonewright_disabled_abilities' => [ 'stonewright/ping' ],
		];
		$_GET = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$_GET = [];
	}

	public function test_render_outputs_compact_grouped_abilities_hub(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-abilities-toolbar', $html );
		self::assertStringContainsString( 'id="stonewright-ability-search"', $html );
		self::assertStringContainsString( 'name="stonewright_bulk_action"', $html );
		self::assertStringContainsString( 'stonewright-provider-group', $html );
		self::assertStringContainsString( 'stonewright-ability-row', $html );
		self::assertStringContainsString( 'stonewright-kind-badge', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'stonewright-schema-table-wrap', $html );
		self::assertStringContainsString( 'stonewright/ping', $html );
	}
}

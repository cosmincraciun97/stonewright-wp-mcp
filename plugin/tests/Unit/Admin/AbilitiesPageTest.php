<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;

/**
 * @covers \Stonewright\WpMcp\Admin\AbilitiesPage
 */
final class AbilitiesPageTest extends TestCase {

	/**
	 * POST field names that must stay stable (toggle + bulk handlers).
	 *
	 * @var list<string>
	 */
	private const FORM_FIELD_NAMES = [
		'action',
		'ability_name',
		'ability_enabled',
		'stonewright_bulk_action',
		'stonewright_bulk_category',
		'stonewright_abilities[]',
	];

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

		self::assertStringContainsString( 'sw-abilities-page', $html );
		self::assertStringContainsString( 'sw-abilities-filters', $html );
		self::assertStringContainsString( 'id="stonewright-ability-search"', $html );
		self::assertStringContainsString( 'name="stonewright_bulk_action"', $html );
		self::assertStringContainsString( 'sw-ability-category', $html );
		self::assertStringContainsString( 'stonewright-ability-row', $html );
		self::assertStringContainsString( 'stonewright-kind-badge', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'stonewright-schema-table-wrap', $html );
		self::assertStringContainsString( 'stonewright/ping', $html );
	}

	public function test_render_includes_sticky_filters_stats_and_switches(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-abilities-filters', $html );
		self::assertStringContainsString( 'sw-abilities-stats', $html );
		self::assertStringContainsString( 'sw-switch', $html );
		self::assertStringContainsString( 'name="ability_enabled"', $html );
		self::assertStringContainsString( 'sw-ability-category', $html );
		self::assertStringContainsString( 'sw-abilities-empty', $html );
		self::assertMatchesRegularExpression( '/Enabled\s+\d+/', $html );
		self::assertMatchesRegularExpression( '/Write\s+\d+/', $html );
		self::assertMatchesRegularExpression( '/Read\s+\d+/', $html );
	}

	public function test_form_field_name_snapshot_is_stable(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		foreach ( self::FORM_FIELD_NAMES as $name ) {
			self::assertStringContainsString(
				'name="' . $name . '"',
				$html,
				"Expected form field name=\"{$name}\" to remain present."
			);
		}
		self::assertStringContainsString( 'value="stonewright_toggle_ability"', $html );
		self::assertStringContainsString( 'value="stonewright_bulk_abilities"', $html );
	}
}

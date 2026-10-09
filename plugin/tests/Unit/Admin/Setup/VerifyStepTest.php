<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Setup;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Setup\SetupContext;
use Stonewright\WpMcp\Admin\Setup\VerifyStep;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * Step 4 of Setup: two buttons of one size, and result lists the page script fills one row per check.
 *
 * @covers \Stonewright\WpMcp\Admin\Setup\VerifyStep
 */
final class VerifyStepTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	private static function html(): string {
		return VerifyStep::html( ( new \ReflectionClass( SetupContext::class ) )->newInstanceWithoutConstructor() );
	}

	public function test_the_two_buttons_are_layer_buttons_of_the_default_size_in_one_actions_row(): void {
		$html = self::html();

		self::assertSame( 1, preg_match( '/<div class="sw-ui-actions"><button[^>]*class="sw-ui-btn"[^>]*data-stonewright-connection-test[^>]*>Run preflight<\/button><button[^>]*class="sw-ui-btn sw-ui-btn--primary"[^>]*data-stonewright-connection-verify[^>]*>Verify connection<\/button><\/div>/', $html ) );
		self::assertStringNotContainsString( 'button-primary', $html, 'No core button next to a layer button.' );
		self::assertStringNotContainsString( 'sw-ui-btn--sm', $html );
	}

	public function test_the_result_lists_are_checklists_and_start_hidden(): void {
		$html = self::html();

		self::assertSame( 2, substr_count( $html, 'class="sw-ui-checks ' ) );
		self::assertStringNotContainsString( 'sw-ui-lineage', $html );
		self::assertMatchesRegularExpression( '/<ol class="sw-ui-checks sw-connection-test-results"[^>]*data-stonewright-connection-results[^>]*hidden/', $html );
		self::assertMatchesRegularExpression( '/<ol class="sw-ui-checks sw-connection-verify-results"[^>]*data-stonewright-connection-verify-results[^>]*hidden/', $html );
	}

	public function test_the_page_script_builds_a_row_with_a_status_a_name_and_a_detail_of_their_own(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/admin.js' );

		foreach ( [ 'sw-ui-checks__item', 'sw-ui-checks__label', 'sw-ui-checks__detail', 'sw-ui-checks__fix' ] as $class ) {
			self::assertStringContainsString( $class, $script, $class );
		}
		self::assertStringNotContainsString( "createTextNode( ' ' )", $script, 'Cells are separated by the layout, not by spaces.' );
	}

	public function test_the_layer_lays_a_check_out_in_three_columns_and_marks_a_failure(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/sw-ui.css' );

		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-checks__item \{[^}]*grid-template-columns: 6rem minmax\(0, 12rem\) minmax\(0, 1fr\);/', $css );
		self::assertMatchesRegularExpression( '/\.sw-ui \.sw-ui-checks__item--danger \{[^}]*background: var\(--sw-danger-soft\);/', $css );
	}
}

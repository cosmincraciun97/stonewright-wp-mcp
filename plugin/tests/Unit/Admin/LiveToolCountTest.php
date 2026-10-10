<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\Pages\StatusPage;
use Stonewright\WpMcp\Admin\RestApi;
use Stonewright\WpMcp\Admin\SetupDiagnostics;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\LiveAbilities;

require_once dirname( __DIR__, 2 ) . '/Support/abilities-registry-double.php';

/**
 * Every tool count the admin shows is taken from the abilities WordPress registered, not
 * from the list of ability classes the plugin ships.
 *
 * @covers \Stonewright\WpMcp\Core\LiveAbilities
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 * @covers \Stonewright\WpMcp\Admin\RestApi
 * @covers \Stonewright\WpMcp\Admin\Pages\StatusPage
 * @covers \Stonewright\WpMcp\Admin\AbilitiesPage
 */
final class LiveToolCountTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                                   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']                 = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']                   = [
			'stonewright_enabled'            => true,
			'stonewright_mcp_surface'        => 'full',
			'stonewright_disabled_abilities' => [],
			'stonewright_mode'               => 'development',
		];
		$GLOBALS['stonewright_test_transients']                = [];
		$GLOBALS['stonewright_test_registered_abilities']      = null;
		$_GET                                                  = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']            = [];
		$GLOBALS['stonewright_test_options']              = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_registered_abilities'] = null;
		$GLOBALS['stonewright_test_filters']              = [];
		$_GET = [];
	}

	/** Registers every ability except the named ones. */
	private function leave_unregistered( string ...$missing ): int {
		$names = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$names[] = ( new $class() )->name();
		}
		$GLOBALS['stonewright_test_registered_abilities'] = array_values( array_diff( $names, $missing ) );

		return count( $names );
	}

	public function test_the_exposed_count_leaves_out_abilities_the_abilities_api_did_not_register(): void {
		$declared = count( AbilityRegistry::mcp_server_ability_names() );
		self::assertSame( $declared, LiveAbilities::exposed_count() );

		$this->leave_unregistered( 'stonewright/ping', 'stonewright/site-info' );

		self::assertSame( $declared - 2, LiveAbilities::exposed_count() );
	}

	public function test_the_exposed_count_leaves_out_disabled_abilities(): void {
		$declared                                                       = count( AbilityRegistry::mcp_server_ability_names() );
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/site-info' ];

		self::assertSame( $declared - 1, LiveAbilities::exposed_count() );
	}

	public function test_the_setup_report_counts_live_tools(): void {
		$declared = count( AbilityRegistry::mcp_server_ability_names() );
		$this->leave_unregistered( 'stonewright/ping', 'stonewright/site-info', 'stonewright/capability-preflight' );

		$report = SetupDiagnostics::report( [ 'endpoint' => 'https://example.test/wp-json/mcp/stonewright' ] );

		self::assertSame( $declared - 3, $report['versions']['tool_count'] );
		$summaries = implode( "\n", array_map( static fn( array $check ): string => (string) ( $check['summary'] ?? '' ), $report['checks'] ) );
		self::assertStringContainsString( sprintf( '%d tools exposed', $declared - 3 ), $summaries );
		self::assertStringNotContainsString( sprintf( '%d tools exposed', $declared ), $summaries );
	}

	public function test_the_connection_test_counts_live_tools(): void {
		$declared = count( AbilityRegistry::mcp_server_ability_names() );
		$this->leave_unregistered( 'stonewright/ping' );

		$response = RestApi::handle_connection_test( new \WP_REST_Request( 'POST', '/stonewright/v1/connection-test' ) );
		$data     = $response->get_data();
		$details  = [];
		foreach ( (array) ( $data['checks'] ?? [] ) as $check ) {
			$details[ (string) $check['id'] ] = (string) $check['detail'];
		}

		self::assertSame( sprintf( '%d tools exposed in the current profile.', $declared - 1 ), $details['tool_surface'] );
	}

	public function test_the_dashboard_tool_surface_card_counts_live_tools(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				unset( $query, $output );
				return [];
			}

			public function get_var( string $query = '' ): string|int|null {
				unset( $query );
				return null;
			}

			public function get_col( string $query = '' ): array {
				unset( $query );
				return [];
			}
		};
		$declared = count( AbilityRegistry::mcp_server_ability_names() );
		$this->leave_unregistered( 'stonewright/ping', 'stonewright/site-info' );

		ob_start();
		StatusPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'#<span class="sw-ui-stat__label">Tool surface</span><span class="sw-ui-stat__value">' . ( $declared - 2 ) . '</span>#',
			$html
		);
	}

	public function test_the_abilities_page_counts_only_live_abilities_as_enabled(): void {
		$total = $this->leave_unregistered( 'stonewright/ping', 'stonewright/site-info' );

		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( sprintf( 'Enabled %d ', $total - 2 ), $html );
		self::assertStringNotContainsString( sprintf( 'Enabled %d ', $total ), $html );
		self::assertSame( 2, substr_count( $html, 'Not registered with WordPress' ) );
		self::assertMatchesRegularExpression( '#data-provider="stonewright".{0,400}?' . ( $total - 2 ) . ' of ' . $total . ' on#s', $html );
	}
}

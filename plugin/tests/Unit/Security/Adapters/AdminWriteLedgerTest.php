<?php
/**
 * Settings that the REST routes of the admin screens write are recorded as events, with names and hashes only.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Core\RestRoutes;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 * @covers \Stonewright\WpMcp\Security\Adapters\SiteAdapter
 */
final class AdminWriteLedgerTest extends FamilyLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_rest_routes'] = [];
		RestRoutes::register();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_rest_routes'] = [];
		parent::tearDown();
	}

	private function route_callback( string $route, string $method ): callable {
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $registered ) {
			if ( $route !== $registered['route'] ) {
				continue;
			}
			$args      = $registered['args'];
			$endpoints = array_is_list( $args ) && isset( $args[0] ) && is_array( $args[0] ) ? $args : [ $args ];
			foreach ( $endpoints as $endpoint ) {
				if ( ( $endpoint['methods'] ?? '' ) === $method && is_callable( $endpoint['callback'] ?? null ) ) {
					return $endpoint['callback'];
				}
			}
		}
		self::fail( 'No ' . $method . ' callback on ' . $route );
	}

	public function test_the_settings_route_records_a_changed_mode_and_the_names_of_the_flags_only(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';

		$this->route_callback( '/settings', 'POST' )(
			new \WP_REST_Request( 'POST', '/stonewright/v1/settings', [ 'mode' => 'staging', 'essential_tools_mode' => false, 'feature_flags' => [ 'new_dashboard' => true, 'beta_token' => 'sentinel-flag-value-4f2a' ] ] )
		);

		$rows = $this->ledger_rows();
		self::assertSame( [ 'stonewright_mode', 'stonewright_essential_tools_mode', 'stonewright_feature_flags' ], array_column( $rows, 'resource_id' ) );
		self::assertStringContainsString( 'from development to staging', $rows[0]['summary'] );
		self::assertStringContainsString( 'new_dashboard', $rows[2]['summary'] );
		self::assertSame( [ false, false, false ], array_column( $rows, 'restorable' ) );
		self::assertStringNotContainsString( 'sentinel-flag-value-4f2a', $this->ledger_text() );
	}

	public function test_the_settings_route_records_nothing_for_a_mode_that_did_not_change(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'staging';

		$this->route_callback( '/settings', 'POST' )( new \WP_REST_Request( 'POST', '/stonewright/v1/settings', [ 'mode' => 'staging' ] ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_the_instructions_route_records_the_length_and_a_hash_of_the_text_never_the_text(): void {
		$text = 'Always reply in plain words. sentinel-instruction-text-77c1';

		$this->route_callback( '/instructions', 'POST' )( new \WP_REST_Request( 'POST', '/stonewright/v1/instructions', [ 'text' => $text, 'enabled' => true ] ) );

		$rows = $this->ledger_rows();
		self::assertSame( [ 'stonewright_custom_instructions', 'stonewright_custom_instructions_enabled' ], array_column( $rows, 'resource_id' ) );
		self::assertStringContainsString( (string) strlen( $text ) . ' bytes', $rows[0]['summary'] );
		self::assertStringContainsString( substr( hash( 'sha256', $text ), 0, 12 ), $rows[0]['summary'] );
		self::assertStringNotContainsString( 'sentinel-instruction-text-77c1', $this->ledger_text() );
	}
}

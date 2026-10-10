<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;

/**
 * php-execute stays available for every snippet. When a snippet matches a common
 * pattern, the response also names the typed ability for it.
 *
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 * @covers \Stonewright\WpMcp\Support\ToolRouting
 */
final class PhpExecuteRoutingHintTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                 => 'development',
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	public function test_a_matching_snippet_still_runs_and_the_response_names_the_typed_tool(): void {
		$result = ( new PhpExecute() )->execute(
			[ 'code' => "update_option( 'blogname', 'Example Site' ); echo 'done'; return [ 'saved' => true ];" ]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'done', $result['stdout'] );
		self::assertSame( [ 'saved' => true ], $result['result'] );
		self::assertSame( 'Example Site', $GLOBALS['stonewright_test_options']['blogname'] ?? null, 'The snippet itself must still run.' );

		self::assertSame( [ 'options' => [ 'stonewright-settings-update' ] ], $result['routing_hint']['prefer'] );
		self::assertIsString( $result['routing_hint']['note'] );
		self::assertStringContainsString( 'not blocked', $result['routing_hint']['note'] );
	}

	public function test_a_snippet_without_a_common_pattern_gets_no_hint(): void {
		$result = ( new PhpExecute() )->execute( [ 'code' => "return get_bloginfo( 'name' );" ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( 'routing_hint', $result );
	}

	public function test_the_hint_never_repeats_the_snippet(): void {
		$result = ( new PhpExecute() )->execute(
			[ 'code' => "update_option( 'blogname', 'ZZ-MARKER-VALUE' ); update_post_meta( 9912345, 'zz_marker_key', 'ZZ-MARKER-VALUE' );" ]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'routing_hint', $result );
		$encoded = (string) wp_json_encode( $result['routing_hint'] );
		foreach ( [ 'ZZ', 'marker', '9912345', 'blogname' ] as $leak ) {
			self::assertStringNotContainsString( $leak, $encoded );
		}
	}

	public function test_a_read_only_inspection_gets_the_typed_reader(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code'      => "return get_option( 'timezone_string' );",
				'read_only' => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [ 'options' => [ 'stonewright-settings-get' ] ], $result['routing_hint']['prefer'] );
	}

	public function test_a_failing_snippet_returns_its_error_unchanged(): void {
		$result = ( new PhpExecute() )->execute(
			[ 'code' => "update_option( 'blogname', 'x' ); throw new \\RuntimeException( 'runtime failed' );" ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_execute_failed', $result->get_error_code() );
		self::assertArrayNotHasKey( 'routing_hint', (array) $result->get_error_data() );
	}

	public function test_the_hint_does_not_remove_an_existing_guard(): void {
		$result = ( new PhpExecute() )->execute(
			[ 'code' => "update_post_meta( 123, '_elementor_data', '[]' );" ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_elementor_raw_write_blocked', $result->get_error_code() );
	}

	public function test_the_output_schema_declares_the_optional_hint(): void {
		$schema = ( new PhpExecute() )->output_schema();

		self::assertSame( 'object', $schema['properties']['routing_hint']['type'] ?? null );
		self::assertNotContains( 'routing_hint', $schema['required'] );
	}
}

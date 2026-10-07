<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * @covers \Stonewright\WpMcp\Support\AgentNotices
 */
final class AgentNoticesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		AgentNotices::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		AgentNotices::reset_for_tests();
	}

	public function test_a_response_without_notices_is_left_exactly_as_it_was(): void {
		$result = [ 'ok' => true, 'name' => 'x' ];

		self::assertSame( $result, AgentNotices::attach( $result ) );
	}

	public function test_a_pushed_line_rides_on_responses_until_it_expires(): void {
		self::assertTrue( AgentNotices::push( 'section_reuse', 'section_reuse: off - do not offer section reuse', 900, 1000 ) );

		self::assertSame(
			[ 'ok' => true, 'notices' => [ 'section_reuse: off - do not offer section reuse' ] ],
			AgentNotices::attach( [ 'ok' => true ], 1899 )
		);
		self::assertSame( [ 'ok' => true ], AgentNotices::attach( [ 'ok' => true ], 1900 ), 'It stops at its expiry.' );
	}

	public function test_a_key_replaces_its_earlier_line_and_can_be_dismissed(): void {
		AgentNotices::push( 'k', 'first', 600, 1000 );
		AgentNotices::push( 'k', 'second', 600, 1000 );

		self::assertSame( [ 'second' ], AgentNotices::fields( 1001 )['notices'] );

		AgentNotices::dismiss( 'k' );
		self::assertSame( [], AgentNotices::fields( 1001 ) );
	}

	public function test_it_keeps_only_a_few_short_lines(): void {
		for ( $i = 1; $i <= 7; $i++ ) {
			AgentNotices::push( 'n' . $i, 'line ' . $i, 600, 1000 + $i );
		}
		AgentNotices::push( 'long', str_repeat( 'x', 500 ), 600, 1100 );

		$lines = AgentNotices::fields( 1101 )['notices'];
		self::assertCount( AgentNotices::MAX_NOTICES, $lines );
		self::assertNotContains( 'line 1', $lines );
		foreach ( $lines as $line ) {
			self::assertLessThanOrEqual( AgentNotices::MAX_LINE + 20, strlen( $line ) );
		}
	}

	public function test_a_line_that_looks_like_a_credential_is_refused(): void {
		self::assertFalse( AgentNotices::push( 'leak', 'token: Bearer abcdefghijklmnopqrstuvwxyz0123456789', 600, 1000 ) );
		self::assertFalse( AgentNotices::push( 'Bad Key!', 'ok', 600, 1000 ) );
		self::assertFalse( AgentNotices::push( 'empty', '   ', 600, 1000 ) );
		self::assertSame( [], AgentNotices::fields( 1001 ) );
	}

	public function test_the_lifetime_is_clamped(): void {
		AgentNotices::push( 'forever', 'still here', 999999999, 1000 );

		self::assertSame( [ 'still here' ], AgentNotices::fields( 1000 + AgentNotices::MAX_TTL - 1 )['notices'] );
		self::assertSame( [], AgentNotices::fields( 1000 + AgentNotices::MAX_TTL ) );
	}

	public function test_a_registered_field_is_computed_for_every_response(): void {
		AgentNotices::register_field( 'pending_incident', static fn (): ?array => [ 'id' => 'cs-1', 'ability' => 'stonewright/x', 'since' => '2026-01-01T00:00:00Z', 'rollback' => 'stonewright-rescue-rollback' ] );

		$result = AgentNotices::attach( [ 'ok' => true ] );

		self::assertSame( 'cs-1', $result['pending_incident']['id'] );
		self::assertSame( [ 'ok', 'pending_incident' ], array_keys( $result ) );
	}

	public function test_an_empty_or_failing_field_adds_nothing(): void {
		AgentNotices::register_field( 'none', static fn (): ?array => null );
		AgentNotices::register_field( 'boom', static function (): ?array {
			throw new \RuntimeException( 'provider failed' );
		} );

		self::assertSame( [ 'ok' => true ], AgentNotices::attach( [ 'ok' => true ] ) );
	}

	public function test_an_ability_keeps_a_field_it_already_returns(): void {
		AgentNotices::register_field( 'pending_incident', static fn (): ?array => [ 'id' => 'cs-1' ] );

		$result = AgentNotices::attach( [ 'ok' => true, 'pending_incident' => 'its own value' ] );

		self::assertSame( 'its own value', $result['pending_incident'] );
	}

	public function test_only_array_results_carry_notices(): void {
		AgentNotices::register_field( 'pending_incident', static fn (): ?array => [ 'id' => 'cs-1' ] );
		$error = new \WP_Error( 'x', 'failed' );

		self::assertSame( $error, AgentNotices::attach( $error ) );
		self::assertSame( 'text', AgentNotices::attach( 'text' ) );
		self::assertNull( AgentNotices::attach( null ) );
	}

	public function test_a_strict_output_schema_declares_the_optional_fields_so_they_cannot_break_it(): void {
		$strict = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [ 'ok' => [ 'type' => 'boolean' ] ],
			'required'             => [ 'ok' ],
		];

		$declared = AgentNotices::declare_in_schema( $strict );

		self::assertArrayHasKey( 'pending_incident', $declared['properties'] );
		self::assertArrayHasKey( 'notices', $declared['properties'] );
		self::assertSame( [ 'ok' ], $declared['required'], 'The notice fields stay optional.' );
		self::assertSame( [ 'ok' ], array_keys( $strict['properties'] ), 'The input is not modified.' );
	}

	public function test_a_schema_that_already_allows_extra_fields_is_left_alone(): void {
		$open = [
			'type'       => 'object',
			'properties' => [ 'ok' => [ 'type' => 'boolean' ] ],
		];
		$explicit = [ 'type' => 'object', 'additionalProperties' => true, 'properties' => [ 'ok' => [ 'type' => 'boolean' ] ] ];

		self::assertSame( $open, AgentNotices::declare_in_schema( $open ) );
		self::assertSame( $explicit, AgentNotices::declare_in_schema( $explicit ) );
	}

	public function test_a_field_the_schema_already_declares_is_not_overwritten(): void {
		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [ 'notices' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ],
		];

		self::assertSame( [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ], AgentNotices::declare_in_schema( $schema )['properties']['notices'] );
	}
}

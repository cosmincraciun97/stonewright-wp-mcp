<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ResponseProjection;

/**
 * Reads are the bulk of an agent's token spend, and most of a read is payload the
 * agent will never look at. Projection lets a caller name the parts it needs.
 *
 * Two properties matter more than the trimming itself: the envelope must survive
 * so callers can still tell success from failure, and an unrecognised path must
 * be dropped silently rather than turned into an error — a projection is a hint
 * about payload size, not a contract the caller can fail.
 *
 * @covers \Stonewright\WpMcp\Support\ResponseProjection
 */
final class ResponseProjectionTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function payload(): array {
		return [
			'ok'       => true,
			'hash'     => 'abc123',
			'post_id'  => 41,
			'meta'     => [
				'title'    => 'Home',
				'template' => 'elementor_canvas',
				'counts'   => [
					'widgets'    => 12,
					'containers' => 4,
				],
			],
			'elements' => [
				[
					'id'       => 'a1',
					'type'     => 'container',
					'settings' => [ 'padding' => '48px' ],
				],
				[
					'id'       => 'b2',
					'type'     => 'widget',
					'settings' => [ 'padding' => '0px' ],
				],
			],
		];
	}

	public function test_no_fields_returns_the_payload_untouched(): void {
		self::assertSame( self::payload(), ResponseProjection::apply( self::payload(), null ) );
		self::assertSame( self::payload(), ResponseProjection::apply( self::payload(), [] ) );
		self::assertSame( self::payload(), ResponseProjection::apply( self::payload(), '' ) );
	}

	public function test_top_level_projection_keeps_the_envelope(): void {
		self::assertSame(
			[
				'ok'      => true,
				'hash'    => 'abc123',
				'post_id' => 41,
			],
			ResponseProjection::apply( self::payload(), [ 'hash', 'post_id' ] )
		);
	}

	public function test_nested_projection_prunes_siblings(): void {
		self::assertSame(
			[
				'ok'   => true,
				'meta' => [ 'counts' => [ 'widgets' => 12 ] ],
			],
			ResponseProjection::apply( self::payload(), [ 'meta.counts.widgets' ] )
		);
	}

	public function test_list_projection_applies_to_every_member(): void {
		self::assertSame(
			[
				'ok'       => true,
				'elements' => [
					[ 'id' => 'a1' ],
					[ 'id' => 'b2' ],
				],
			],
			ResponseProjection::apply( self::payload(), [ 'elements.id' ] )
		);
	}

	public function test_sibling_paths_merge_into_one_subtree(): void {
		// Two paths through the same list must produce one list of two-key items,
		// not two lists appended to each other.
		self::assertSame(
			[
				'ok'       => true,
				'elements' => [
					[
						'id'   => 'a1',
						'type' => 'container',
					],
					[
						'id'   => 'b2',
						'type' => 'widget',
					],
				],
			],
			ResponseProjection::apply( self::payload(), [ 'elements.id', 'elements.type' ] )
		);
	}

	public function test_unknown_paths_are_dropped_without_error(): void {
		self::assertSame(
			[
				'ok'   => true,
				'hash' => 'abc123',
			],
			ResponseProjection::apply( self::payload(), [ 'hash', 'nope', 'meta.nope', 'elements.nope', 'hash.deeper' ] )
		);
	}

	public function test_a_projection_that_matches_nothing_still_returns_the_envelope(): void {
		// Silently returning an empty array would read as a failed call. The
		// envelope is the one thing a caller can never project away.
		self::assertSame( [ 'ok' => true ], ResponseProjection::apply( self::payload(), [ 'nothing.here' ] ) );
	}

	public function test_comma_separated_strings_are_accepted(): void {
		self::assertSame(
			ResponseProjection::apply( self::payload(), [ 'hash', 'post_id' ] ),
			ResponseProjection::apply( self::payload(), ' hash , post_id , ' )
		);
	}

	public function test_projection_is_idempotent(): void {
		$once  = ResponseProjection::apply( self::payload(), [ 'elements.id', 'meta.title' ] );
		$twice = ResponseProjection::apply( $once, [ 'elements.id', 'meta.title' ] );

		self::assertSame( $once, $twice );
	}

	public function test_malformed_paths_are_ignored_rather_than_trusted(): void {
		// Field names come straight off the wire. Anything outside the safe
		// character set is dropped instead of being walked into the payload.
		self::assertSame(
			[
				'ok'   => true,
				'hash' => 'abc123',
			],
			ResponseProjection::apply( self::payload(), [ 'hash', 'meta[title]', '../../etc', 'meta..title', str_repeat( 'a.', 40 ) . 'z' ] )
		);
	}

	public function test_lists_stay_lists_when_some_members_do_not_match(): void {
		$payload = [
			'ok'    => true,
			'items' => [
				[ 'id' => 1 ],
				[ 'other' => 2 ],
				[ 'id' => 3 ],
			],
		];

		$projected = ResponseProjection::apply( $payload, [ 'items.id' ] );

		self::assertSame(
			[
				'ok'    => true,
				'items' => [
					[ 'id' => 1 ],
					[ 'id' => 3 ],
				],
			],
			$projected
		);
		self::assertSame( [ 0, 1 ], array_keys( $projected['items'] ) );
	}

	public function test_schema_property_declares_an_optional_string_or_list(): void {
		$property = ResponseProjection::schema_property();

		self::assertSame( [ 'string', 'array' ], $property['type'] );
		self::assertNotSame( '', (string) $property['description'] );
	}
}

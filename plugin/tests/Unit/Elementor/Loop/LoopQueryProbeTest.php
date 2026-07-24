<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Loop\LoopQueryProbe;

/**
 * @covers \Stonewright\WpMcp\Elementor\Loop\LoopQueryProbe
 */
final class LoopQueryProbeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_types'] = [
			'project' => (object) [ 'name' => 'project', 'public' => true ],
		];
		$GLOBALS['stonewright_test_search_posts'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_post_types']  = [];
		$GLOBALS['stonewright_test_search_posts'] = [];
	}

	public function test_empty_query_warns_by_default_and_blocks_when_required(): void {
		$warning = LoopQueryProbe::probe( 'project', [ 'posts_per_page' => 6 ], false );

		self::assertIsArray( $warning );
		self::assertSame( 0, $warning['found'] );
		self::assertContains( 'query_returned_no_results', $warning['warnings'] );

		$blocked = LoopQueryProbe::probe( 'project', [ 'posts_per_page' => 6 ], true );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_loop_query_empty', $blocked->get_error_code() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $blocked->get_error_data()['query_hash'] );
		self::assertArrayNotHasKey( 'query', $blocked->get_error_data() );
	}

	public function test_probe_returns_bounded_ids_and_rejects_unknown_query_keys(): void {
		$GLOBALS['stonewright_test_search_posts'] = array_map(
			static fn( int $id ): object => (object) [ 'ID' => $id ],
			range( 1, 30 )
		);
		$result = LoopQueryProbe::probe( 'project', [ 'posts_per_page' => 50 ], false );

		self::assertIsArray( $result );
		self::assertSame( 30, $result['found'] );
		self::assertCount( 20, $result['sampled_ids'] );

		$invalid = LoopQueryProbe::probe( 'project', [ 'suppress_filters' => true ], false );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'stonewright_loop_query_key_invalid', $invalid->get_error_code() );
	}

	public function test_unregistered_post_type_fails_before_query(): void {
		$result = LoopQueryProbe::probe( 'missing', [], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_post_type_invalid', $result->get_error_code() );
	}
}

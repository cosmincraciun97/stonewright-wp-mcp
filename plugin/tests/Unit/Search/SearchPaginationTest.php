<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Search\SearchQuery;

/**
 * @covers \Stonewright\WpMcp\Abilities\Search\SearchQuery
 */
final class SearchPaginationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_user_can_callback']  = null;
		$GLOBALS['stonewright_test_user_caps']          = [ 'read' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_search_posts']       = [
			(object) [ 'ID' => 11, 'post_title' => 'One', 'post_type' => 'post', 'post_status' => 'publish', 'post_modified' => '2026-01-01' ],
		];
		$GLOBALS['stonewright_test_search_found_posts'] = 23;
		unset( $GLOBALS['stonewright_test_last_wp_query_args'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_search_found_posts'], $GLOBALS['stonewright_test_last_wp_query_args'] );
		$GLOBALS['stonewright_test_search_posts'] = [];
	}

	public function test_the_schema_offers_a_page_argument(): void {
		$page = ( new SearchQuery() )->input_schema()['properties']['page'];

		self::assertSame( 'integer', $page['type'] );
		self::assertSame( 1, $page['minimum'] );
		self::assertSame( 1, $page['default'] );
	}

	public function test_a_later_page_is_requested_from_the_query_and_reported(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x', 'per_page' => 5, 'page' => 3 ] );

		self::assertIsArray( $result );
		self::assertSame( 3, $GLOBALS['stonewright_test_last_wp_query_args']['paged'] );
		self::assertSame( 5, $GLOBALS['stonewright_test_last_wp_query_args']['posts_per_page'] );
		self::assertSame( 3, $result['page'] );
		self::assertSame( 5, $result['per_page'] );
		self::assertSame( 23, $result['total'] );
		self::assertSame( 5, $result['total_pages'] );
	}

	public function test_the_first_page_is_the_default(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x' ] );

		self::assertSame( 1, $GLOBALS['stonewright_test_last_wp_query_args']['paged'] );
		self::assertSame( 1, $result['page'] );
		self::assertSame( 20, $result['per_page'] );
		self::assertSame( 2, $result['total_pages'] );
	}

	public function test_page_and_page_size_are_capped(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x', 'per_page' => 500, 'page' => 0 ] );

		self::assertSame( 50, $GLOBALS['stonewright_test_last_wp_query_args']['posts_per_page'] );
		self::assertSame( 1, $GLOBALS['stonewright_test_last_wp_query_args']['paged'] );
		self::assertSame( 1, $result['page'] );
	}
}

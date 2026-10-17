<?php
/**
 * search-query needs edit_posts and lists a post type the caller cannot edit only when the type is public.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Search\SearchQuery;

/**
 * @covers \Stonewright\WpMcp\Abilities\Search\SearchQuery
 */
final class SearchQueryAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_caps']         = [ 'read' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_post_types']        = [
			'post'        => (object) [
				'name'               => 'post',
				'public'             => true,
				'publicly_queryable' => true,
				'_builtin'           => true,
				'cap'                => (object) [ 'edit_posts' => 'edit_posts' ],
			],
			// A public type its owner plugin gates with its own capability.
			'product'     => (object) [
				'name'               => 'product',
				'public'             => true,
				'publicly_queryable' => true,
				'_builtin'           => false,
				'cap'                => (object) [ 'edit_posts' => 'edit_products' ],
			],
			// Never shown on the site: the entries are private data.
			'shop_coupon' => (object) [
				'name'               => 'shop_coupon',
				'public'             => false,
				'publicly_queryable' => false,
				'_builtin'           => false,
				'cap'                => (object) [ 'edit_posts' => 'edit_shop_coupons' ],
			],
		];
		$GLOBALS['stonewright_test_search_posts']       = [
			(object) [ 'ID' => 11, 'post_title' => 'SUMMER-50', 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_modified' => '2026-01-01' ],
		];
		$GLOBALS['stonewright_test_search_found_posts'] = 1;
		unset( $GLOBALS['stonewright_test_last_wp_query_args'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_search_found_posts'], $GLOBALS['stonewright_test_last_wp_query_args'], $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['stonewright_test_search_posts'] = [];
		$GLOBALS['stonewright_test_post_types']   = [];
		$GLOBALS['stonewright_test_user_caps']    = [];
	}

	/** @param list<string> $caps */
	private function user_with( array $caps ): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys( $caps, true );
	}

	public function test_a_user_who_can_only_read_is_refused(): void {
		$this->user_with( [ 'read' ] );

		self::assertNotTrue( ( new SearchQuery() )->permission_callback( [ 'search' => 'x' ] ) );
	}

	public function test_a_user_who_can_edit_posts_may_search(): void {
		self::assertTrue( ( new SearchQuery() )->permission_callback( [ 'search' => 'x' ] ) );
	}

	public function test_a_type_the_caller_cannot_edit_is_refused_unless_it_is_public(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'SUMMER', 'post_type' => 'shop_coupon' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_search_post_type_not_allowed', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertArrayNotHasKey( 'stonewright_test_last_wp_query_args', $GLOBALS, 'The query never ran.' );
	}

	public function test_an_unknown_type_is_refused(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x', 'post_type' => 'no_such_type' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_search_post_type_not_allowed', $result->get_error_code() );
	}

	public function test_a_role_that_can_edit_the_type_may_list_it(): void {
		$this->user_with( [ 'read', 'edit_posts', 'edit_shop_coupons' ] );

		$result = ( new SearchQuery() )->execute( [ 'search' => 'SUMMER', 'post_type' => 'shop_coupon' ] );

		self::assertIsArray( $result );
		self::assertSame( 'shop_coupon', $GLOBALS['stonewright_test_last_wp_query_args']['post_type'] );
		self::assertSame( 'SUMMER-50', $result['items'][0]['title'] );
	}

	public function test_a_public_type_is_listed_for_a_caller_who_cannot_edit_it(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x', 'post_type' => 'product' ] );

		self::assertIsArray( $result );
		self::assertSame( 'product', $GLOBALS['stonewright_test_last_wp_query_args']['post_type'] );
	}

	public function test_posts_and_the_default_search_across_types_are_unchanged(): void {
		self::assertIsArray( ( new SearchQuery() )->execute( [ 'search' => 'x', 'post_type' => 'post' ] ) );
		self::assertSame( 'post', $GLOBALS['stonewright_test_last_wp_query_args']['post_type'] );

		self::assertIsArray( ( new SearchQuery() )->execute( [ 'search' => 'x' ] ) );
		self::assertSame( 'any', $GLOBALS['stonewright_test_last_wp_query_args']['post_type'] );

		self::assertIsArray( ( new SearchQuery() )->execute( [ 'search' => 'x', 'post_type' => 'any' ] ) );
		self::assertSame( 'any', $GLOBALS['stonewright_test_last_wp_query_args']['post_type'] );
	}

	public function test_an_unpublished_post_the_caller_cannot_edit_is_not_listed(): void {
		$GLOBALS['stonewright_test_search_posts'] = [
			(object) [ 'ID' => 21, 'post_title' => 'Own draft', 'post_type' => 'post', 'post_status' => 'draft', 'post_modified' => '2026-01-01' ],
			(object) [ 'ID' => 22, 'post_title' => 'Another draft', 'post_type' => 'post', 'post_status' => 'draft', 'post_modified' => '2026-01-01' ],
			(object) [ 'ID' => 23, 'post_title' => 'Live', 'post_type' => 'post', 'post_status' => 'publish', 'post_modified' => '2026-01-01' ],
		];
		$GLOBALS['stonewright_test_search_found_posts'] = 3;
		$GLOBALS['stonewright_test_user_can_callback']  = static fn ( string $cap, mixed ...$args ): bool => match ( $cap ) {
			'read', 'edit_posts' => true,
			'edit_post'          => 21 === (int) ( $args[0] ?? 0 ),
			default              => false,
		};

		$result = ( new SearchQuery() )->execute( [ 'search' => 'x', 'post_type' => 'post' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 21, 23 ], array_column( $result['items'], 'id' ) );
	}
}

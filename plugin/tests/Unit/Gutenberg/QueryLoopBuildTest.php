<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\QueryLoopBuild;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\QueryLoopBuild
 */
final class QueryLoopBuildTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_posts' => true,
			'edit_post'  => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_audit_rows']      = [];
		$GLOBALS['stonewright_test_post_types']      = [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
			'page' => (object) [ 'name' => 'page', 'label' => 'Pages', 'public' => true ],
		];
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/query'         => (object) [ 'title' => 'Query Loop', 'category' => 'theme' ],
			'core/post-template' => (object) [ 'title' => 'Post Template', 'category' => 'theme' ],
			'core/post-title'    => (object) [ 'title' => 'Post Title', 'category' => 'theme' ],
			'core/post-excerpt'  => (object) [ 'title' => 'Excerpt', 'category' => 'theme' ],
			'core/post-date'     => (object) [ 'title' => 'Post Date', 'category' => 'theme' ],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
	}

	public function test_ability_name_is_stable(): void {
		self::assertSame( 'stonewright/blocks-query-loop-build', ( new QueryLoopBuild() )->name() );
	}

	public function test_builds_core_query_and_post_template_spec(): void {
		$result = ( new QueryLoopBuild() )->execute(
			[
				'post_type' => 'post',
				'count'     => 4,
				'order'     => 'desc',
				'inherit'   => false,
				'taxonomy'  => [
					'taxonomy' => 'category',
					'terms'    => [ 'news' ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'core/query', $result['block']['name'] );
		self::assertSame( 'post', $result['block']['attrs']['query']['postType'] );
		self::assertSame( 4, $result['block']['attrs']['query']['perPage'] );
		self::assertSame( 'desc', $result['block']['attrs']['query']['order'] );
		self::assertFalse( $result['block']['attrs']['query']['inherit'] );
		self::assertSame( 'core/post-template', $result['block']['innerBlocks'][0]['name'] );
		self::assertContains( 'core/post-title', array_column( $result['block']['innerBlocks'][0]['innerBlocks'], 'name' ) );
	}

	public function test_refuses_unregistered_inner_blocks(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks']['core/post-title'] );

		$result = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post', 'count' => 3 ] );

		self::assertIsArray( $result );
		$inner_names = array_column( $result['block']['innerBlocks'][0]['innerBlocks'], 'name' );
		self::assertNotContains( 'core/post-title', $inner_names );
		self::assertContains( 'core/post-excerpt', $inner_names );
	}

	public function test_refuses_unregistered_query_block(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks']['core/query'] );

		$result = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_block_unregistered', $result->get_error_code() );
	}

	public function test_refuses_unknown_post_type(): void {
		$result = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'not-a-type' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_post_type_unregistered', $result->get_error_code() );
	}

	public function test_schema_is_closed(): void {
		$schema = ( new QueryLoopBuild() )->input_schema();
		self::assertFalse( $schema['additionalProperties'] );
		self::assertArrayHasKey( 'post_type', $schema['properties'] );
		self::assertArrayHasKey( 'taxonomy', $schema['properties'] );
		self::assertArrayHasKey( 'count', $schema['properties'] );
		self::assertArrayHasKey( 'order', $schema['properties'] );
		self::assertArrayHasKey( 'inherit', $schema['properties'] );
	}
}

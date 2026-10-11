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
		unset( $GLOBALS['stonewright_test_registered_blocks'], $GLOBALS['stonewright_test_taxonomies'], $GLOBALS['stonewright_test_terms'], $GLOBALS['stonewright_test_taxonomy_objects'] );
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_posts']          = [];
	}

	public function test_ability_name_is_stable(): void {
		self::assertSame( 'stonewright/blocks-query-loop-build', ( new QueryLoopBuild() )->name() );
	}

	public function test_builds_core_query_and_post_template_spec(): void {
		$GLOBALS['stonewright_test_taxonomies']['category']      = true;
		$GLOBALS['stonewright_test_terms']['category']['news']   = (object) [ 'term_id' => 17, 'name' => 'News', 'slug' => 'news' ];
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
		self::assertIsArray( $result['block']['attributes'] );
		self::assertSame( 'post', $result['block']['attributes']['query']['postType'] );
		self::assertSame( 4, $result['block']['attributes']['query']['perPage'] );
		self::assertSame( 'desc', $result['block']['attributes']['query']['order'] );
		self::assertFalse( $result['block']['attributes']['query']['inherit'] );
		self::assertSame( [ 'category' => [ 17 ] ], $result['block']['attributes']['query']['taxQuery'], 'taxQuery holds term ids, not slugs.' );
		self::assertSame( 'core/post-template', $result['block']['innerBlocks'][0]['name'] );
		self::assertContains( 'core/post-title', array_column( $result['block']['innerBlocks'][0]['innerBlocks'], 'name' ) );
		foreach ( $result['block']['innerBlocks'][0]['innerBlocks'] as $child ) {
			self::assertArrayHasKey( 'attributes', $child );
			self::assertSame( '{}', wp_json_encode( $child['attributes'] ) );
		}
		self::assertSame( '{}', wp_json_encode( $result['block']['innerBlocks'][0]['attributes'] ) );
		self::assertArrayNotHasKey( 'attrs', $result['block'] );
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

	public function test_build_output_is_directly_insertable(): void {
		$GLOBALS['stonewright_test_posts'] = [
			21 => (object) [
				'ID'           => 21,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Query loop target',
				'post_content' => '<!-- wp:paragraph --><p>Keep</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_registered_blocks']['core/query']         = (object) [
			'attributes'      => [ 'query' => [ 'type' => 'object' ] ],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];
		$GLOBALS['stonewright_test_registered_blocks']['core/post-template'] = (object) [
			'attributes'      => [],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];
		$GLOBALS['stonewright_test_registered_blocks']['core/post-title']    = (object) [
			'attributes'      => [],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];
		$GLOBALS['stonewright_test_registered_blocks']['core/post-excerpt']  = (object) [
			'attributes'      => [],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];
		$GLOBALS['stonewright_test_registered_blocks']['core/post-date']     = (object) [
			'attributes'      => [],
			'render_callback' => static fn(): string => '',
			'is_dynamic'      => true,
		];

		$built = ( new QueryLoopBuild() )->execute(
			[
				'post_type' => 'post',
				'count'     => 3,
			]
		);
		self::assertIsArray( $built );

		$inserted = ( new \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock() )->execute(
			[
				'post_id' => 21,
				'block'   => $built['block'],
			]
		);
		self::assertIsArray( $inserted );
		self::assertArrayNotHasKey( 'queued', $inserted );
		$content = (string) $GLOBALS['stonewright_test_posts'][21]->post_content;
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?query/', $content );
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?post-template/', $content );
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?post-title/', $content );
		self::assertDoesNotMatchRegularExpression( '/wp:(?:core\/)?query\s+\/>/', $content );
	}

	public function test_refuses_a_term_slug_that_does_not_exist(): void {
		$GLOBALS['stonewright_test_taxonomies']['category']    = true;
		$GLOBALS['stonewright_test_terms']['category']['news'] = (object) [ 'term_id' => 17, 'name' => 'News', 'slug' => 'news' ];

		$result = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post', 'taxonomy' => [ 'taxonomy' => 'category', 'terms' => [ 'news', 'nope' ] ] ] );

		self::assertInstanceOf( \WP_Error::class,$result );
		self::assertSame( 'stonewright_term_not_found', $result->get_error_code() );
		self::assertSame( [ 'nope' ], $result->get_error_data()['missing_terms'] );
	}

	public function test_refuses_a_taxonomy_that_is_unknown_or_not_for_the_post_type(): void {
		$unknown = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post', 'taxonomy' => [ 'taxonomy' => 'nope', 'terms' => [ 'a' ] ] ] );
		self::assertInstanceOf( \WP_Error::class,$unknown );
		self::assertSame( 'stonewright_taxonomy_invalid', $unknown->get_error_code() );

		$GLOBALS['stonewright_test_taxonomies']['genre']        = true;
		$GLOBALS['stonewright_test_taxonomy_objects']['genre']  = [ 'book' ];
		$GLOBALS['stonewright_test_terms']['genre']['noir']     = (object) [ 'term_id' => 5, 'name' => 'Noir', 'slug' => 'noir' ];
		$wrong_type = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post', 'taxonomy' => [ 'taxonomy' => 'genre', 'terms' => [ 'noir' ] ] ] );
		self::assertInstanceOf( \WP_Error::class,$wrong_type );
		self::assertSame( 'stonewright_taxonomy_invalid', $wrong_type->get_error_code() );
		unset( $GLOBALS['stonewright_test_taxonomy_objects'] );
	}

	public function test_refuses_terms_without_a_taxonomy(): void {
		$result = ( new QueryLoopBuild() )->execute( [ 'post_type' => 'post', 'taxonomy' => [ 'terms' => [ 'news' ] ] ] );

		self::assertInstanceOf( \WP_Error::class,$result );
		self::assertSame( 'stonewright_taxonomy_invalid', $result->get_error_code() );
	}

	public function test_schema_is_closed(): void {
		$schema = ( new QueryLoopBuild() )->input_schema();
		self::assertFalse( $schema['additionalProperties'] );
		self::assertArrayHasKey( 'post_type', $schema['properties'] );
		self::assertArrayHasKey( 'taxonomy', $schema['properties'] );
		self::assertArrayHasKey( 'count', $schema['properties'] );
		self::assertArrayHasKey( 'order', $schema['properties'] );
		self::assertArrayHasKey( 'inherit', $schema['properties'] );
		self::assertArrayNotHasKey( 'post_id', $schema['properties'], 'The builder only builds; blocks-insert writes.' );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock
 */
final class InsertBlockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_posts']           = [
			12 => (object) [
				'ID'           => 12,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Insert target',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_posts']           = [];
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	public function test_production_safe_mode_requires_confirmation_token(): void {
		$args = [
			'post_id' => 12,
			'block'   => [ 'name' => 'core/paragraph' ],
		];

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$blocked = ( new InsertBlock() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/blocks-insert', $args );
		$result = ( new InsertBlock() )->execute( $args );
		self::assertFalse(
			$result instanceof \WP_Error && 'stonewright_confirmation_required' === $result->get_error_code()
		);

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$dev = ( new InsertBlock() )->execute(
			[
				'post_id' => 12,
				'block'   => [ 'name' => 'core/paragraph' ],
			]
		);
		self::assertFalse(
			$dev instanceof \WP_Error && 'stonewright_confirmation_required' === $dev->get_error_code()
		);
	}

	public function test_insert_persists_nested_inner_blocks_for_dynamic_query_tree(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/query'         => (object) [
				'attributes'      => [ 'query' => [ 'type' => 'object' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/post-template' => (object) [
				'attributes'      => [],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/post-title'    => (object) [
				'attributes'      => [],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
		];

		$result = ( new InsertBlock() )->execute(
			[
				'post_id' => 12,
				'block'   => [
					'name'        => 'core/query',
					'attributes'  => [
						'query' => [
							'perPage'  => 3,
							'postType' => 'post',
							'inherit'  => false,
						],
					],
					'innerBlocks' => [
						[
							'name'        => 'core/post-template',
							'attributes'  => [],
							'innerBlocks' => [
								[
									'name'        => 'core/post-title',
									'attributes'  => [],
									'innerBlocks' => [],
								],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'queued', $result );
		$content = (string) $GLOBALS['stonewright_test_posts'][12]->post_content;
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?query/', $content );
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?post-template/', $content );
		self::assertMatchesRegularExpression( '/wp:(?:core\/)?post-title/', $content );
		self::assertDoesNotMatchRegularExpression( '/wp:(?:core\/)?query\s+\/>/', $content );
	}

	public function test_insert_queues_whole_tree_when_a_static_child_needs_the_finalizer(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/query'     => (object) [
				'attributes'      => [ 'query' => [ 'type' => 'object' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/paragraph' => (object) [
				'attributes' => [ 'content' => [ 'type' => 'string' ] ],
			],
		];
		$before = (string) $GLOBALS['stonewright_test_posts'][12]->post_content;

		$result = ( new InsertBlock() )->execute(
			[
				'post_id' => 12,
				'block'   => [
					'name'        => 'core/query',
					'attributes'  => [ 'query' => [ 'perPage' => 1, 'inherit' => false ] ],
					'innerBlocks' => [
						[
							'name'        => 'core/paragraph',
							'attributes'  => [ 'content' => 'Keep with parent' ],
							'innerBlocks' => [],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][12]->post_content );
		$stored = \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue::get( (string) $result['change_id'] );
		self::assertIsArray( $stored );
		self::assertSame( 'core/query', $stored['block_spec']['name'] );
		self::assertSame( 'core/paragraph', $stored['block_spec']['innerBlocks'][0]['name'] );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

/**
 * blocks-update on a block that only the browser finalizer can write.
 *
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock
 */
final class UpdateBlockFinalizerTest extends TestCase {

	private const POST    = 12;
	private const CONTENT = '<!-- wp:vendor/alpha {"title":"Old"} --><div>old</div><!-- /wp:vendor/alpha -->';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'vendor/alpha' => (object) [ 'attributes' => [ 'title' => [ 'type' => 'string' ] ] ],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => self::CONTENT,
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

	private function queued_for_post(): int {
		$count = 0;
		foreach ( BlockQueue::list() as $item ) {
			if ( self::POST === (int) $item['post_id'] ) {
				++$count;
			}
		}
		return $count;
	}

	public function test_attrs_and_inner_html_together_are_refused_for_a_finalizer_only_block(): void {
		$before = $this->queued_for_post();

		$result = ( new UpdateBlock() )->execute(
			[
				'post_id'   => self::POST,
				'path'      => [ 0 ],
				'attrs'     => [ 'title' => 'New' ],
				'innerHTML' => '<div>new</div>',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_finalizer_inner_html_unsupported', $result->get_error_code() );
		self::assertSame( $before, $this->queued_for_post(), 'Nothing was queued.' );
		self::assertSame( self::CONTENT, $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content );
	}

	public function test_attrs_alone_still_queue_for_the_finalizer(): void {
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 0 ], 'attrs' => [ 'title' => 'New' ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertTrue( $result['queued'] );
	}
}

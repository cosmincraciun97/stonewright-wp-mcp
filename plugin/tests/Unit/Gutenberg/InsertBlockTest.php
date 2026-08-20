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
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
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
}

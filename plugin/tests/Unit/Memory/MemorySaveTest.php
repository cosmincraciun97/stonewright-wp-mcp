<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Memory\MemorySave;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Memory\MemorySave
 */
final class MemorySaveTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_production_safe_mode_requires_confirmation_token(): void {
		$args = [
			'type'  => 'generic',
			'scope' => 'project',
			'key'   => 'example-key',
			'name'  => 'Example',
		];

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$blocked = ( new MemorySave() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/memory-save', $args );
		$result = ( new MemorySave() )->execute( $args );
		self::assertFalse(
			$result instanceof \WP_Error && 'stonewright_confirmation_required' === $result->get_error_code()
		);

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$dev = ( new MemorySave() )->execute(
			[
				'type'  => 'generic',
				'scope' => 'project',
				'key'   => 'example-key-dev',
				'name'  => 'Example Dev',
			]
		);
		self::assertFalse(
			$dev instanceof \WP_Error && 'stonewright_confirmation_required' === $dev->get_error_code()
		);
	}
}

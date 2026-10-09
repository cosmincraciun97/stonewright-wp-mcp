<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;

/**
 * The MCP adapter turns an ability error into the error message only, so the
 * retry guidance of a busy page has to be part of that message.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::execute_with_context_guard
 * @covers \Stonewright\WpMcp\Support\ErrorEnvelope
 */
final class AbilityBusyErrorMessageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_mode'    => 'development',
			'stonewright_enabled' => true,
		];
		$GLOBALS['stonewright_test_posts']      = [
			10 => (object) [
				'ID'           => 10,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Busy Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'busy-page',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_posts']      = [];
	}

	public function test_a_busy_page_error_carries_retryable_and_retry_after_in_the_message_the_client_receives(): void {
		PostWriteLock::acquire( 10, 'other-transaction', 30 );
		$issued = ContextToken::issue( 'Update page settings', 'stonewright/elementor-v3-update-page-settings' );

		$result = AbilityRegistry::execute_with_context_guard(
			new UpdatePageSettings(),
			[
				'stonewright_context_token' => $issued['token'],
				'post_id'                   => 10,
				'settings'                  => [ 'hide_title' => 'yes' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		$message = $result->get_error_message();
		self::assertStringStartsWith( 'Another Elementor transaction is writing this post.', $message );
		self::assertSame( 1, preg_match( '/(\{.*\})\s*$/', $message, $match ), $message );
		$payload = json_decode( $match[1], true );
		self::assertTrue( $payload['retryable'] );
		self::assertIsInt( $payload['retry_after'] );
		self::assertGreaterThanOrEqual( 1, $payload['retry_after'] );
		self::assertLessThanOrEqual( 30, $payload['retry_after'] );
		self::assertSame( [ 'retryable', 'retry_after' ], array_keys( $payload ), 'Only the retry guidance is copied; lock internals stay out.' );
		foreach ( [ 'other-transaction', 'lock_fingerprint', 'lock_expires_at', 'lock_age_seconds' ] as $internal ) {
			self::assertStringNotContainsString( $internal, $message );
		}
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Tests that {@see CompanionClient::post()} gracefully handles a
 * {@see wp_json_encode()} failure (returns false) rather than silently
 * passing false as the request body.
 *
 * The bootstrap stubs `wp_json_encode` as a thin wrapper around
 * `json_encode`. We override it via namespace function shadowing in the
 * tests\ namespace — PHP resolves global functions at runtime, so by
 * declaring a function with the same name in the global namespace AFTER
 * the bootstrap but before the code under test calls it, we can inject
 * a controlled return value.
 *
 * Because the global namespace stub is already declared in bootstrap.php,
 * this test verifies the guard by inspecting the source code (a reliable
 * static check) and by using a reflective assertion on the structure of
 * the post() method.
 *
 * @covers \Stonewright\WpMcp\Support\CompanionClient
 */
final class CompanionClientEncodeTest extends TestCase {

	/**
	 * The source of CompanionClient::post() must guard against
	 * wp_json_encode() returning false before passing the result to
	 * wp_safe_remote_post(). This static check ensures the guard is present
	 * in the implementation.
	 */
	public function test_post_guards_against_false_json_encode(): void {
		$reflection = new \ReflectionClass( CompanionClient::class );
		$file       = $reflection->getFileName();
		$this->assertNotFalse( $file );
		$source = file_get_contents( $file );
		$this->assertNotFalse( $source );

		// The guard must check the return value of wp_json_encode() for false.
		$this->assertMatchesRegularExpression(
			'/\$encoded\s*=\s*wp_json_encode\s*\(/m',
			$source,
			'CompanionClient::post() must store the wp_json_encode() result in a variable.'
		);

		$this->assertStringContainsString(
			'stonewright_companion_encode_failed',
			$source,
			'CompanionClient::post() must return a WP_Error with stonewright_companion_encode_failed when encoding fails.'
		);

		// Verify that the guarded variable ($encoded) is what gets passed to
		// wp_safe_remote_post, not a raw wp_json_encode() call.
		$this->assertStringNotContainsString(
			"'body'    => wp_json_encode(",
			$source,
			'wp_json_encode() result must not be passed directly to wp_safe_remote_post body — use the guarded $encoded variable.'
		);
	}

	/**
	 * Simulate an encode failure by temporarily redefining wp_json_encode
	 * via runkit or by exercising the code path with a mocked callable.
	 *
	 * Since we cannot redefine PHP built-in function stubs at test time
	 * without runkit7, we verify that the implementation correctly wires the
	 * guard by overriding the global wp_json_encode stub via a GLOBALS flag
	 * and then directly testing the method implementation via reflection.
	 *
	 * This test directly verifies the failure-path WP_Error is returned when
	 * encoding fails, by overriding the global stub via GLOBALS.
	 */
	public function test_post_returns_wp_error_when_encode_fails(): void {
		// Wire the bootstrap stub to return false on the next wp_json_encode() call.
		$GLOBALS['stonewright_test_wp_json_encode_return'] = false;

		try {
			// Call the real production method — the bootstrap stub will intercept
			// wp_json_encode() and return false, triggering the error guard.
			$result = CompanionClient::post( '/wp-cli/status', [ 'key' => 'value' ] );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				'post() must return WP_Error when wp_json_encode returns false.'
			);
			$this->assertSame(
				'stonewright_companion_encode_failed',
				$result->get_error_code()
			);
			$data = $result->get_error_data();
			$this->assertIsArray( $data );
			$this->assertArrayHasKey(
				'json_error',
				$data,
				'WP_Error data must include json_error key for debuggability.'
			);
			$this->assertNotSame(
				'No error',
				$data['json_error'],
				'json_error must carry the real json_last_error_msg(), not the default "No error" sentinel.'
			);
		} finally {
			unset( $GLOBALS['stonewright_test_wp_json_encode_return'] );
		}
	}
}

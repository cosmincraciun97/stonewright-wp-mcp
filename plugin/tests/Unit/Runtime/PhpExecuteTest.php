<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 */
final class PhpExecuteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_php_execute_is_registered_and_visible_in_essential_mode(): void {
		$registered = array_map(
			static fn( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);
		$visible = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/php-execute', $registered );
		self::assertContains( 'stonewright/php-execute', $visible );
	}

	public function test_php_execute_requires_admin_capability(): void {
		$ability = new PhpExecute();

		self::assertTrue( $ability->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;

		self::assertFalse( $ability->permission_callback( [] ) );
	}

	public function test_executes_php_in_wordpress_context_and_captures_stdout(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'echo get_bloginfo("name"); return ["sum" => 2 + 3];',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'Stonewright Test', $result['stdout'] );
		self::assertSame( [ 'sum' => 5 ], $result['result'] );
		self::assertGreaterThanOrEqual( 0, $result['elapsed_ms'] );
		self::assertArrayHasKey( 'memory_delta_bytes', $result );
	}

	public function test_throwable_becomes_wp_error(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'throw new \RuntimeException("runtime failed");',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_execute_failed', $result->get_error_code() );
		self::assertStringContainsString( 'runtime failed', $result->get_error_message() );
		self::assertSame( 500, $result->get_error_data()['status'] ?? null );
	}

	public function test_code_is_redacted_from_audit_log(): void {
		$secret_code = 'return "secret runtime source";';

		$result = ( new PhpExecute() )->execute(
			[
				'code' => $secret_code,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );

		$inserts = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => 'stonewright/php-execute' === ( $insert['data']['ability_name'] ?? null )
			)
		);
		self::assertNotEmpty( $inserts );

		$args = json_decode( (string) ( $inserts[0]['data']['sanitized_args'] ?? '{}' ), true );
		self::assertIsArray( $args );
		self::assertArrayHasKey( 'code', $args );
		self::assertStringContainsString( '[redacted', (string) $args['code'] );
		self::assertStringNotContainsString( $secret_code, (string) ( $inserts[0]['data']['sanitized_args'] ?? '' ) );
	}
}

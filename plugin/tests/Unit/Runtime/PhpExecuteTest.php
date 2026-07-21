<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\ConfirmationToken;

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
			'stonewright_mode' => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
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
		self::assertFalse( $result['stdout_truncated'] );
		self::assertFalse( $result['result_truncated'] );
	}

	public function test_production_safe_mode_requires_matching_confirmation_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args = [ 'code' => 'return 42;' ];

		$blocked = ( new PhpExecute() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/php-execute', $args );
		$result = ( new PhpExecute() )->execute( $args );
		self::assertIsArray( $result );
		self::assertSame( 42, $result['result'] );
	}

	public function test_large_stdout_and_results_are_bounded(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code'             => 'echo str_repeat("x", 2048); return str_repeat("y", 2048);',
				'max_output_bytes' => 1024,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1024, strlen( $result['stdout'] ) );
		self::assertTrue( $result['stdout_truncated'] );
		self::assertTrue( $result['result_truncated'] );
		self::assertStringStartsWith( '[truncated result', $result['result'] );
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

	public function test_blocks_raw_elementor_meta_writes_but_allows_reads(): void {
		$blocked = ( new PhpExecute() )->execute(
			[
				'code' => 'update_post_meta(8170, "_elementor_data", "[]"); return true;',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_php_elementor_raw_write_blocked', $blocked->get_error_code() );
		self::assertFalse( $blocked->get_error_data()['retryable'] );
		self::assertTrue( $blocked->get_error_data()['do_not_retry_php_execute'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $blocked->get_error_data()['next_call']['ability'] );
		self::assertSame( 'dry_run', $blocked->get_error_data()['next_call']['mode'] );

		$read = ( new PhpExecute() )->execute(
			[
				'code' => 'return get_post_meta(8170, "_elementor_data", true);',
			]
		);
		self::assertIsArray( $read );
		self::assertTrue( $read['ok'] );
	}

	public function test_read_only_flag_blocks_mutation_apis(): void {
		$blocked = ( new PhpExecute() )->execute(
			[
				'code'      => 'update_option("blogname", "x"); return true;',
				'read_only' => true,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_php_read_only_violation', $blocked->get_error_code() );

		$ok = ( new PhpExecute() )->execute(
			[
				'code'      => 'return get_option("blogname");',
				'read_only' => true,
			]
		);
		self::assertIsArray( $ok );
		self::assertTrue( $ok['ok'] );
	}

	public function test_blocks_direct_elementor_data_helper_bypass(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'return \\Stonewright\\WpMcp\\Support\\ElementorData::write(8170, []);',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_elementor_raw_write_blocked', $result->get_error_code() );
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
		self::assertSame( hash( 'sha256', $secret_code ), $args['_meta']['code_sha256'] ?? null );
		self::assertArrayHasKey( 'duration_ms', $args['_meta'] ?? [] );
	}
}

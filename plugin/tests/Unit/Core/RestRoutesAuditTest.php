<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Core\RestRoutes;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class RestRoutesAuditTest extends TestCase {

	public function test_finalizer_heartbeat_is_not_a_mutation_audit_event_but_result_is(): void {
		$method = new ReflectionMethod( RestRoutes::class, 'is_stonewright_mutation' );

		$heartbeat = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/heartbeat' );
		$result = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result' );

		self::assertFalse( $method->invoke( null, $heartbeat ) );
		self::assertTrue( $method->invoke( null, $result ) );
	}

	public function test_free_form_rest_bodies_are_hashed_before_auditing(): void {
		$params = [
			'name'       => 'runtime-helper.php',
			'contents'   => '<?php $password = "do-not-log-me";',
			'old_string' => 'token=old-secret',
			'new_string' => 'token=new-secret',
			'nested'     => [
				'text' => 'token=secret-inside-instructions',
				'mode' => 'development',
			],
		];

		$method  = new ReflectionMethod( RestRoutes::class, 'compact_audit_params' );
		$summary = $method->invoke( null, $params );
		$encoded = wp_json_encode( $summary );

		self::assertIsArray( $summary );
		self::assertSame( 'runtime-helper.php', $summary['name'] );
		self::assertSame( 'development', $summary['nested']['mode'] );
		self::assertTrue( $summary['contents']['redacted'] );
		self::assertSame( strlen( $params['contents'] ), $summary['contents']['bytes'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $summary['contents']['sha256'] );
		self::assertStringNotContainsString( 'do-not-log-me', (string) $encoded );
		self::assertStringNotContainsString( 'old-secret', (string) $encoded );
		self::assertStringNotContainsString( 'new-secret', (string) $encoded );
		self::assertStringNotContainsString( 'secret-inside-instructions', (string) $encoded );
	}
}

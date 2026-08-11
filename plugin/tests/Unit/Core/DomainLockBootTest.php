<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Boot-time domain lock must never rewrite operator enablement intent.
 *
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::check_domain_lock
 */
final class DomainLockBootTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled' => true,
		];
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$GLOBALS['stonewright_test_actions']  = [];
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_home_url'] );
	}

	public function test_check_domain_lock_on_mismatch_keeps_enabled_requested(): void {
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://cloned.example/';

		// Construct registration without full boot.
		$ref  = new \ReflectionClass( PluginRegistration::class );
		$ctor = $ref->getConstructor();
		self::assertNotNull( $ctor );
		$instance = $ref->newInstanceWithoutConstructor();
		$ctor->invoke( $instance, __FILE__ );

		$instance->check_domain_lock();

		self::assertTrue( PluginEffectiveState::enabled_requested() );
		self::assertTrue( (bool) get_option( 'stonewright_enabled', false ) );
		self::assertFalse( DomainLock::check() );
		self::assertNotNull( DomainLock::mismatch() );
		self::assertSame(
			PluginEffectiveState::STATE_BLOCKED_DOMAIN_MISMATCH,
			PluginEffectiveState::effective_state()
		);
	}
}

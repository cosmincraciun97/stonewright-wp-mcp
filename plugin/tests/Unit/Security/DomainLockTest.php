<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\DomainLock;

/**
 * @covers \Stonewright\WpMcp\Security\DomainLock
 */
final class DomainLockTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'stonewright_locked_domain' );
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
	}

	protected function tearDown(): void {
		delete_option( 'stonewright_locked_domain' );
		unset( $GLOBALS['stonewright_test_home_url'] );
	}

	public function test_lock_stores_current_domain(): void {
		DomainLock::lock();
		$this->assertSame( 'https://example.test/', DomainLock::locked_domain() );
	}

	public function test_lock_is_idempotent(): void {
		DomainLock::lock();
		// Simulate domain change — lock() should NOT overwrite.
		$GLOBALS['stonewright_test_home_url'] = 'https://new.test/';
		DomainLock::lock();
		$this->assertSame( 'https://example.test/', DomainLock::locked_domain() );
	}

	public function test_check_returns_true_when_not_locked(): void {
		$this->assertTrue( DomainLock::check() );
	}

	public function test_check_returns_true_when_domain_matches(): void {
		DomainLock::lock();
		$this->assertTrue( DomainLock::check() );
	}

	public function test_check_returns_false_when_domain_changed(): void {
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://cloned.test/';
		$this->assertFalse( DomainLock::check() );
	}

	public function test_reset_clears_lock(): void {
		DomainLock::lock();
		DomainLock::reset();
		$this->assertSame( '', DomainLock::locked_domain() );
		$this->assertTrue( DomainLock::check() );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * @covers \Stonewright\WpMcp\Security\DomainLock
 * @covers \Stonewright\WpMcp\Security\PluginEffectiveState
 */
final class DomainLockTest extends TestCase {

	protected function setUp(): void {
		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		delete_option( 'stonewright_domain_lock_prior' );
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled' => true,
		];
		$GLOBALS['stonewright_test_home_url']  = 'https://example.test/';
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		delete_option( 'stonewright_domain_lock_prior' );
		unset( $GLOBALS['stonewright_test_home_url'] );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_lock_stores_current_domain(): void {
		DomainLock::lock();
		$this->assertSame( 'https://example.test/', DomainLock::locked_domain() );
	}

	public function test_lock_is_idempotent(): void {
		DomainLock::lock();
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

	/**
	 * @dataProvider origin_normalization_provider
	 */
	public function test_normalize_origin_matrix( string $input, string $expected ): void {
		$this->assertSame( $expected, DomainLock::normalize_origin( $input ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function origin_normalization_provider(): array {
		return [
			'trailing_slash_root'   => [ 'https://example.com', 'https://example.com/' ],
			'trailing_slash_kept'   => [ 'https://example.com/', 'https://example.com/' ],
			'hostname_case'         => [ 'https://Example.COM/', 'https://example.com/' ],
			'default_https_port'    => [ 'https://example.com:443/', 'https://example.com/' ],
			'default_http_port'     => [ 'http://example.com:80/', 'http://example.com/' ],
			'non_default_port'      => [ 'https://example.com:8443/', 'https://example.com:8443/' ],
			'subdirectory'          => [ 'https://example.com/wp', 'https://example.com/wp/' ],
			'subdirectory_slash'    => [ 'https://example.com/wp/', 'https://example.com/wp/' ],
			'http_to_https_scheme'  => [ 'http://example.com/', 'http://example.com/' ],
		];
	}

	public function test_trailing_slash_equivalence(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$this->assertTrue( DomainLock::check() );
	}

	public function test_hostname_case_equivalence(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://Example.Test/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$this->assertTrue( DomainLock::check() );
	}

	public function test_default_ports_equivalence(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test:443/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$this->assertTrue( DomainLock::check() );
	}

	public function test_http_to_https_is_mismatch(): void {
		$GLOBALS['stonewright_test_home_url'] = 'http://example.test/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$this->assertFalse( DomainLock::check() );
	}

	public function test_root_to_subdirectory_is_mismatch(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/blog/';
		$this->assertFalse( DomainLock::check() );
	}

	public function test_subdirectory_preserved_on_match(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/shop/';
		DomainLock::lock();
		$this->assertSame( 'https://example.test/shop/', DomainLock::locked_domain() );
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/shop';
		$this->assertTrue( DomainLock::check() );
	}

	public function test_reverse_proxy_uses_configured_home_not_request_host(): void {
		// home_url is the source of truth; changing only "request host" is not modeled.
		$GLOBALS['stonewright_test_home_url'] = 'https://canonical.example/';
		DomainLock::lock();
		// If WP home stays canonical, check still passes.
		$this->assertTrue( DomainLock::check() );
		$GLOBALS['stonewright_test_home_url'] = 'https://edge-proxy.example/';
		$this->assertFalse( DomainLock::check() );
	}

	public function test_staging_clone_mismatch(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://prod.example/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://staging.example/';
		$this->assertFalse( DomainLock::check() );
	}

	public function test_domain_migration_mismatch_until_rebind(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://old.example/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://new.example/';
		$this->assertFalse( DomainLock::check() );
		$result = DomainLock::rebind();
		$this->assertTrue( $result );
		$this->assertTrue( DomainLock::check() );
		$this->assertSame( 'https://new.example/', DomainLock::locked_domain() );
	}

	public function test_mismatch_never_mutates_operator_intent(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://cloned.example/';
		$this->assertFalse( DomainLock::check() );
		DomainLock::record_mismatch();
		$this->assertTrue( PluginEffectiveState::enabled_requested() );
		$this->assertSame(
			PluginEffectiveState::STATE_BLOCKED_DOMAIN_MISMATCH,
			PluginEffectiveState::effective_state()
		);
		$this->assertFalse( PluginEffectiveState::is_effectively_enabled() );
		$this->assertTrue( (bool) get_option( 'stonewright_enabled', false ) );
	}

	public function test_explicit_operator_off_remains_off_on_mismatch(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://other.example/';
		DomainLock::record_mismatch();
		$this->assertFalse( PluginEffectiveState::enabled_requested() );
		$this->assertSame(
			PluginEffectiveState::STATE_DISABLED_BY_OPERATOR,
			PluginEffectiveState::effective_state()
		);
	}

	public function test_rebind_snapshots_prior_and_rollback_restores(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://first.example/';
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://second.example/';
		DomainLock::rebind();
		$this->assertTrue( DomainLock::can_rollback() );
		$this->assertSame( 'https://second.example/', DomainLock::locked_domain() );

		// After rebind, live origin matches; rollback restores first (which then mismatches).
		$result = DomainLock::rollback();
		$this->assertTrue( $result );
		$this->assertSame( 'https://first.example/', DomainLock::locked_domain() );
		$this->assertFalse( DomainLock::check() );
	}

	public function test_redact_origin_strips_to_stable_display(): void {
		$this->assertSame(
			'https://example.com/path/',
			DomainLock::redact_origin( 'https://Example.COM/path' )
		);
	}

	public function test_enablement_writers_contract(): void {
		$this->assertContains(
			'Stonewright\\WpMcp\\Admin\\AdminBarIndicator::apply_toggle',
			PluginEffectiveState::ENABLEMENT_WRITERS
		);
		$this->assertContains(
			'Stonewright\\WpMcp\\Admin\\ConfigurationPage::register_settings',
			PluginEffectiveState::ENABLEMENT_WRITERS
		);
		// Domain lock must not be listed as an enablement writer.
		foreach ( PluginEffectiveState::ENABLEMENT_WRITERS as $writer ) {
			$this->assertStringNotContainsString( 'DomainLock', $writer );
			$this->assertStringNotContainsString( 'PluginRegistration', $writer );
		}
	}
}

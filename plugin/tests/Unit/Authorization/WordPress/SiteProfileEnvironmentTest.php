<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\SiteProfile;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The environment type a site profile decides its transport policy with, read back
 * so admin screens can explain why OAuth is unavailable.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\SiteProfile::environment
 */
final class SiteProfileEnvironmentTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		unset( $GLOBALS['stonewright_test_environment_type'] );
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $GLOBALS['stonewright_test_environment_type'] );
	}

	public function test_a_profile_reports_the_environment_it_was_built_with(): void {
		self::assertSame( 'production', HttpRig::site( true, 'production' )->environment() );
		self::assertSame( 'local', HttpRig::site( true, 'local', 'pretty', 'http://example.test' )->environment() );
	}

	public function test_the_running_site_reports_the_wordpress_environment_type(): void {
		$GLOBALS['stonewright_test_environment_type'] = 'staging';

		self::assertSame( 'staging', SiteProfile::wordpress()->environment() );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Connect;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Connect\SignInStatus;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * Whether this site offers OAuth sign-in, why not, and the addresses clients need.
 *
 * @covers \Stonewright\WpMcp\Admin\Connect\SignInStatus
 */
final class SignInStatusTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		StorageRig::reset_globals();
	}

	private static function reasons( array $status ): string {
		return implode( "\n", array_merge( array_column( $status['issues'], 'reason' ), array_column( $status['issues'], 'remedy' ) ) );
	}

	public function test_an_enabled_https_site_offers_sign_in_with_its_addresses(): void {
		$status = SignInStatus::describe( HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );

		self::assertTrue( $status['available'] );
		self::assertTrue( $status['enabled'] );
		self::assertTrue( $status['transport_allowed'] );
		self::assertTrue( $status['secure'] );
		self::assertSame( 'https', $status['transport'] );
		self::assertSame( 'HTTPS', $status['transport_label'] );
		self::assertSame( [], $status['issues'] );
		self::assertSame( 'https://example.com/wp-json/mcp/stonewright-oauth', $status['mcp_url'] );
		self::assertSame( 'https://example.com/.well-known/oauth-protected-resource', $status['discovery_url'] );
		self::assertSame( 'https://example.test/wp-json/mcp/stonewright', $status['password_url'] );
		self::assertSame( 'stonewright-example-test', $status['server_name'] );
		self::assertSame( 'example.com', $status['host'] );
		self::assertFalse( $status['local'] );
		self::assertSame( '', $status['local_reason'] );
		self::assertFalse( $status['loopback'] );
	}

	public function test_current_reads_the_running_site_profile(): void {
		HttpSurface::use_site( HttpRig::site( true, 'production', 'plain', 'https://example.com' ) );

		self::assertSame( 'https://example.com/index.php?rest_route=/mcp/stonewright-oauth', SignInStatus::current()['mcp_url'] );
	}

	public function test_a_public_plain_http_site_explains_the_refusal_and_the_fix(): void {
		$status = SignInStatus::describe( HttpRig::site( true, 'production', 'pretty', 'http://example.com' ) );

		self::assertFalse( $status['available'] );
		self::assertFalse( $status['transport_allowed'] );
		self::assertFalse( $status['secure'] );
		self::assertSame( 'refused', $status['transport'] );
		self::assertCount( 1, $status['issues'] );
		self::assertStringContainsString( 'plain HTTP', $status['issues'][0]['reason'] );
		self::assertStringContainsString( 'production', $status['issues'][0]['reason'] );
		self::assertStringContainsString( 'HTTPS', $status['issues'][0]['remedy'] );
		self::assertStringContainsString( 'WP_ENVIRONMENT_TYPE', $status['issues'][0]['remedy'] );
	}

	public function test_a_local_installation_may_use_plain_http(): void {
		$status = SignInStatus::describe( HttpRig::site( true, 'local', 'pretty', 'http://site-a.test' ) );

		self::assertTrue( $status['available'] );
		self::assertSame( 'local-http', $status['transport'] );
		self::assertStringContainsString( 'local', $status['transport_label'] );
		self::assertTrue( $status['local'] );
		self::assertNotSame( '', $status['local_reason'] );
	}

	public function test_development_sites_may_use_plain_http_only_at_a_loopback_address(): void {
		$loopback = SignInStatus::describe( HttpRig::site( true, 'development', 'pretty', 'http://localhost:8080' ) );
		self::assertTrue( $loopback['available'] );
		self::assertSame( 'loopback-http', $loopback['transport'] );
		self::assertTrue( $loopback['loopback'] );
		self::assertTrue( $loopback['local'] );

		$network = SignInStatus::describe( HttpRig::site( true, 'development', 'pretty', 'http://192.168.1.20' ) );
		self::assertFalse( $network['available'] );
		self::assertSame( 'refused', $network['transport'] );
		self::assertStringContainsString( '192.168.1.20', self::reasons( $network ) );
		self::assertStringContainsString( 'localhost', self::reasons( $network ) );
		self::assertTrue( $network['local'] );
	}

	public function test_a_turned_off_plugin_says_where_to_turn_it_on(): void {
		$status = SignInStatus::describe( HttpRig::site( false, 'production', 'pretty', 'https://example.com' ) );

		self::assertFalse( $status['available'] );
		self::assertFalse( $status['enabled'] );
		self::assertTrue( $status['transport_allowed'] );
		self::assertSame( 'https', $status['transport'] );
		self::assertCount( 1, $status['issues'] );
		self::assertStringContainsString( 'turned off', $status['issues'][0]['reason'] );
		self::assertStringContainsString( 'step 1', $status['issues'][0]['remedy'] );
	}

	public function test_every_reason_is_reported_when_several_apply(): void {
		$status = SignInStatus::describe( HttpRig::site( false, 'staging', 'pretty', 'http://example.com' ) );

		self::assertCount( 2, $status['issues'] );
		self::assertStringContainsString( 'staging', self::reasons( $status ) );
	}

	/** @dataProvider hosts */
	public function test_local_looking_sites_are_recognised( string $home, bool $local ): void {
		self::assertSame( $local, SignInStatus::describe( HttpRig::site( true, 'production', 'pretty', $home ) )['local'], $home );
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function hosts(): array {
		return [
			'public name'       => [ 'https://example.com', false ],
			'public address'    => [ 'https://93.184.216.34', false ],
			'loopback name'     => [ 'https://localhost', true ],
			'loopback address'  => [ 'https://127.0.0.1', true ],
			'private address'   => [ 'https://10.0.0.5', true ],
			'reserved suffix'   => [ 'https://site-a.test', true ],
			'mdns suffix'       => [ 'https://site-a.local', true ],
			'single label host' => [ 'https://intranet', true ],
		];
	}
}

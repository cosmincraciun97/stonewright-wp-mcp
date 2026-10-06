<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\PermissionSubjectAuthority;
use Stonewright\WpMcp\Authorization\WordPress\RandomIdentifiers;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\SystemClock;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\PermissionSubjectAuthority
 * @covers \Stonewright\WpMcp\Authorization\WordPress\SystemClock
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RandomIdentifiers
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RowKeys
 * @covers \Stonewright\WpMcp\Security\Permissions::user_can_use_mcp
 */
final class SubjectAndRuntimeAdaptersTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function denied( string $subject ): void {
		try {
			( new PermissionSubjectAuthority() )->require_allowed( $subject, 'refresh', [] );
			self::fail( 'The subject must be refused.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'access_denied', $fault->error() );
			self::assertSame( 403, $fault->status() );
		}
	}

	public function test_an_existing_user_with_the_mcp_capability_is_allowed(): void {
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];

		( new PermissionSubjectAuthority() )->require_allowed( '7', 'refresh', [ 'client_key' => 'c' ] );

		self::assertTrue( Permissions::user_can_use_mcp( 7 ) );
	}

	public function test_missing_users_users_without_the_capability_and_malformed_subjects_are_refused(): void {
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ], 8 => [ 'read' => false ] ];
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 9 ];

		$this->denied( '8' );
		$this->denied( '9' );
		foreach ( [ '', '0', '-7', '7a', ' 7', '07', '99999999999999999999999' ] as $subject ) {
			$this->denied( $subject );
		}
		self::assertFalse( Permissions::user_can_use_mcp( 0 ) );
		self::assertFalse( Permissions::user_can_use_mcp( 9 ) );
	}

	public function test_clock_and_identifiers_use_the_runtime(): void {
		$before = time();
		$now = ( new SystemClock() )->now();
		self::assertGreaterThanOrEqual( $before, $now );
		self::assertLessThanOrEqual( time(), $now );

		$identifiers = new RandomIdentifiers();
		$first = $identifiers->next();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{80}$/D', $first );
		self::assertNotSame( $first, $identifiers->next() );
		self::assertFalse( RowKeys::is_earlier( $first ), 'new keys never look like identifiers written by the earlier version' );
	}

	public function test_row_keys_follow_the_documented_derivations(): void {
		$jti = str_repeat( 'a', 80 );
		$earlier = str_repeat( 'b', 64 );

		self::assertSame( hash( 'sha256', $jti ), RowKeys::access( $jti ) );
		self::assertSame( $earlier, RowKeys::refresh( $earlier ) );
		self::assertSame( $earlier, RowKeys::grant_family( $earlier ) );
		self::assertSame( hash( 'sha256', 'stonewright-oauth:refresh:' . $jti ), RowKeys::refresh( $jti ) );
		self::assertSame( hash( 'sha256', 'stonewright-oauth:family:' . $jti ), RowKeys::grant_family( $jti ) );
		self::assertSame( hash( 'sha256', 'stonewright-oauth:code:' . $jti ), RowKeys::code( $jti ) );
		self::assertSame( hash( 'sha256', 'stonewright-oauth:consent:' . $jti ), RowKeys::consent( $jti ) );
		self::assertTrue( RowKeys::is_earlier( $earlier ) );
		self::assertFalse( RowKeys::is_earlier( strtoupper( $earlier ) ) );
	}

	public function test_the_registering_address_is_stored_as_a_keyed_hash(): void {
		$address = RowKeys::address( '192.0.2.10' );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $address );
		self::assertNotSame( hash( 'sha256', '192.0.2.10' ), $address, 'A plain digest of an IPv4 address is undone by trying every address.' );
		self::assertSame( $address, RowKeys::address( '192.0.2.10' ), 'The same address always gives the same value.' );
		self::assertNotSame( $address, RowKeys::address( '192.0.2.11' ) );
		self::assertNotSame( $address, RowKeys::address( '' ), 'An unknown address is not the value of a known one.' );
		$key = hash_hmac( 'sha256', 'stonewright-oauth/registration-address/v1', wp_salt( 'auth' ), true );
		self::assertSame( hash_hmac( 'sha256', '192.0.2.10', $key ), $address, 'HMAC-SHA256 under a key computed from the authentication salt.' );
	}
}

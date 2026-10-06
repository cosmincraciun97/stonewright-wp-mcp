<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\AccessTokenValidator;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\PermissionSubjectAuthority;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AccessTokenValidator
 */
final class AccessTokenValidatorTest extends TestCase {

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function validator(): AccessTokenValidator {
		return new AccessTokenValidator( $this->rig->codec, $this->rig->access, new PermissionSubjectAuthority(), $this->rig->clock );
	}

	private function invalid( string $bearer ): void {
		try {
			$this->validator()->validate( $bearer );
			self::fail( 'The bearer credential must be refused.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_token', $fault->error() );
			self::assertSame( 401, $fault->status() );
		}
	}

	public function test_a_current_access_token_is_accepted(): void {
		[ $client, $pair, $outcome ] = $this->rig->connect();

		$this->rig->at( StorageRig::T + 3599 );
		$result = $this->validator()->validate( $pair->access_token );

		self::assertSame( '7', $result['subject_key'] );
		self::assertSame( $client, $result['client_key'] );
		self::assertSame( [ 'mcp' ], $result['scopes'] );
		self::assertSame( $outcome->issuance->access_key, $result['credential_key'] );
		self::assertSame( $outcome->issuance->family_key, $result['family_key'] );
		self::assertSame( StorageRig::T + 3600, $result['expires_at'] );
	}

	public function test_an_access_token_from_the_earlier_version_is_accepted(): void {
		$legacy = new LegacyRows( $this->rig->space, StorageRig::T );
		$legacy->client();
		$family = $legacy->family( 'f', 0, null, StorageRig::T - 100 );
		$token = LegacyRows::access_token( $family['jtis'][0], StorageRig::private_key(), StorageRig::T - 100 );

		$result = $this->validator()->validate( $token );

		self::assertSame( LegacyRows::CLIENT, $result['client_key'] );
		self::assertSame( $family['family_hash'], $result['family_key'] );
	}

	public function test_expired_revoked_or_unknown_tokens_are_refused(): void {
		[ , $pair, $outcome ] = $this->rig->connect();

		$this->rig->at( StorageRig::T + 3600 );
		$this->invalid( $pair->access_token );

		$this->rig->at( StorageRig::T + 10 );
		$this->rig->access->revoke( $outcome->issuance->access_key );
		$this->invalid( $pair->access_token );

		$this->rig->db->execute( 'DELETE FROM ' . $this->rig->db->table( 'access_tokens' ) );
		$this->invalid( $pair->access_token );
		$this->invalid( 'not-a-token' );
	}

	public function test_a_token_for_another_audience_or_signed_elsewhere_is_refused(): void {
		[ , , $outcome ] = $this->rig->connect();
		[ $other_key ] = CredentialKeys::generate_rsa( CredentialKeys::default_configurations() );

		$this->invalid( LegacyRows::access_token( $outcome->issuance->access_key, StorageRig::private_key(), StorageRig::T, [ 'aud' => 'https://example.test/wp-json/mcp/stonewright' ] ) );
		$this->invalid( LegacyRows::access_token( $outcome->issuance->access_key, (string) $other_key, StorageRig::T ) );
		$this->invalid( LegacyRows::access_token( $outcome->issuance->access_key, StorageRig::private_key(), StorageRig::T, [ 'nbf' => StorageRig::T + 120 ] ) );
	}

	public function test_a_subject_that_was_deleted_or_lost_the_capability_is_refused(): void {
		[ , $pair ] = $this->rig->connect();

		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => false ] ];
		$this->invalid( $pair->access_token );

		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 7 ];
		$this->invalid( $pair->access_token );
	}

	public function test_a_refresh_credential_is_not_a_bearer_token(): void {
		[ , $pair ] = $this->rig->connect();

		$this->invalid( $pair->refresh_token );
	}
}

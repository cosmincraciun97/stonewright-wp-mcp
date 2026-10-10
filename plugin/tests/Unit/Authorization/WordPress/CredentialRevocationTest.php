<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\CredentialRevocation;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\CredentialRevocation
 */
final class CredentialRevocationTest extends TestCase {

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function revocation(): CredentialRevocation {
		return new CredentialRevocation( $this->rig->codec, $this->rig->families, $this->rig->access );
	}

	private function phase( string $family_key ): string {
		return (string) $this->rig->row( 'families', 'family_hash', RowKeys::grant_family( $family_key ) )['phase'];
	}

	public function test_revoking_a_refresh_credential_closes_the_whole_family(): void {
		[ $client, $pair, $outcome ] = $this->rig->connect();
		$rotated = $this->rig->at( StorageRig::T + 100 )->refresh( $pair->refresh_token, $client );
		$current = $this->rig->codec->encode( $rotated->issuance );

		self::assertTrue( $this->revocation()->revoke( $current->refresh_token, $client ) );

		self::assertSame( 'revoked', $this->phase( $outcome->issuance->family_key ) );
		foreach ( $this->rig->rows( 'access_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}
		self::assertSame( 'invalid_grant', $this->rig->refresh( $current->refresh_token, $client )->fault->error() );
	}

	public function test_revoking_an_access_token_closes_its_family(): void {
		[ $client, $pair, $outcome ] = $this->rig->connect();

		self::assertTrue( $this->revocation()->revoke( $pair->access_token, null ) );

		self::assertSame( 'revoked', $this->phase( $outcome->issuance->family_key ) );
		self::assertSame( '1', $this->rig->row( 'refresh_tokens', 'identifier_hash', RowKeys::refresh( $outcome->issuance->refresh_key ) )['revoked'] );
		self::assertTrue( $this->revocation()->revoke( $pair->access_token, $client ), 'repeating is harmless' );
	}

	public function test_a_mismatched_client_changes_nothing(): void {
		[ , $pair ] = $this->rig->connect();
		$before = $this->rig->space->tables;

		self::assertFalse( $this->revocation()->revoke( $pair->refresh_token, 'ffffffffffffffffffffffffffffffff' ) );
		self::assertFalse( $this->revocation()->revoke( 'unknown-token', null ) );
		self::assertFalse( $this->revocation()->revoke( '', null ) );
		self::assertSame( $before, $this->rig->space->tables );
	}

	public function test_codes_are_not_revocation_targets(): void {
		$client = $this->rig->register_client();
		$code = $this->rig->authorize( $client );

		self::assertFalse( $this->revocation()->revoke( $code, $client ) );
	}

	public function test_an_earlier_refresh_credential_revokes_its_family(): void {
		$legacy = new LegacyRows( $this->rig->space, StorageRig::T );
		$legacy->client();
		$family = $legacy->family( 'f', 1 );

		self::assertTrue( $this->revocation()->revoke( LegacyRows::refresh_payload( $family, 1, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ), LegacyRows::CLIENT ) );

		self::assertSame( 'revoked', $this->rig->row( 'families', 'family_hash', $family['family_hash'] )['phase'] );
		self::assertSame( '1', $this->rig->row( 'refresh_tokens', 'identifier_hash', $family['rows'][1] )['revoked'] );
		self::assertSame( '1', $this->rig->row( 'access_tokens', 'identifier_hash', hash( 'sha256', $family['jtis'][1] ) )['revoked'] );
	}
}

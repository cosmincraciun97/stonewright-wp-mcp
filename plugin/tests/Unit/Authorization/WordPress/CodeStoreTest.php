<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\StorageFailure;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\CodeStore
 */
final class CodeStoreTest extends TestCase {

	private StorageRig $rig;
	private string $client;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
		$this->client = $this->rig->register_client();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function code_key( string $code ): string {
		return $this->rig->codec->inspect( $code )->credential_key;
	}

	private function code_row( string $code ): array {
		return (array) $this->rig->row( 'auth_codes', 'identifier_hash', RowKeys::code( $this->code_key( $code ) ) );
	}

	public function test_approval_stores_the_code_row(): void {
		$code = $this->rig->authorize( $this->client );

		$row = $this->code_row( $code );
		self::assertSame( $this->client, $row['client_id'] );
		self::assertSame( '7', $row['user_id'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 60 ), $row['expires_at'] );
		self::assertSame( '["mcp"]', $row['scopes'] );
		self::assertSame( StorageRig::REDIRECT, $row['redirect_uri'] );
		self::assertSame( '0', $row['revoked'] );
		self::assertSame( ( new CodeProof() )->challenge( StorageRig::VERIFIER ), $row['code_challenge'] );
		self::assertSame( '["https://example.test/wp-json/mcp/stonewright-oauth"]', $row['resources'] );
		self::assertNull( $row['family_key'] );
	}

	public function test_exchange_consumes_the_code_and_creates_the_family_together(): void {
		$code = $this->rig->authorize( $this->client );

		$outcome = $this->rig->at( StorageRig::T + 10 )->exchange( $code, $this->client );

		self::assertNull( $outcome->fault );
		$row = $this->code_row( $code );
		self::assertSame( '1', $row['revoked'] );
		self::assertSame( $outcome->family->to_array()['family_key'], $row['family_key'] );
		$family = (array) $this->rig->row( 'families', 'family_key', $outcome->issuance->family_key );
		self::assertSame( RowKeys::grant_family( $outcome->issuance->family_key ), $family['family_hash'] );
		self::assertSame( $this->client, $family['client_id'] );
		self::assertSame( '7', $family['user_id'] );
		self::assertSame( '["mcp"]', $family['scopes'] );
		self::assertSame( 'active', $family['phase'] );
		self::assertSame( '0', $family['revision'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 10 + RefreshPolicy::FAMILY_LIFETIME ), $family['family_expires_at'] );
		$refresh = (array) $this->rig->row( 'refresh_tokens', 'identifier_hash', RowKeys::refresh( $outcome->issuance->refresh_key ) );
		self::assertNull( $refresh['parent_identifier_hash'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 10 + RefreshPolicy::REFRESH_IDLE_LIFETIME ), $refresh['expires_at'] );
		self::assertSame( $family['family_hash'], $refresh['grant_family_hash'] );
		self::assertNotNull( $this->rig->row( 'access_tokens', 'identifier_hash', hash( 'sha256', $outcome->issuance->access_key ) ) );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 10 ), $this->rig->row( 'clients', 'client_id', $this->client )['last_used_at'] );
	}

	public function test_a_replayed_code_revokes_the_family_it_created(): void {
		$code = $this->rig->authorize( $this->client );
		$first = $this->rig->exchange( $code, $this->client );

		$replay = $this->rig->at( StorageRig::T + 20 )->exchange( $code, $this->client );

		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( $first->issuance->family_key, $replay->revoke_family_key );
		self::assertSame( 'revoked', $this->rig->row( 'families', 'family_key', $first->issuance->family_key )['phase'] );
		self::assertSame( '1', $this->rig->row( 'access_tokens', 'identifier_hash', hash( 'sha256', $first->issuance->access_key ) )['revoked'] );
		self::assertSame( '1', $this->rig->row( 'refresh_tokens', 'identifier_hash', RowKeys::refresh( $first->issuance->refresh_key ) )['revoked'] );
	}

	public function test_concurrent_exchanges_create_one_family_and_revoke_it(): void {
		$code = $this->rig->authorize( $this->client );
		$other = $this->rig->connection();
		$winner = null;
		$this->rig->wpdb->before = function ( string $sql ) use ( $other, $code, &$winner ): void {
			if ( null === $winner && str_starts_with( $sql, 'UPDATE wptests_stonewright_oauth_auth_codes' ) ) {
				$winner = $other->exchange( $code, $this->client );
			}
		};

		$loser = $this->rig->exchange( $code, $this->client );

		self::assertNull( $winner->fault );
		self::assertSame( 'invalid_grant', $loser->fault->error() );
		self::assertCount( 1, $this->rig->rows( 'families' ) );
		self::assertSame( 'revoked', $this->rig->rows( 'families' )[0]['phase'] );
	}

	public function test_unknown_codes_are_invalid_grant(): void {
		try {
			$this->rig->codes->change( str_repeat( 'f', 80 ), static fn ( CodeGrantState $state ): CodeExchangeOutcome => throw new \LogicException( 'never decided' ) );
			self::fail( 'An unknown code must be refused.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_grant', $fault->error() );
		}
	}

	public function test_an_expired_code_creates_nothing(): void {
		$code = $this->rig->authorize( $this->client );

		$outcome = $this->rig->at( StorageRig::T + 60 )->exchange( $code, $this->client );

		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( '0', $this->code_row( $code )['revoked'] );
		self::assertSame( [], $this->rig->rows( 'families' ) );
	}

	public function test_a_wrong_verifier_leaves_the_code_usable(): void {
		$code = $this->rig->authorize( $this->client );

		$wrong = $this->rig->exchange( $code, $this->client, str_repeat( 'w', 43 ) );
		$right = $this->rig->exchange( $code, $this->client );

		self::assertSame( 'invalid_grant', $wrong->fault->error() );
		self::assertNull( $right->fault );
	}

	public function test_a_failed_commit_leaves_the_code_unused(): void {
		$code = $this->rig->authorize( $this->client );
		$this->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO wptests_stonewright_oauth_access_tokens' );

		try {
			$this->rig->exchange( $code, $this->client );
			self::fail( 'A failed write must surface.' );
		} catch ( StorageFailure $failure ) {
			self::assertSame( 1, $this->rig->wpdb->rollbacks );
		}

		self::assertSame( '0', $this->code_row( $code )['revoked'] );
		self::assertSame( [], $this->rig->rows( 'families' ) );
		self::assertSame( [], $this->rig->rows( 'refresh_tokens' ) );
	}
}

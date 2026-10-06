<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\Housekeeping;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\Housekeeping
 */
final class HousekeepingTest extends TestCase {

	private const NOW = StorageRig::T + 200 * 86400;

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function housekeeping(): Housekeeping {
		return new Housekeeping( $this->rig->db, $this->rig->families, $this->rig->clients, $this->rig->clock, 60 );
	}

	private static function datetime( int $epoch ): string {
		return gmdate( 'Y-m-d H:i:s', $epoch );
	}

	private function insert( string $table, array $data ): void {
		self::assertTrue( $this->rig->db->insert( $this->rig->db->table( $table ), $data ) );
	}

	private function code( string $key, int $expires, bool $used ): void {
		$this->insert( 'auth_codes', [ 'identifier_hash' => RowKeys::code( $key ), 'client_id' => 'c', 'user_id' => 7, 'expires_at' => self::datetime( $expires ), 'scopes' => '["mcp"]', 'redirect_uri' => StorageRig::REDIRECT, 'revoked' => $used ? 1 : 0 ] );
	}

	public function test_codes_keep_used_markers_until_expiry_plus_retention(): void {
		$this->code( 'unused-expired', self::NOW - 10, false );
		$this->code( 'used-recent', self::NOW - 10, true );
		$this->code( 'used-old', self::NOW - CodeGrantState::USED_CODE_RETENTION - 1, true );
		$this->code( 'live', self::NOW + 30, false );

		$this->rig->at( self::NOW );
		$counts = $this->housekeeping()->run();

		self::assertSame( 2, $counts['codes'] );
		$left = array_column( $this->rig->rows( 'auth_codes' ), 'identifier_hash' );
		self::assertEqualsCanonicalizing( [ RowKeys::code( 'used-recent' ), RowKeys::code( 'live' ) ], $left );
	}

	public function test_expired_consents_and_access_rows_are_removed(): void {
		$this->insert( 'consents', [ 'consent_hash' => RowKeys::consent( 'old' ), 'user_id' => 7, 'client_id' => 'c', 'request_json' => '{}', 'created_at' => self::datetime( self::NOW - 700 ), 'expires_at' => self::datetime( self::NOW - 100 ) ] );
		$this->insert( 'consents', [ 'consent_hash' => RowKeys::consent( 'new' ), 'user_id' => 7, 'client_id' => 'c', 'request_json' => '{}', 'created_at' => self::datetime( self::NOW ), 'expires_at' => self::datetime( self::NOW + 500 ) ] );
		$this->insert( 'access_tokens', [ 'identifier_hash' => hash( 'sha256', 'old' ), 'client_id' => 'c', 'user_id' => 7, 'expires_at' => self::datetime( self::NOW - 1 ), 'scopes' => '["mcp"]', 'revoked' => 0 ] );
		$this->insert( 'access_tokens', [ 'identifier_hash' => hash( 'sha256', 'new' ), 'client_id' => 'c', 'user_id' => 7, 'expires_at' => self::datetime( self::NOW + 100 ), 'scopes' => '["mcp"]', 'revoked' => 0 ] );

		$this->rig->at( self::NOW );
		$counts = $this->housekeeping()->run();

		self::assertSame( 1, $counts['consents'] );
		self::assertSame( 1, $counts['access_tokens'] );
		self::assertSame( [ RowKeys::consent( 'new' ) ], array_column( $this->rig->rows( 'consents' ), 'consent_hash' ) );
		self::assertSame( [ hash( 'sha256', 'new' ) ], array_column( $this->rig->rows( 'access_tokens' ), 'identifier_hash' ) );
	}

	public function test_dead_families_go_after_their_deadline_plus_a_margin(): void {
		[ $client, , $dead ] = $this->rig->connect();
		$this->rig->at( self::NOW - 3 * 86400 );
		$this->rig->clients->touch( $client, self::NOW - 3600 );
		$live_client = $this->rig->register_client();
		$live = $this->rig->exchange( $this->rig->authorize( $live_client ), $live_client );
		$legacy = new LegacyRows( $this->rig->space, self::NOW );
		$legacy_dead = $legacy->family( 'f', 1, null, self::NOW - LegacyRows::FAMILY_LIFETIME - 2 * 86400 );
		$legacy_recent = $legacy->family( 'f', 0, null, self::NOW - LegacyRows::FAMILY_LIFETIME - 3600 );

		$this->rig->at( self::NOW );
		$counts = $this->housekeeping()->run();

		self::assertSame( 1, $counts['families'] );
		self::assertNull( $this->rig->row( 'families', 'family_key', $dead->issuance->family_key ) );
		self::assertNotNull( $this->rig->row( 'families', 'family_key', $live->issuance->family_key ) );
		$families_left = array_unique( array_column( $this->rig->rows( 'refresh_tokens' ), 'grant_family_hash' ) );
		self::assertEqualsCanonicalizing( [ RowKeys::grant_family( $live->issuance->family_key ), $legacy_recent['family_hash'] ], $families_left );
		self::assertSame( 3, $counts['refresh_tokens'] );
		self::assertNotContains( $legacy_dead['family_hash'], $families_left );
	}

	public function test_unused_clients_and_old_rate_rows_are_removed(): void {
		$this->rig->at( self::NOW - ClientStore::UNUSED_LIFETIME - 60 );
		$stale = $this->rig->register_client();
		$this->rig->at( self::NOW );
		$fresh = $this->rig->register_client();
		$old = self::NOW - Housekeeping::RATE_LIMIT_RETENTION - 1;
		$this->insert( 'rate_limits', [ 'bucket_key' => hash( 'sha256', 'old' ), 'window_started' => $old, 'hits' => 3, 'updated_at' => self::datetime( $old ) ] );
		$this->insert( 'rate_limits', [ 'bucket_key' => hash( 'sha256', 'new' ), 'window_started' => self::NOW, 'hits' => 1, 'updated_at' => self::datetime( self::NOW ) ] );
		$this->insert( 'rate_metrics', [ 'metric_bucket' => hash( 'sha256', 'old' ), 'window_started' => $old, 'fingerprint_key' => hash( 'sha256', 'f' ), 'limited_requests' => 1, 'cooldown_until' => $old + 60, 'updated_at' => self::datetime( $old ) ] );

		$counts = $this->housekeeping()->run();

		self::assertSame( 1, $counts['clients'] );
		self::assertNull( $this->rig->clients->find( $stale ) );
		self::assertNotNull( $this->rig->clients->find( $fresh ) );
		self::assertSame( 2, $counts['rate_limits'] );
		self::assertSame( [ hash( 'sha256', 'new' ) ], array_column( $this->rig->rows( 'rate_limits' ), 'bucket_key' ) );
		self::assertSame( [], $this->rig->rows( 'rate_metrics' ) );
	}

	public function test_consumed_history_is_compacted_to_the_head_and_its_predecessor(): void {
		[ $client, $token, $created ] = $this->rig->connect();
		for ( $step = 1; $step <= 3; $step++ ) {
			$outcome = $this->rig->at( StorageRig::T + $step * 600 )->refresh( $token->refresh_token, $client );
			$token = $this->rig->codec->encode( $outcome->issuance );
		}
		$legacy = new LegacyRows( $this->rig->space, StorageRig::T );
		$legacy_family = $legacy->family( 'f', 3, null, StorageRig::T - 3600 );

		$this->rig->at( StorageRig::T + 4000 );
		$counts = $this->housekeeping()->run();

		self::assertSame( 1, $counts['compacted'] );
		$family_hash = RowKeys::grant_family( $created->issuance->family_key );
		self::assertCount( 2, array_filter( $this->rig->rows( 'refresh_tokens' ), static fn ( array $row ): bool => $row['grant_family_hash'] === $family_hash ) );
		self::assertCount( 4, array_filter( $this->rig->rows( 'refresh_tokens' ), static fn ( array $row ): bool => $row['grant_family_hash'] === $legacy_family['family_hash'] ), 'earlier rows are never compacted' );
		self::assertSame( 0, $this->housekeeping()->run()['compacted'] );
	}

	public function test_the_hook_name_is_kept(): void {
		self::assertSame( 'stonewright_oauth_gc', Housekeeping::HOOK );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The read-only list of grants a client can still use, which backs the
 * administrator's list of connected clients and its disconnect action.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\FamilyStore::live_grants
 */
final class LiveGrantsTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	/** Version 4 tables with earlier rows, upgraded in place. */
	private static function earlier_site(): array {
		$space = new FakeTables();
		LegacyRows::install_version_four( $space );
		update_option( 'stonewright_oauth_schema_version', '4' );
		$rig = new StorageRig( $space );
		$legacy = new LegacyRows( $space, StorageRig::T );
		$legacy->client();
		return [ $rig, $legacy ];
	}

	public function test_active_families_come_newest_first_with_owner_and_dates(): void {
		$rig = new StorageRig();
		[ $older ] = $rig->connect();
		$rig->at( StorageRig::T + 60 );
		[ $newer ] = $rig->connect();

		$grants = $rig->families->live_grants( StorageRig::T + 120 );

		self::assertSame( [ $newer, $older ], array_column( $grants, 'client_key' ) );
		self::assertSame( '7', $grants[0]['subject_key'] );
		self::assertSame( StorageRig::T + 60, $grants[0]['granted_at'] );
		self::assertGreaterThan( StorageRig::T + 120, (int) $grants[0]['expires_at'] );
		self::assertSame( $rig->row( 'families', 'client_id', $newer )['family_hash'], RowKeys::grant_family( $grants[0]['family_key'] ) );
	}

	public function test_revoked_and_expired_families_are_left_out(): void {
		$rig = new StorageRig();
		[ $revoked ] = $rig->connect();
		[ $kept ] = $rig->connect();
		$grants = $rig->families->live_grants( StorageRig::T );
		$rig->families->revoke( $grants[ (int) array_search( $revoked, array_column( $grants, 'client_key' ), true ) ]['family_key'] );

		$remaining = $rig->families->live_grants( StorageRig::T );

		self::assertSame( [ $kept ], array_column( $remaining, 'client_key' ) );
		self::assertSame( [], $rig->families->live_grants( (int) $remaining[0]['expires_at'] ) );
	}

	public function test_reading_the_list_changes_nothing(): void {
		$rig = new StorageRig();
		$rig->connect();
		$before = [ $rig->rows( 'families' ), $rig->rows( 'refresh_tokens' ), $rig->rows( 'access_tokens' ) ];

		$rig->families->live_grants( StorageRig::T );

		self::assertSame( $before, [ $rig->rows( 'families' ), $rig->rows( 'refresh_tokens' ), $rig->rows( 'access_tokens' ) ] );
	}

	public function test_earlier_families_without_a_families_row_take_the_owner_of_their_paired_access_row(): void {
		[ $rig, $legacy ] = self::earlier_site();
		$live = $legacy->family( 'live', 2 );
		$legacy->family( 'revoked', 1, 'revoked' );
		$legacy->family( 'expired', 0, null, StorageRig::T - LegacyRows::FAMILY_LIFETIME - 10 );

		self::assertSame(
			[
				[
					'family_key'  => $live['family_hash'],
					'client_key'  => LegacyRows::CLIENT,
					'subject_key' => LegacyRows::SUBJECT,
					'granted_at'  => null,
					'expires_at'  => $live['deadline'],
				],
			],
			$rig->families->live_grants( StorageRig::T )
		);
		self::assertSame( [], $rig->rows( 'families' ), 'Listing never adopts an earlier family.' );
	}

	public function test_an_adopted_earlier_family_is_listed_once(): void {
		[ $rig, $legacy ] = self::earlier_site();
		$live = $legacy->family( 'live', 1 );
		self::assertNotNull( $rig->families->load( $live['family_hash'] ) );

		$grants = $rig->families->live_grants( StorageRig::T );

		self::assertCount( 1, $grants );
		self::assertSame( $live['family_hash'], $grants[0]['family_key'] );
		self::assertSame( LegacyRows::CLIENT, $grants[0]['client_key'] );
	}

	public function test_a_listed_earlier_family_can_be_revoked_by_its_key(): void {
		[ $rig, $legacy ] = self::earlier_site();
		$legacy->family( 'live', 1 );

		$rig->families->revoke( $rig->families->live_grants( StorageRig::T )[0]['family_key'] );

		self::assertSame( [], $rig->families->live_grants( StorageRig::T ) );
	}

	public function test_a_client_key_limits_the_list_to_that_client(): void {
		$rig = new StorageRig();
		[ $first ] = $rig->connect();
		$rig->exchange( $rig->authorize( $first ), $first );
		[ $second ] = $rig->connect();

		self::assertSame( [ $first, $first ], array_column( $rig->families->live_grants( StorageRig::T, 500, $first ), 'client_key' ) );
		self::assertSame( [ $second ], array_column( $rig->families->live_grants( StorageRig::T, 500, $second ), 'client_key' ) );
		self::assertCount( 1, $rig->families->live_grants( StorageRig::T, 1, $first ) );
		self::assertSame( [], $rig->families->live_grants( StorageRig::T, 500, 'ffffffffffffffffffffffffffffffff' ) );
	}

	public function test_a_client_key_selects_earlier_families_by_their_recorded_owner(): void {
		[ $rig, $legacy ] = self::earlier_site();
		$other = 'fedcba9876543210fedcba9876543210';
		$legacy->client( $other );
		$mine = $legacy->family( 'mine', 1 );
		$legacy->family( 'other', 1, null, null, $other );

		self::assertSame( [ $mine['family_hash'] ], array_column( $rig->families->live_grants( StorageRig::T, 500, LegacyRows::CLIENT ), 'family_key' ) );
		self::assertCount( 2, $rig->families->live_grants( StorageRig::T ) );
	}

	public function test_the_list_is_bounded(): void {
		$rig = new StorageRig();
		$rig->connect();
		$rig->connect();
		$rig->connect();

		self::assertCount( 2, $rig->families->live_grants( StorageRig::T, 2 ) );
	}
}

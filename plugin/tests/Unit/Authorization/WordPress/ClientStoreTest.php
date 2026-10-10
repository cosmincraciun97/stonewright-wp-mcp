<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientStore
 */
final class ClientStoreTest extends TestCase {

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private static function profile( array $overrides = [] ): array {
		return array_replace(
			[
				'redirect_uris'              => [ StorageRig::REDIRECT ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
				'client_name'                => 'Synthetic client',
			],
			$overrides
		);
	}

	public function test_registration_writes_the_observed_row_shape(): void {
		$assigned = $this->rig->clients->create( self::profile( [ 'client_uri' => 'https://client.example.test/' ] ) );

		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $assigned['client_id'] );
		self::assertSame( 'client_id', array_key_first( $assigned ) );
		self::assertSame( self::profile( [ 'client_uri' => 'https://client.example.test/' ] ), array_diff_key( $assigned, [ 'client_id' => true ] ) );
		$row = $this->rig->row( 'clients', 'client_id', $assigned['client_id'] );
		self::assertSame( 'Synthetic client', $row['client_name'] );
		self::assertSame( '["http://127.0.0.1:7999/callback"]', $row['redirect_uris'] );
		self::assertSame( '0', $row['is_confidential'] );
		self::assertNull( $row['client_secret_hash'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T ), $row['created_at'] );
		self::assertNull( $row['last_used_at'] );
		self::assertSame( RowKeys::address( '192.0.2.10' ), $row['registered_by_ip_hash'] );
		self::assertNotSame( hash( 'sha256', '192.0.2.10' ), $row['registered_by_ip_hash'], 'The registering address is stored as a keyed hash, never as a plain digest.' );
		self::assertSame( '0', $row['admin_created'] );
		self::assertNull( $row['registration_purpose'] );
		self::assertNull( $row['registration_expires_at'] );
		self::assertSame( self::profile( [ 'client_uri' => 'https://client.example.test/' ] ), json_decode( (string) $row['client_metadata'], true ) );
	}

	public function test_find_returns_the_stored_profile(): void {
		$client_id = $this->rig->clients->create( self::profile() )['client_id'];

		$found = $this->rig->clients->find( $client_id );

		self::assertSame( $client_id, $found['client_id'] );
		self::assertSame( [ StorageRig::REDIRECT ], $found['redirect_uris'] );
		self::assertSame( 'none', $found['token_endpoint_auth_method'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $found['grant_types'] );
		self::assertSame( StorageRig::T, $found['created_at'] );
		self::assertNull( $found['last_used_at'] );
		self::assertFalse( $found['admin_created'] );
		self::assertNull( $this->rig->clients->find( 'ffffffffffffffffffffffffffffffff' ) );
		self::assertNull( $this->rig->clients->find( '' ) );
	}

	public function test_clients_registered_by_the_earlier_version_get_the_public_defaults(): void {
		( new LegacyRows( $this->rig->space, StorageRig::T ) )->client();

		$found = $this->rig->clients->find( LegacyRows::CLIENT );

		self::assertSame( 'Synthetic earlier client', $found['client_name'] );
		self::assertSame( [ 'http://127.0.0.1:7999/callback' ], $found['redirect_uris'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $found['grant_types'] );
		self::assertSame( [ 'code' ], $found['response_types'] );
		self::assertSame( 'none', $found['token_endpoint_auth_method'] );
	}

	public function test_a_colliding_identifier_is_replaced_before_insert(): void {
		$ids = [ str_repeat( 'a', 32 ), str_repeat( 'a', 32 ), str_repeat( 'b', 32 ) ];
		$clients = new ClientStore( $this->rig->db, $this->rig->clock, static fn (): string => '192.0.2.10', static function () use ( &$ids ): string {
			return array_shift( $ids );
		} );

		self::assertSame( str_repeat( 'a', 32 ), $clients->create( self::profile() )['client_id'] );
		self::assertSame( str_repeat( 'b', 32 ), $clients->create( self::profile() )['client_id'] );
	}

	public function test_long_names_fit_the_column_and_stay_whole_in_the_profile(): void {
		$name = str_repeat( 'n', 300 );

		$client_id = $this->rig->clients->create( self::profile( [ 'client_name' => $name ] ) )['client_id'];

		self::assertSame( 191, strlen( (string) $this->rig->row( 'clients', 'client_id', $client_id )['client_name'] ) );
		self::assertSame( $name, $this->rig->clients->find( $client_id )['client_name'] );
	}

	public function test_touch_records_the_last_grant(): void {
		$client_id = $this->rig->clients->create( self::profile() )['client_id'];

		$this->rig->clients->touch( $client_id, StorageRig::T + 50 );

		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 50 ), $this->rig->row( 'clients', 'client_id', $client_id )['last_used_at'] );
	}

	public function test_prune_keeps_a_client_that_completed_a_grant_for_longer_than_thirty_days(): void {
		$client_id = $this->rig->clients->create( self::profile() )['client_id'];
		$this->rig->clients->touch( $client_id, StorageRig::T - 31 * 86400 );

		self::assertSame( 0, $this->rig->clients->prune( StorageRig::T ) );
		self::assertNotNull( $this->rig->clients->find( $client_id ) );

		self::assertSame( 0, $this->rig->clients->prune( StorageRig::T + 148 * 86400 ) );
		self::assertNotNull( $this->rig->clients->find( $client_id ), 'Idle for 179 days.' );
	}

	public function test_prune_removes_a_client_idle_for_more_than_the_used_lifetime_without_a_live_family(): void {
		$client_id = $this->rig->clients->create( self::profile() )['client_id'];
		$this->rig->clients->touch( $client_id, StorageRig::T - ClientStore::USED_LIFETIME - 10 );

		self::assertSame( 1, $this->rig->clients->prune( StorageRig::T ) );

		self::assertNull( $this->rig->clients->find( $client_id ) );
	}

	public function test_prune_keeps_a_client_idle_for_more_than_the_used_lifetime_while_a_family_is_live(): void {
		[ $client_id ] = $this->rig->connect();
		$this->rig->clients->touch( $client_id, StorageRig::T - ClientStore::USED_LIFETIME - 10 );

		self::assertSame( 0, $this->rig->clients->prune( StorageRig::T ) );

		self::assertNotNull( $this->rig->clients->find( $client_id ) );
	}

	public function test_prune_removes_a_client_that_never_completed_a_grant_after_thirty_days(): void {
		$this->rig->at( StorageRig::T - ClientStore::UNUSED_LIFETIME - 10 );
		$never_used = $this->rig->clients->create( self::profile() )['client_id'];
		$this->rig->at( StorageRig::T - ClientStore::UNUSED_LIFETIME + 86400 );
		$recent = $this->rig->clients->create( self::profile() )['client_id'];
		$this->rig->at( StorageRig::T );

		self::assertSame( 1, $this->rig->clients->prune( StorageRig::T ) );

		self::assertNull( $this->rig->clients->find( $never_used ) );
		self::assertNotNull( $this->rig->clients->find( $recent ) );
	}

	public function test_prune_keeps_administrator_created_clients_whatever_their_age(): void {
		$this->rig->at( StorageRig::T - ClientStore::USED_LIFETIME - 10 );
		$never_used = $this->rig->clients->create( self::profile() )['client_id'];
		$used = $this->rig->clients->create( self::profile() )['client_id'];
		$this->rig->clients->touch( $used, StorageRig::T - ClientStore::USED_LIFETIME - 10 );
		foreach ( [ $never_used, $used ] as $client_id ) {
			$this->rig->db->execute( 'UPDATE ' . $this->rig->db->table( 'clients' ) . ' SET admin_created = 1 WHERE client_id = %s', [ $client_id ] );
		}
		$this->rig->at( StorageRig::T );

		self::assertSame( 0, $this->rig->clients->prune( StorageRig::T ) );

		self::assertNotNull( $this->rig->clients->find( $never_used ) );
		self::assertNotNull( $this->rig->clients->find( $used ) );
	}

	public function test_prune_gives_a_metadata_document_client_the_same_two_lifetimes(): void {
		$profile = self::profile( [ 'client_id_metadata_document' => 'https://client.example.test/metadata.json' ] );
		$granted = hash( 'sha256', 'document-granted' );
		$never = hash( 'sha256', 'document-never' );
		$this->rig->at( StorageRig::T - ClientStore::UNUSED_LIFETIME - 10 );
		$this->rig->clients->save_document( $granted, $profile, StorageRig::T + 600 );
		$this->rig->clients->save_document( $never, $profile, StorageRig::T + 600 );
		$this->rig->clients->touch( $granted, StorageRig::T - 31 * 86400 );
		$this->rig->at( StorageRig::T );

		self::assertSame( 1, $this->rig->clients->prune( StorageRig::T ) );

		self::assertNotNull( $this->rig->clients->find( $granted ) );
		self::assertNull( $this->rig->clients->find( $never ) );
	}

	public function test_prune_works_through_every_eligible_client_in_successive_batches(): void {
		$this->rig->at( StorageRig::T - ClientStore::UNUSED_LIFETIME - 10 );
		for ( $index = 0; $index < 450; $index++ ) {
			$this->rig->clients->create( self::profile() );
		}
		$this->rig->at( StorageRig::T );
		$recent = $this->rig->clients->create( self::profile() )['client_id'];

		self::assertSame( 450, $this->rig->clients->prune( StorageRig::T ) );

		self::assertSame( [ $recent ], array_column( $this->rig->rows( 'clients' ), 'client_id' ) );
	}

	public function test_clients_with_a_live_family_do_not_hold_up_the_rest_of_a_prune(): void {
		$this->rig->at( StorageRig::T - ClientStore::UNUSED_LIFETIME - 10 );
		for ( $index = 0; $index < 205; $index++ ) {
			$this->live_family( $this->rig->clients->create( self::profile() )['client_id'] );
		}
		$unused = [];
		for ( $index = 0; $index < 3; $index++ ) {
			$unused[] = $this->rig->clients->create( self::profile() )['client_id'];
		}
		$this->rig->at( StorageRig::T );

		self::assertSame( 3, $this->rig->clients->prune( StorageRig::T ) );

		self::assertCount( 205, $this->rig->rows( 'clients' ) );
		foreach ( $unused as $client_id ) {
			self::assertNull( $this->rig->clients->find( $client_id ) );
		}
	}

	/** An unexpired active family of the client, as a grant would leave it. */
	private function live_family( string $client_id ): void {
		$created = gmdate( 'Y-m-d H:i:s', StorageRig::T );
		self::assertTrue(
			$this->rig->db->insert(
				$this->rig->db->table( 'families' ),
				[
					'family_hash'       => hash( 'sha256', 'family-of-' . $client_id ),
					'family_key'        => 'family-of-' . $client_id,
					'client_id'         => $client_id,
					'user_id'           => 7,
					'scopes'            => '["mcp"]',
					'resources'         => '["' . StorageRig::RESOURCE . '"]',
					'phase'             => 'active',
					'revision'          => 0,
					'delivery_count'    => 0,
					'compacted_entries' => 0,
					'family_expires_at' => gmdate( 'Y-m-d H:i:s', StorageRig::T + 86400 ),
					'created_at'        => $created,
					'updated_at'        => $created,
				]
			)
		);
	}

	public function test_store_uses_the_wordpress_prefix(): void {
		self::assertSame( 'wptests_stonewright_oauth_clients', ( new Database( $this->rig->wpdb ) )->table( 'clients' ) );
	}
}

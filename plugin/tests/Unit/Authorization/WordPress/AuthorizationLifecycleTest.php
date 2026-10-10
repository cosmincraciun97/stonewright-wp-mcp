<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationStorage;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\Housekeeping;
use Stonewright\WpMcp\Authorization\WordPress\KeyRecoveryNotice;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\SequentialIdentifiers;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AuthorizationStorage
 */
final class AuthorizationLifecycleTest extends TestCase {

	private FakeTables $space;
	private FakeWpdb $wpdb;
	private bool $generation_works = true;
	private bool $schema_works = true;
	private int $generation_attempts = 0;
	private int $schema_attempts = 0;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$_POST = [];
		$this->space = new FakeTables();
		$this->generation_works = true;
		$this->schema_works = true;
		$this->generation_attempts = 0;
		$this->schema_attempts = 0;
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-lifecycle-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
		$this->wpdb = new FakeWpdb( $this->space );
		$db = new Database( $this->wpdb );
		$keys = new CredentialKeys(
			$db,
			function ( ?string $configuration ): array {
				++$this->generation_attempts;
				return $this->generation_works ? [ StorageRig::private_key(), '' ] : [ null, 'no configuration' ];
			},
			static fn ( int $length ): string => str_repeat( "\x04", $length ),
			[ null ]
		);
		AuthorizationLifecycle::use_storage(
			new AuthorizationStorage(
				$db,
				new ScriptedClock( [ StorageRig::T ] ),
				new SequentialIdentifiers(),
				StorageRig::ISSUER,
				[ StorageRig::RESOURCE ],
				60,
				function ( $sql ): array {
					++$this->schema_attempts;
					return $this->schema_works ? $this->space->apply_ddl( $sql ) : [];
				},
				static fn (): string => 'synthetic-binding-secret',
				$keys
			)
		);
	}

	protected function tearDown(): void {
		AuthorizationLifecycle::use_storage( null );
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		$_POST = [];
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
	}

	private function remove_keys(): void {
		unset( $GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ], $GLOBALS['stonewright_test_options'][ CredentialKeys::ENCRYPTION_KEY_OPTION ] );
	}

	/** The stored schema is older than this version, as after a file-copy update. */
	private function outdate_schema(): void {
		update_option( StorageTables::VERSION_OPTION, '4' );
	}

	private function log(): string {
		return (string) file_get_contents( $this->log_file );
	}

	public function test_activation_installs_tables_creates_keys_and_schedules_cleanup(): void {
		$before = time();

		AuthorizationLifecycle::activate();

		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertTrue( $this->space->has( 'wptests_stonewright_oauth_refresh_tokens' ) );
		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		$scheduled = $GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ];
		self::assertGreaterThanOrEqual( $before + 3600, $scheduled );
		self::assertLessThanOrEqual( time() + 3600, $scheduled );
	}

	public function test_activation_survives_key_generation_failure(): void {
		$this->generation_works = false;

		AuthorizationLifecycle::activate();

		self::assertNotFalse( get_option( CredentialKeys::ERROR_OPTION ) );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertArrayHasKey( Housekeeping::HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}

	public function test_an_existing_schedule_is_kept(): void {
		$GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ] = 12345;

		AuthorizationLifecycle::activate();

		self::assertSame( 12345, $GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ] );
	}

	public function test_deactivation_clears_the_cleanup_schedule(): void {
		AuthorizationLifecycle::activate();

		AuthorizationLifecycle::deactivate();

		self::assertArrayNotHasKey( Housekeeping::HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}

	public function test_register_wires_cleanup_upgrade_and_key_recovery(): void {
		AuthorizationLifecycle::register();

		$actions = $GLOBALS['stonewright_test_actions'];
		self::assertSame( [ AuthorizationLifecycle::class, 'collect_garbage' ], $actions[ Housekeeping::HOOK ][0]['callback'] );
		self::assertSame( [ AuthorizationLifecycle::class, 'upgrade' ], $actions['init'][0]['callback'] );
		self::assertSame( [ AuthorizationLifecycle::class, 'render_key_notice' ], $actions['admin_notices'][0]['callback'] );
		self::assertSame( [ AuthorizationLifecycle::class, 'retry_keys' ], $actions[ 'admin_post_' . KeyRecoveryNotice::ACTION ][0]['callback'] );
	}

	public function test_upgrade_runs_only_for_an_older_schema(): void {
		AuthorizationLifecycle::upgrade();
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		$count = count( $this->space->ddl );

		AuthorizationLifecycle::upgrade();

		self::assertCount( $count, $this->space->ddl );
	}

	public function test_init_does_not_rerun_a_failed_schema_upgrade_within_the_back_off(): void {
		$this->schema_works = false;

		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();

		self::assertSame( 1, $this->schema_attempts, 'dbDelta runs once, not on every request' );
		self::assertSame( 1, substr_count( $this->log(), 'oauth_schema_install_failed' ) );
		self::assertFalse( get_option( StorageTables::VERSION_OPTION ) );
	}

	public function test_activation_ignores_the_back_off_and_a_success_clears_it(): void {
		$this->schema_works = false;
		AuthorizationLifecycle::upgrade();
		self::assertNotFalse( get_transient( StorageTables::BACKOFF_TRANSIENT ) );
		$this->schema_works = true;

		AuthorizationLifecycle::activate();

		self::assertSame( 2, $this->schema_attempts );
		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		self::assertFalse( get_transient( StorageTables::BACKOFF_TRANSIENT ) );
	}

	public function test_init_gives_a_site_that_never_ran_activation_its_keys_and_cleanup_event(): void {
		$before = time();

		AuthorizationLifecycle::upgrade();

		self::assertSame( '5', (string) get_option( StorageTables::VERSION_OPTION ) );
		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertSame( base64_encode( str_repeat( "\x04", 32 ) ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
		$scheduled = $GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ];
		self::assertGreaterThanOrEqual( $before + 3600, $scheduled );
		self::assertLessThanOrEqual( time() + 3600, $scheduled );
		self::assertSame( 1, $this->generation_attempts );
	}

	public function test_init_keeps_an_existing_cleanup_schedule(): void {
		$GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ] = 12345;

		AuthorizationLifecycle::upgrade();

		self::assertSame( 12345, $GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ] );
	}

	public function test_init_schedules_the_cleanup_event_even_while_the_schema_cannot_be_upgraded(): void {
		$this->schema_works = false;

		AuthorizationLifecycle::upgrade();

		self::assertArrayHasKey( Housekeeping::HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
		self::assertSame( 0, $this->generation_attempts, 'keys wait for readable tables' );
		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
	}

	public function test_init_asks_about_keys_only_when_it_upgrades_the_schema(): void {
		AuthorizationLifecycle::activate();
		$this->remove_keys();
		$attempts = $this->generation_attempts;
		$statements = count( $this->wpdb->statements );

		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();

		self::assertSame( $attempts, $this->generation_attempts, 'a current schema means no key look-up, even with the keys missing' );
		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ), 'the notice, not init, deals with missing keys' );
		self::assertCount( $statements, $this->wpdb->statements, 'no state look-up and no query on the usual request' );
	}

	public function test_init_restores_a_missing_cleanup_event_on_a_site_with_a_current_schema(): void {
		AuthorizationLifecycle::activate();
		unset( $GLOBALS['stonewright_test_scheduled_hooks'][ Housekeeping::HOOK ] );

		AuthorizationLifecycle::upgrade();

		self::assertArrayHasKey( Housekeeping::HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}

	public function test_an_upgraded_site_keeps_the_keys_it_has(): void {
		AuthorizationLifecycle::activate();
		$keys = [ get_option( CredentialKeys::PRIVATE_KEY_OPTION ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ];
		$this->outdate_schema();
		$attempts = $this->generation_attempts;
		$schema = $this->schema_attempts;

		AuthorizationLifecycle::upgrade();

		self::assertSame( $schema + 1, $this->schema_attempts, 'the upgrade ran' );
		self::assertSame( $attempts, $this->generation_attempts );
		self::assertSame( $keys, [ get_option( CredentialKeys::PRIVATE_KEY_OPTION ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ] );
	}

	/**
	 * @dataProvider \Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\StorageTablesTest::state_rows
	 * @param array<string, string> $row
	 */
	public function test_init_never_creates_keys_for_a_site_that_has_oauth_state( string $suffix, array $row ): void {
		AuthorizationLifecycle::activate();
		self::assertIsArray( $this->space->insert_row( 'wptests_stonewright_oauth_' . $suffix, $row ) );
		$this->remove_keys();
		$this->outdate_schema();
		$attempts = $this->generation_attempts;

		AuthorizationLifecycle::upgrade();

		self::assertSame( $attempts, $this->generation_attempts, 'no key was generated' );
		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertFalse( get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
		self::assertStringContainsString( 'oauth_key_creation_skipped', $this->log(), 'the look-up ran and declined' );
		self::assertArrayHasKey( Housekeeping::HOOK, $GLOBALS['stonewright_test_scheduled_hooks'] );
	}

	public function test_rate_limit_counters_alone_do_not_stop_key_creation(): void {
		AuthorizationLifecycle::activate();
		self::assertIsArray( $this->space->insert_row( 'wptests_stonewright_oauth_rate_limits', [ 'bucket_key' => str_repeat( 'b', 64 ), 'window_started' => '1', 'updated_at' => '2026-01-01 00:00:00' ] ) );
		$this->remove_keys();
		$this->outdate_schema();

		AuthorizationLifecycle::upgrade();

		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
	}

	public function test_a_missing_key_is_filled_in_for_a_site_without_state_and_never_replaced(): void {
		AuthorizationLifecycle::activate();
		$private = get_option( CredentialKeys::PRIVATE_KEY_OPTION );
		unset( $GLOBALS['stonewright_test_options'][ CredentialKeys::ENCRYPTION_KEY_OPTION ] );
		$this->outdate_schema();

		AuthorizationLifecycle::upgrade();

		self::assertSame( $private, get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertSame( base64_encode( str_repeat( "\x04", 32 ) ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
	}

	public function test_an_unusable_stored_key_is_reported_to_the_notice_and_left_in_place(): void {
		AuthorizationLifecycle::activate();
		$GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ] = 'not a key';
		$this->outdate_schema();

		AuthorizationLifecycle::upgrade();

		self::assertSame( 'not a key', get_option( CredentialKeys::PRIVATE_KEY_OPTION ), 'a stored key is never replaced' );
		self::assertStringContainsString( 'unusable', (string) get_option( CredentialKeys::ERROR_OPTION ) );
	}

	public function test_a_site_with_state_still_gets_its_keys_through_the_notice(): void {
		$this->site_with_a_client_and_no_keys();
		AuthorizationLifecycle::upgrade();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];

		ob_start();
		AuthorizationLifecycle::render_key_notice();
		$html = (string) ob_get_clean();
		$_POST['_wpnonce'] = 'nonce';
		$location = AuthorizationLifecycle::storage()->key_notice()->retry();

		self::assertStringContainsString( KeyRecoveryNotice::ACTION, $html );
		self::assertStringContainsString( KeyRecoveryNotice::RESULT_ARG . '=ready', $location );
		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
	}

	public function test_a_site_with_state_is_looked_at_once_when_its_schema_is_upgraded(): void {
		$this->site_with_a_client_and_no_keys();
		$before = count( $this->wpdb->statements );

		AuthorizationLifecycle::upgrade();
		$first = count( $this->wpdb->statements );
		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();

		self::assertGreaterThan( $before, $first, 'the upgrading request looked at the tables' );
		self::assertCount( $first, $this->wpdb->statements, 'the next requests do not' );
		self::assertSame( 1, substr_count( $this->log(), 'oauth_key_creation_skipped' ) );
	}

	public function test_init_treats_unreadable_state_as_present(): void {
		AuthorizationLifecycle::activate();
		$this->remove_keys();
		$this->outdate_schema();
		$attempts = $this->generation_attempts;
		$this->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'SELECT client_id FROM' );

		AuthorizationLifecycle::upgrade();

		self::assertSame( $attempts, $this->generation_attempts, 'unknown state never leads to new keys' );
		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertStringContainsString( 'oauth_key_creation_skipped', $this->log() );
	}

	public function test_a_failed_key_creation_on_init_is_not_retried_and_the_notice_takes_over(): void {
		$this->generation_works = false;

		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();
		AuthorizationLifecycle::upgrade();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		ob_start();
		AuthorizationLifecycle::render_key_notice();
		$html = (string) ob_get_clean();

		self::assertSame( 1, $this->generation_attempts, 'key generation ran for the upgrade, not on every request' );
		self::assertNotFalse( get_option( CredentialKeys::ERROR_OPTION ), 'the notice has its reason' );
		self::assertStringContainsString( KeyRecoveryNotice::ACTION, $html );
	}

	/** A site that ran activation, then lost its signing and encryption keys while holding one registered client. */
	private function site_with_a_client_and_no_keys(): void {
		AuthorizationLifecycle::activate();
		self::assertIsArray( $this->space->insert_row( 'wptests_stonewright_oauth_clients', [ 'client_id' => 'c1', 'client_name' => 'Synthetic', 'redirect_uris' => '[]', 'created_at' => '2026-01-01 00:00:00', 'registered_by_ip_hash' => str_repeat( 'a', 64 ) ] ) );
		$this->remove_keys();
		$this->outdate_schema();
	}

	public function test_the_cron_handler_runs_housekeeping(): void {
		AuthorizationLifecycle::activate();
		$db = new Database( new FakeWpdb( $this->space ) );
		$db->insert( $db->table( 'auth_codes' ), [ 'identifier_hash' => RowKeys::code( 'old' ), 'client_id' => 'c', 'user_id' => 7, 'expires_at' => gmdate( 'Y-m-d H:i:s', StorageRig::T - 10 ), 'scopes' => '["mcp"]', 'redirect_uri' => StorageRig::REDIRECT, 'revoked' => 0 ] );

		AuthorizationLifecycle::collect_garbage();

		self::assertSame( [], $this->space->rows( 'wptests_stonewright_oauth_auth_codes' ) );
	}

	public function test_the_key_notice_renders_through_the_lifecycle(): void {
		$this->generation_works = false;
		AuthorizationLifecycle::activate();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];

		ob_start();
		AuthorizationLifecycle::render_key_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( KeyRecoveryNotice::ACTION, $html );
	}

	public function test_wordpress_composition_derives_the_observed_urls(): void {
		AuthorizationLifecycle::use_storage( null );

		$storage = AuthorizationStorage::wordpress();

		self::assertSame( 'https://example.test', $storage->issuer() );
		self::assertSame( 'https://example.test/wp-json/mcp/stonewright-oauth', $storage->resources()[0] );
		self::assertContains( 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth', $storage->resources() );
		self::assertSame( 60, $storage->policy()->duplicate_window() );
	}
}

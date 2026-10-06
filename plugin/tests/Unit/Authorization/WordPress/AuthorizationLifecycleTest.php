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
	private bool $generation_works = true;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->space = new FakeTables();
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-lifecycle-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
		$db = new Database( new FakeWpdb( $this->space ) );
		$keys = new CredentialKeys(
			$db,
			fn ( ?string $configuration ): array => $this->generation_works ? [ StorageRig::private_key(), '' ] : [ null, 'no configuration' ],
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
				fn ( $sql ): array => $this->space->apply_ddl( $sql ),
				static fn (): string => 'synthetic-binding-secret',
				$keys
			)
		);
	}

	protected function tearDown(): void {
		AuthorizationLifecycle::use_storage( null );
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		StorageRig::reset_globals();
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

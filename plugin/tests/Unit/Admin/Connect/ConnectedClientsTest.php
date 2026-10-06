<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Connect;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInPanel;
use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Exchange\ConsentCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\OAuthReply;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Authorization\WordPress\TokenEndpoint;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The administrator's list of connected OAuth clients and its disconnect action.
 *
 * @covers \Stonewright\WpMcp\Admin\Connect\ConnectedClients
 */
final class ConnectedClientsTest extends TestCase {

	private const DOCUMENT = 'https://client.example/oauth/metadata.json';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->http = new HttpRig();
		HttpSurface::use_site( $this->http->site );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		AuditLog::reset_request_state();
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_users']           = [
			7 => [
				'ID'           => 7,
				'user_login'   => 'editor-a',
				'display_name' => 'Editor A',
			],
		];
		$_GET = [];
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		AuditLog::reset_request_state();
		StorageRig::reset_globals();
		unset( $GLOBALS['stonewright_test_users'], $_SERVER['REMOTE_ADDR'] );
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$_GET = [];
	}

	/** One registered client with two sign-ins and another with one. @return array{0: string, 1: string, 2: string} */
	private function two_clients(): array {
		$rig = $this->http->rig;
		[ $first, $tokens ] = $rig->connect();
		$rig->exchange( $rig->authorize( $first ), $first );
		$second = $rig->register_client( 'Second <b>client</b>' );
		$rig->at( StorageRig::T + 30 );
		$rig->exchange( $rig->authorize( $second ), $second );
		return [ $first, $second, $tokens->refresh_token ];
	}

	/** @return array<string, array<string, mixed>> */
	private static function by_key( array $rows ): array {
		return array_column( $rows, null, 'client_key' );
	}

	/** A consent request of user 7 that is still waiting for an answer; returns its pending key. */
	private function open_consent( string $client ): string {
		return $this->http->rig->consents->open(
			[
				'subject_key'           => '7',
				'client_key'            => $client,
				'redirect_uri'          => StorageRig::REDIRECT,
				'code_challenge'        => HttpRig::challenge(),
				'code_challenge_method' => 'S256',
				'scopes'                => [ 'mcp' ],
				'resources'             => [ StorageRig::RESOURCE ],
				'native_client'         => true,
				'registered_redirects'  => [ StorageRig::REDIRECT ],
			]
		);
	}

	/** The answer of the token endpoint to an authorization code. */
	private function exchange_code( string $code, string $client ): OAuthReply {
		return ( new TokenEndpoint( $this->http->storage, $this->http->site ) )->handle(
			$this->http->form(
				[
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => StorageRig::REDIRECT,
					'client_id'     => $client,
					'code_verifier' => StorageRig::VERIFIER,
					'resource'      => StorageRig::RESOURCE,
				]
			)
		);
	}

	/** @return list<array<string, mixed>> Audit rows written through the shared recorder. */
	private static function audit_rows(): array {
		return array_values(
			array_map(
				static fn ( array $insert ): array => $insert['data'],
				array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $insert ): bool => str_contains( (string) $insert['table'], 'stonewright_audit_log' ) )
			)
		);
	}

	private static function request( string $client, string $nonce_client = '' ): array {
		return [
			'client'   => $client,
			'_wpnonce' => wp_create_nonce( ConnectedClients::NONCE_PREFIX . ( '' === $nonce_client ? $client : $nonce_client ) ),
		];
	}

	public function test_the_list_groups_live_grants_by_client_with_people_and_last_use(): void {
		[ $first, $second ] = $this->two_clients();

		$rows = self::by_key( ConnectedClients::current() );

		self::assertSame( [ $second, $first ], array_keys( $rows ), 'Most recently used client first.' );
		self::assertSame( 'Synthetic client', $rows[ $first ]['name'] );
		self::assertSame( 2, $rows[ $first ]['grants'] );
		self::assertSame( [ 'Editor A' ], $rows[ $first ]['people'] );
		self::assertSame( StorageRig::T, $rows[ $first ]['connected_since'] );
		self::assertSame( StorageRig::T, $rows[ $first ]['last_used'] );
		self::assertSame( 'Registered automatically', $rows[ $first ]['identity'] );
		self::assertSame( 'Second <b>client</b>', $rows[ $second ]['name'], 'Names stay raw; the panel escapes them.' );
		self::assertSame( StorageRig::T + 30, $rows[ $second ]['last_used'] );
	}

	public function test_clients_with_a_published_identity_name_its_host(): void {
		$key = RowKeys::client_document( self::DOCUMENT );
		$this->http->storage->clients()->save_document(
			$key,
			[
				'client_id'                   => self::DOCUMENT,
				'client_name'                 => 'Document client',
				'redirect_uris'               => [ StorageRig::REDIRECT ],
				'token_endpoint_auth_method'  => 'none',
				'client_id_metadata_document' => self::DOCUMENT,
			],
			StorageRig::T + 3600
		);
		$rig = $this->http->rig;
		$rig->exchange( $rig->authorize( $key ), $key );

		$row = self::by_key( ConnectedClients::current() )[ $key ];

		self::assertSame( 'Document client', $row['name'] );
		self::assertSame( 'Published identity from client.example', $row['identity'] );
	}

	public function test_long_names_are_shortened_and_unknown_people_are_named_by_id(): void {
		$rig = $this->http->rig;
		$client = $rig->register_client( str_repeat( 'n', 300 ) );
		$rig->current_subject = '42';
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 42 ];
		$rig->exchange( $rig->authorize( $client ), $client );

		$row = self::by_key( ConnectedClients::current() )[ $client ];

		self::assertSame( str_repeat( 'n', 79 ) . '…', $row['name'] );
		self::assertSame( [ 'Deleted user #42' ], $row['people'] );
	}

	public function test_disconnect_revokes_every_live_grant_of_that_client_only_and_is_audited(): void {
		[ $first, $second, $refresh ] = $this->two_clients();

		$result = ConnectedClients::disconnect( self::request( $first ) );

		self::assertSame( [ 'status' => 'disconnected', 'revoked' => 2 ], $result );
		self::assertSame( [ $second ], array_keys( self::by_key( ConnectedClients::current() ) ) );
		self::assertNull( $this->http->rig->refresh( $refresh, $first )->issuance, 'A disconnected client cannot refresh.' );
		foreach ( $this->http->rig->rows( 'access_tokens' ) as $access ) {
			self::assertSame( $access['client_id'] === $first ? '1' : '0', $access['revoked'] );
		}
		$audit = self::audit_rows();
		self::assertCount( 1, $audit );
		self::assertSame( 'oauth/disconnect', $audit[0]['ability_name'] );
		self::assertSame( 'ok', $audit[0]['result_status'] );
		self::assertSame( 1, $audit[0]['user_id'] );
		$details = json_decode( (string) $audit[0]['sanitized_args'], true );
		self::assertSame( $first, $details['client_id'] );
		self::assertSame( 'Synthetic client', $details['client_name'] );
		self::assertSame( 2, $details['revoked_grants'] );
		self::assertSame( 'oauth_client', $details['_meta']['resource_type'] );
	}

	public function test_disconnect_also_closes_the_codes_and_consents_that_could_still_create_a_grant(): void {
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		[ $first, $second ] = $this->two_clients();
		$rig = $this->http->rig;
		$code = $rig->authorize( $first );
		$pending = $this->open_consent( $first );
		$kept_code = $rig->authorize( $second );
		$kept_pending = $this->open_consent( $second );
		self::assertNotNull( $rig->consents->peek( $pending ), 'The consent is live before the disconnect.' );
		self::assertSame( 200, $this->exchange_code( $rig->authorize( $first ), $first )->status, 'A code of this client is exchangeable before the disconnect.' );

		$result = ConnectedClients::disconnect( self::request( $first ) );

		self::assertSame( 'disconnected', $result['status'] );
		$reply = $this->exchange_code( $code, $first );
		self::assertSame( 400, $reply->status );
		self::assertSame( 'invalid_grant', $reply->body['error'] ?? null, 'A code issued before the disconnect no longer creates a grant.' );
		self::assertNull( $rig->consents->peek( $pending ) );
		try {
			( new ConsentCoordinator( $rig->consents, $rig->clock, $rig->ids, new SyntheticSubjectAuthority(), new ConsentDecision( 60 ) ) )->decide( $pending, '7', true, true );
			self::fail( 'A consent that was pending at the disconnect must not be approved.' );
		} catch ( OAuthFault $refused ) {
			self::assertSame( 'invalid_request', $refused->error() );
		}
		self::assertSame( [ $second ], array_column( $rig->rows( 'consents' ), 'client_id' ), 'Only the other client keeps its pending request.' );
		self::assertSame( [ $second ], array_keys( self::by_key( ConnectedClients::current() ) ), 'The disconnected client holds no grant.' );

		self::assertSame( 200, $this->exchange_code( $kept_code, $second )->status, 'Another client keeps its code.' );
		self::assertNotNull( $rig->consents->peek( $kept_pending ) );
	}

	public function test_disconnect_keeps_reading_batches_until_the_client_has_no_live_grant(): void {
		[ $first, $second ] = $this->two_clients();
		$rig = $this->http->rig;
		$rig->exchange( $rig->authorize( $first ), $first );

		$result = ConnectedClients::disconnect( self::request( $first ), 1 );

		self::assertSame( [ 'status' => 'disconnected', 'revoked' => 3 ], $result );
		self::assertSame( [ $second ], array_keys( self::by_key( ConnectedClients::current() ) ) );
	}

	public function test_a_client_without_live_grants_is_reported_and_audited(): void {
		$client = $this->http->rig->register_client();

		self::assertSame( [ 'status' => 'none', 'revoked' => 0 ], ConnectedClients::disconnect( self::request( $client ) ) );
		self::assertCount( 1, self::audit_rows() );
	}

	public function test_disconnect_requires_manage_options(): void {
		[ $first ] = $this->two_clients();
		$GLOBALS['stonewright_test_user_caps'] = [];

		try {
			ConnectedClients::disconnect( self::request( $first ) );
			self::fail( 'Expected wp_die.' );
		} catch ( \RuntimeException $denied ) {
			self::assertStringContainsString( 'wp_die', $denied->getMessage() );
		}
		self::assertCount( 2, self::by_key( ConnectedClients::current() ) );
		self::assertSame( [], self::audit_rows() );
	}

	public function test_disconnect_requires_a_valid_nonce(): void {
		[ $first ] = $this->two_clients();
		$GLOBALS['stonewright_test_nonce_invalid'] = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		ConnectedClients::disconnect( self::request( $first ) );
	}

	/** @dataProvider malformed_clients */
	public function test_disconnect_rejects_malformed_client_keys( mixed $client ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		ConnectedClients::disconnect(
			[
				'client'   => $client,
				'_wpnonce' => 'test-nonce',
			]
		);
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformed_clients(): array {
		return [
			'empty'  => [ '' ],
			'markup' => [ '<script>' ],
			'long'   => [ str_repeat( 'a', 65 ) ],
			'array'  => [ [ 'a' ] ],
		];
	}

	public function test_a_storage_failure_is_reported_and_audited_as_an_error(): void {
		[ $first ] = $this->two_clients();
		$this->http->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'UPDATE' );

		$result = [];
		HttpRig::quietly(
			static function () use ( $first, &$result ): void {
				$result = ConnectedClients::disconnect( self::request( $first ) );
			}
		);

		self::assertSame( 'failed', $result['status'] );
		$audit = self::audit_rows();
		self::assertCount( 1, $audit );
		self::assertSame( 'error', $audit[0]['result_status'] );
	}

	public function test_the_notice_selector_accepts_only_known_outcomes(): void {
		self::assertSame( '', ConnectedClients::notice() );
		$_GET[ ConnectedClients::NOTICE_ARG ] = 'disconnected';
		self::assertSame( 'disconnected', ConnectedClients::notice() );
		$_GET[ ConnectedClients::NOTICE_ARG ] = '<b>forged</b>';
		self::assertSame( '', ConnectedClients::notice() );
	}

	public function test_the_connected_apps_address_is_a_hidden_administrator_page_that_leads_to_the_list(): void {
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		$GLOBALS['stonewright_test_actions']       = [];
		ConfigurationPage::register();
		do_action( 'admin_menu' );

		$page = $GLOBALS['stonewright_test_submenu_pages'][ ConnectedClients::PAGE ] ?? null;
		self::assertSame( 'stonewright-connected-apps', ConnectedClients::PAGE );
		self::assertIsArray( $page );
		self::assertSame( 'options.php', $page['parent'], 'Reachable by URL, never listed in a menu.' );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( [ ConnectedClients::class, 'render_page' ], $page['callback'] );
		self::assertSame(
			[ ConnectedClients::class, 'redirect_page' ],
			$GLOBALS['stonewright_test_actions'][ 'load-stonewright_page_' . ConnectedClients::PAGE ][0]['callback'] ?? null,
			'The redirect runs on the page load hook, before any output.'
		);

		self::assertTrue( ConnectedClients::send_to_list() );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright#' . SignInPanel::CONNECTIONS_ID, $GLOBALS['stonewright_test_last_redirect'] );
		$GLOBALS['stonewright_test_actions'] = [];
	}

	public function test_the_connected_apps_address_refuses_people_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		try {
			ConnectedClients::send_to_list();
			self::fail( 'Expected wp_die.' );
		} catch ( \RuntimeException $denied ) {
			self::assertStringContainsString( 'wp_die', $denied->getMessage() );
		}
		self::assertArrayNotHasKey( 'stonewright_test_last_redirect', $GLOBALS );
	}

	public function test_the_connected_apps_page_links_to_the_list_when_it_renders(): void {
		ob_start();
		try {
			ConnectedClients::render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=stonewright#' . SignInPanel::CONNECTIONS_ID . '"', $html );
		self::assertStringContainsString( 'Connected OAuth clients', $html );
	}

	public function test_nothing_is_read_before_the_storage_schema_is_installed(): void {
		$this->two_clients();
		update_option( StorageTables::VERSION_OPTION, (string) ( StorageTables::SCHEMA_VERSION - 1 ) );
		$statements = count( $this->http->rig->wpdb->statements );

		self::assertSame( [], ConnectedClients::current() );
		self::assertSame( $statements, count( $this->http->rig->wpdb->statements ), 'No query touches tables that may not exist.' );
	}

	public function test_an_unreadable_store_yields_an_empty_list(): void {
		$this->two_clients();
		$this->http->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'SELECT' );

		self::assertSame( [], ConnectedClients::current() );
	}
}

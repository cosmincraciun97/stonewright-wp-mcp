<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationPages;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocuments;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Authorization\WordPress\PageOutcome;
use Stonewright\WpMcp\Authorization\WordPress\TokenCodec;
use Stonewright\WpMcp\Authorization\WordPress\TokenEndpoint;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AuthorizationPages
 * @covers \Stonewright\WpMcp\Authorization\WordPress\PageOutcome
 */
final class AuthorizationPagesTest extends TestCase {

	private HttpRig $http;
	private string $client;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$this->http = new HttpRig();
		$this->client = $this->http->rig->register_client();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function pages(): AuthorizationPages {
		return new AuthorizationPages( $this->http->site, $this->http->storage, $this->http->documents, $this->http->limiter );
	}

	/** @param array<string, ?string> $changes Null removes a parameter. */
	private function query( array $changes = [] ): string {
		$parameters = array_replace(
			[
				'page'                  => 'stonewright-oauth-authorize',
				'response_type'         => 'code',
				'client_id'             => $this->client,
				'redirect_uri'          => StorageRig::REDIRECT,
				'code_challenge'        => HttpRig::challenge(),
				'code_challenge_method' => 'S256',
				'state'                 => 'synthetic-state',
				'scope'                 => 'mcp',
				'resource'              => StorageRig::RESOURCE,
			],
			$changes
		);
		return http_build_query( array_filter( $parameters, static fn ( ?string $value ): bool => null !== $value ), '', '&', PHP_QUERY_RFC3986 );
	}

	private function pending_token( array $changes = [] ): string {
		$outcome = $this->pages()->authorize( $this->query( $changes ), 7 );
		self::assertSame( PageOutcome::REDIRECT, $outcome->kind, $outcome->message );
		self::assertFalse( $outcome->external );
		self::assertMatchesRegularExpression( '#^https://example\.test/wp-admin/admin\.php\?page=stonewright-oauth-consent&token=([0-9a-f]{32})$#D', $outcome->location );
		return substr( $outcome->location, -32 );
	}

	private static function consent_query( string $token ): string {
		return 'page=stonewright-oauth-consent&token=' . $token;
	}

	/** @return array{0: string, 1: array<string, string>, 2: list<string>} Base, parameters and their order. */
	private static function returned( PageOutcome $outcome ): array {
		self::assertSame( PageOutcome::REDIRECT, $outcome->kind, $outcome->message );
		self::assertTrue( $outcome->external );
		[ $base, $query ] = explode( '?', $outcome->location, 2 ) + [ 1 => '' ];
		$names = [];
		$values = [];
		foreach ( explode( '&', $query ) as $pair ) {
			[ $name, $value ] = explode( '=', $pair, 2 ) + [ 1 => '' ];
			$names[] = rawurldecode( $name );
			$values[ rawurldecode( $name ) ] = rawurldecode( $value );
		}
		return [ $base, $values, $names ];
	}

	private static function assert_error_page( PageOutcome $outcome, int $status ): void {
		self::assertSame( PageOutcome::ERROR, $outcome->kind );
		self::assertSame( $status, $outcome->status );
		self::assertNotSame( '', $outcome->message );
		self::assertSame( '', $outcome->location );
	}

	public function test_a_signed_in_user_is_sent_to_a_one_use_consent_screen(): void {
		$token = $this->pending_token();

		$rows = $this->http->rig->rows( 'consents' );
		self::assertCount( 1, $rows );
		self::assertSame( '7', $rows[0]['user_id'] );
		self::assertSame( $this->client, $rows[0]['client_id'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 600 ), $rows[0]['expires_at'] );
		$request = json_decode( (string) $rows[0]['request_json'], true );
		self::assertSame( 'synthetic-state', $request['state'] );
		self::assertSame( StorageRig::REDIRECT, $request['redirect_uri'] );
		self::assertSame( [ 'mcp' ], $request['scopes'] );
		self::assertSame( [ StorageRig::RESOURCE ], $request['resources'] );
		self::assertTrue( $request['native_client'] );
		self::assertStringNotContainsString( $token, (string) json_encode( $rows ) );
	}

	public function test_the_redirect_uri_may_be_omitted_when_exactly_one_is_registered(): void {
		$this->pending_token( [ 'redirect_uri' => null, 'resource' => null, 'scope' => null ] );

		$request = json_decode( (string) $this->http->rig->rows( 'consents' )[0]['request_json'], true );
		self::assertSame( StorageRig::REDIRECT, $request['redirect_uri'] );
		self::assertSame( [ StorageRig::RESOURCE ], $request['resources'] );
	}

	public function test_a_loopback_callback_may_change_its_port(): void {
		$this->pending_token( [ 'redirect_uri' => 'http://127.0.0.1:43210/callback' ] );

		self::assertSame( 'http://127.0.0.1:43210/callback', json_decode( (string) $this->http->rig->rows( 'consents' )[0]['request_json'], true )['redirect_uri'] );
	}

	/** @dataProvider requests_without_a_trusted_callback */
	public function test_requests_without_a_trusted_callback_get_an_error_page_and_no_redirect( array $changes ): void {
		self::assert_error_page( $this->pages()->authorize( $this->query( $changes ), 7 ), 400 );
		self::assertSame( [], $this->http->rig->rows( 'consents' ) );
	}

	public function requests_without_a_trusted_callback(): array {
		return [
			'unknown client'        => [ [ 'client_id' => 'ffffffffffffffffffffffffffffffff' ] ],
			'missing client'        => [ [ 'client_id' => null ] ],
			'unregistered callback' => [ [ 'redirect_uri' => 'https://attacker.example.test/callback' ] ],
			'other loopback host'   => [ [ 'redirect_uri' => 'http://localhost:7999/callback' ] ],
			'other callback path'   => [ [ 'redirect_uri' => 'http://127.0.0.1:7999/other' ] ],
		];
	}

	public function test_an_application_the_site_does_not_know_is_never_named_in_the_audit_facts(): void {
		foreach ( [ 'ffffffffffffffffffffffffffffffff', 'made-up-application', 'https://client.example.test/oauth/unpublished.json' ] as $client_id ) {
			$outcome = $this->pages()->authorize( $this->query( [ 'client_id' => $client_id ] ), 7 );

			self::assert_error_page( $outcome, 400 );
			self::assertSame( '', $outcome->audit['client_id'], $client_id );
		}
		self::assertSame( [], $this->http->rig->rows( 'consents' ) );
	}

	public function test_a_known_application_is_named_in_the_audit_facts(): void {
		$redirected = $this->pages()->authorize( $this->query(), 7 );
		self::assertSame( PageOutcome::REDIRECT, $redirected->kind );
		self::assertSame( $this->client, $redirected->audit['client_id'] );

		$refused = $this->pages()->authorize( $this->query( [ 'redirect_uri' => 'https://attacker.example.test/callback' ] ), 7 );
		self::assert_error_page( $refused, 400 );
		self::assertSame( $this->client, $refused->audit['client_id'], 'The client is known even when its callback is not.' );

		$url = 'https://client.example.test/oauth/client.json';
		$this->http->publish_document( $url, [ 'client_id' => $url, 'client_name' => 'Document client', 'redirect_uris' => [ 'http://localhost/callback' ] ] );
		$document = $this->pages()->authorize( $this->query( [ 'client_id' => $url, 'redirect_uri' => 'http://localhost:51234/callback' ] ), 7 );
		self::assertSame( PageOutcome::REDIRECT, $document->kind );
		self::assertSame( $url, $document->audit['client_id'], 'A document client is named by the URL it presented.' );
	}

	public function test_a_malformed_query_gets_an_error_page(): void {
		self::assert_error_page( $this->pages()->authorize( $this->query() . '&client_id=' . $this->client, 7 ), 400 );
		self::assert_error_page( $this->pages()->authorize( 'page=x&client_id=%zz', 7 ), 400 );
	}

	/** @dataProvider protocol_errors */
	public function test_protocol_errors_return_to_the_client_with_state_and_issuer( array $changes, string $error ): void {
		[ $base, $parameters, $names ] = self::returned( $this->pages()->authorize( $this->query( $changes ), 7 ) );

		self::assertSame( StorageRig::REDIRECT, $base );
		self::assertSame( [ 'error', 'error_description', 'state', 'iss' ], $names );
		self::assertSame( $error, $parameters['error'] );
		self::assertSame( 'synthetic-state', $parameters['state'] );
		self::assertSame( 'https://example.test', $parameters['iss'] );
		self::assertSame( [], $this->http->rig->rows( 'consents' ) );
	}

	public function protocol_errors(): array {
		return [
			'token response'      => [ [ 'response_type' => 'token' ], 'unsupported_response_type' ],
			'no response type'    => [ [ 'response_type' => null ], 'invalid_request' ],
			'no challenge'        => [ [ 'code_challenge' => null ], 'invalid_request' ],
			'plain challenge'     => [ [ 'code_challenge_method' => 'plain' ], 'invalid_request' ],
			'unknown scope'       => [ [ 'scope' => 'mcp admin' ], 'invalid_scope' ],
			'foreign resource'    => [ [ 'resource' => 'https://other.example.test/mcp' ], 'invalid_target' ],
			'resource no scheme'  => [ [ 'resource' => 'localhost:8080' ], 'invalid_target' ],
		];
	}

	public function test_advertised_scopes_are_accepted_and_the_grant_carries_mcp(): void {
		$this->pending_token( [ 'scope' => 'mcp read write offline_access' ] );

		self::assertSame( [ 'mcp' ], json_decode( (string) $this->http->rig->rows( 'consents' )[0]['request_json'], true )['scopes'] );
	}

	public function test_too_many_authorization_requests_are_refused(): void {
		$http = new HttpRig( 'pretty', [ 'authorization' => [ 1, 600 ] ] );
		$client = $http->rig->register_client();
		$pages = new AuthorizationPages( $http->site, $http->storage, $http->documents, $http->limiter );
		$query = $this->query( [ 'client_id' => $client ] );

		self::assertSame( PageOutcome::REDIRECT, $pages->authorize( $query, 7 )->kind );
		self::assert_error_page( $pages->authorize( $query, 7 ), 429 );
	}

	public function test_a_metadata_document_client_is_resolved_at_authorization(): void {
		$url = 'https://client.example.test/oauth/client.json';
		$this->http->publish_document( $url, [ 'client_id' => $url, 'client_name' => 'Document client', 'redirect_uris' => [ 'http://localhost/callback' ] ] );

		$this->pending_token( [ 'client_id' => $url, 'redirect_uri' => 'http://localhost:51234/callback' ] );

		$row = $this->http->rig->rows( 'consents' )[0];
		self::assertSame( ClientDocuments::client_key( $url ), $row['client_id'] );
		self::assertSame( 'http://localhost:51234/callback', json_decode( (string) $row['request_json'], true )['redirect_uri'] );
	}

	public function test_a_document_client_key_cannot_stand_in_for_its_url(): void {
		$url = 'https://client.example.test/oauth/client.json';
		$this->http->publish_document( $url, [ 'client_id' => $url, 'redirect_uris' => [ StorageRig::REDIRECT ] ] );
		$this->http->documents->resolve( $url );

		self::assert_error_page( $this->pages()->authorize( $this->query( [ 'client_id' => ClientDocuments::client_key( $url ) ] ), 7 ), 400 );
	}

	public function test_the_consent_screen_escapes_client_data_and_shows_only_the_callback_origin(): void {
		$client = $this->http->rig->register_client( '<script>alert(1)</script> & Co' );
		$token = $this->pending_token( [ 'client_id' => $client ] );

		$outcome = $this->pages()->review( self::consent_query( $token ), 7 );
		self::assertSame( PageOutcome::VIEW, $outcome->kind );
		$html = AuthorizationPages::render( $outcome->view );

		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $html );
		// The port is part of an origin: another local process could hold another port.
		self::assertStringContainsString( '<code>http://127.0.0.1:7999</code>', $html );
		self::assertStringNotContainsString( '/callback', $html );
		self::assertMatchesRegularExpression( '/<form method="post" action="">/', $html );
		self::assertStringContainsString( 'name="approve"', $html );
		self::assertStringContainsString( 'name="deny"', $html );
		self::assertStringContainsString( 'value="test-nonce-' . AuthorizationPages::nonce_action( $token ) . '"', $html );
		self::assertStringContainsString( 'Stonewright Test', $html );
	}

	public function test_the_consent_screen_is_built_from_the_layer_with_one_primary_action(): void {
		$client  = $this->http->rig->register_client( 'Example editor client' );
		$token   = $this->pending_token( [ 'client_id' => $client ] );
		$outcome = $this->pages()->review( self::consent_query( $token ), 7 );
		$html    = AuthorizationPages::render( $outcome->view );

		self::assertStringContainsString( 'class="sw-ui sw-ui-page sw-oauth-consent"', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ), 'One h1.' );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Connect Example editor client to Stonewright Test?</h1>', $html );
		self::assertStringContainsString( 'sw-ui-kv', $html, 'The application, its identification and its destination are facts.' );
		self::assertSame( 1, substr_count( $html, 'sw-ui-btn--primary' ), 'Approve is the one primary action.' );
		self::assertMatchesRegularExpression( '/<button[^>]*sw-ui-btn--primary[^>]*name="approve"[^>]*>Approve</', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*name="deny"[^>]*>Deny</', $html );
		self::assertDoesNotMatchRegularExpression( '/sw-ui-btn--primary[^>]*name="deny"/', $html );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertStringNotContainsString( 'class="card"', $html, 'No core card markup.' );
		self::assertStringNotContainsString( 'scope: mcp', $html, 'The access is described in plain words.' );
		self::assertStringContainsString( 'Use the Stonewright MCP tools', $html );
	}

	public function test_a_client_that_registered_itself_is_flagged_because_anyone_can_choose_its_name(): void {
		$client  = $this->http->rig->register_client( 'Example editor client' );
		$token   = $this->pending_token( [ 'client_id' => $client ] );
		$outcome = $this->pages()->review( self::consent_query( $token ), 7 );
		$html    = AuthorizationPages::render( $outcome->view );

		self::assertStringContainsString( 'sw-ui-callout--warn', $html );
		self::assertStringContainsString( 'registered itself with this site', $html );
		self::assertStringContainsString( 'under any name', $html );

		$view                  = $outcome->view;
		$view['document_host'] = 'client.example.test';
		$published             = AuthorizationPages::render( $view );
		self::assertStringNotContainsString( 'sw-ui-callout--warn', $published, 'A client whose information a host publishes is not flagged.' );
		self::assertStringContainsString( 'Client information published by client.example.test', $published );
	}

	public function test_a_loopback_destination_says_it_is_on_this_computer(): void {
		$client  = $this->http->rig->register_client( 'Example editor client' );
		$token   = $this->pending_token( [ 'client_id' => $client ] );
		$view    = $this->pages()->review( self::consent_query( $token ), 7 )->view;
		$loop    = AuthorizationPages::render( $view );
		self::assertStringContainsString( 'This computer', $loop );

		$view['destination'] = 'https://client.example.test';
		self::assertStringNotContainsString( 'This computer', AuthorizationPages::render( $view ) );
	}

	/**
	 * @dataProvider callbacks_and_the_origin_the_consent_screen_shows
	 *
	 * @param string $registered The callback the client registered.
	 * @param string $requested  The callback the authorization request names.
	 * @param string $expected   What "Returns you to" shows.
	 */
	public function test_the_consent_screen_returns_you_to_the_callback_scheme_host_and_port( string $registered, string $requested, string $expected ): void {
		$client = $this->http->rig->clients->create(
			[
				'redirect_uris'              => [ $registered ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
				'client_name'                => 'Synthetic client',
			]
		)['client_id'];
		$token  = $this->pending_token( [ 'client_id' => $client, 'redirect_uri' => $requested ] );

		$outcome = $this->pages()->review( self::consent_query( $token ), 7 );
		self::assertSame( PageOutcome::VIEW, $outcome->kind );
		self::assertSame( $expected, $outcome->view['destination'] );

		$html = AuthorizationPages::render( $outcome->view );
		self::assertStringContainsString( '<code>' . $expected . '</code>', $html );
		self::assertStringNotContainsString( 'callback', $html );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function callbacks_and_the_origin_the_consent_screen_shows(): array {
		return [
			'loopback address with its registered port'  => [ 'http://127.0.0.1:7999/callback', 'http://127.0.0.1:7999/callback', 'http://127.0.0.1:7999' ],
			'loopback address with a port the client chose' => [ 'http://127.0.0.1:7999/callback', 'http://127.0.0.1:43210/callback', 'http://127.0.0.1:43210' ],
			'localhost with a port the client chose'     => [ 'http://localhost/callback', 'http://localhost:51234/callback', 'http://localhost:51234' ],
			'IPv6 loopback with a port'                  => [ 'http://[::1]/callback', 'http://[::1]:9090/callback', 'http://[::1]:9090' ],
			'HTTPS host without a port'                  => [ 'https://client.example.test/oauth/callback', 'https://client.example.test/oauth/callback', 'https://client.example.test' ],
			'HTTPS host with a port'                     => [ 'https://client.example.test:8443/oauth/callback', 'https://client.example.test:8443/oauth/callback', 'https://client.example.test:8443' ],
		];
	}

	public function test_an_unknown_or_foreign_consent_token_is_refused(): void {
		$token = $this->pending_token();

		self::assert_error_page( $this->pages()->review( self::consent_query( str_repeat( '0', 32 ) ), 7 ), 400 );
		self::assert_error_page( $this->pages()->review( 'page=stonewright-oauth-consent', 7 ), 400 );
		$GLOBALS['stonewright_test_current_user_id'] = 8;
		self::assert_error_page( $this->pages()->review( self::consent_query( $token ), 8 ), 400 );
		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 8 ), 400 );
		self::assertCount( 1, $this->http->rig->rows( 'consents' ) );
	}

	public function test_approval_returns_code_state_and_issuer_once(): void {
		$token = $this->pending_token();

		[ $base, $parameters, $names ] = self::returned( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 ) );

		self::assertSame( StorageRig::REDIRECT, $base );
		self::assertSame( [ 'code', 'state', 'iss' ], $names );
		self::assertMatchesRegularExpression( '/^def50200[0-9a-f]+$/D', $parameters['code'] );
		self::assertSame( TokenCodec::KIND_CODE, $this->http->storage->codec()->inspect( $parameters['code'] )->kind );
		self::assertSame( 'synthetic-state', $parameters['state'] );
		self::assertSame( 'https://example.test', $parameters['iss'] );
		self::assertSame( [], $this->http->rig->rows( 'consents' ) );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 60 ), $this->http->rig->rows( 'auth_codes' )[0]['expires_at'] );

		$tokens = ( new TokenEndpoint( $this->http->storage, $this->http->site ) )->handle( $this->http->form( [ 'grant_type' => 'authorization_code', 'code' => $parameters['code'], 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $this->client, 'code_verifier' => HttpRig::CHALLENGE_VERIFIER, 'resource' => StorageRig::RESOURCE ] ) );
		self::assertSame( 200, $tokens->status );

		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 ), 400 );
	}

	public function test_denial_returns_access_denied_state_and_issuer(): void {
		$token = $this->pending_token();

		[ $base, $parameters, $names ] = self::returned( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'deny' => '1' ], 7 ) );

		self::assertSame( StorageRig::REDIRECT, $base );
		self::assertSame( [ 'error', 'state', 'iss' ], $names );
		self::assertSame( 'access_denied', $parameters['error'] );
		self::assertSame( [], $this->http->rig->rows( 'consents' ) );
		self::assertSame( [], $this->http->rig->rows( 'auth_codes' ) );
	}

	public function test_a_failed_form_check_changes_nothing(): void {
		$token = $this->pending_token();
		$GLOBALS['stonewright_test_nonce_invalid'] = true;

		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 ), 403 );
		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ 'approve' => '1', 'deny' => '1' ], 7 ), 403 );
		self::assertCount( 1, $this->http->rig->rows( 'consents' ) );

		$GLOBALS['stonewright_test_nonce_invalid'] = false;
		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n' ], 7 ), 400 );
		self::assertSame( 'code', self::returned( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 ) )[2][0] );
	}

	public function test_a_user_who_lost_access_cannot_approve(): void {
		$token = $this->pending_token();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];

		self::assert_error_page( $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 ), 403 );
		self::assertCount( 1, $this->http->rig->rows( 'consents' ) );
	}

	public function test_state_and_callback_queries_survive_exactly(): void {
		$client = $this->http->rig->clients->create( [ 'redirect_uris' => [ 'https://client.example.test/cb?tenant=a' ], 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ], 'token_endpoint_auth_method' => 'none', 'client_name' => 'Hosted client' ] )['client_id'];
		$state = 'a b&c=d/é+%';
		$token = $this->pending_token( [ 'client_id' => $client, 'redirect_uri' => 'https://client.example.test/cb?tenant=a', 'state' => $state ] );

		$outcome = $this->pages()->decide( self::consent_query( $token ), [ '_wpnonce' => 'n', 'approve' => '1' ], 7 );

		self::assertStringStartsWith( 'https://client.example.test/cb?tenant=a&code=', $outcome->location );
		self::assertSame( $state, self::returned( $outcome )[1]['state'] );
	}

	public function test_the_page_hooks_render_the_form_and_stop_on_errors(): void {
		HttpSurface::use_site( $this->http->site );
		HttpSurface::use_documents( $this->http->documents );
		HttpSurface::use_limiter( $this->http->limiter );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		try {
			$token = $this->pending_token();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_SERVER['QUERY_STRING'] = self::consent_query( $token );

			AuthorizationPages::load_consent();
			ob_start();
			AuthorizationPages::render_page();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'name="approve"', $html );
			self::assertStringContainsString( AuthorizationPages::nonce_action( $token ), $html );

			$_SERVER['QUERY_STRING'] = $this->query( [ 'client_id' => 'ffffffffffffffffffffffffffffffff' ] );
			try {
				AuthorizationPages::load_authorize();
				self::fail( 'An unknown client must stop at an error page.' );
			} catch ( \RuntimeException $stopped ) {
				self::assertStringContainsString( 'not registered', $stopped->getMessage() );
			}
			$audited = array_column( array_column( $GLOBALS['stonewright_test_wpdb_inserts'], 'data' ), 'ability_name' );
			self::assertContains( 'oauth/authorize', $audited );
		} finally {
			HttpSurface::use_site( null );
			HttpSurface::use_documents( null );
			HttpSurface::use_limiter( null );
			AuthorizationLifecycle::use_storage( null );
			unset( $_SERVER['REQUEST_METHOD'], $_SERVER['QUERY_STRING'] );
		}
	}

	public function test_the_security_headers_match_the_observed_ones(): void {
		self::assertSame(
			[
				'X-Frame-Options'         => 'SAMEORIGIN',
				'Content-Security-Policy' => "frame-ancestors 'self';",
				'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			],
			AuthorizationPages::SECURITY_HEADERS
		);
	}
}

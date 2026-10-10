<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\IntrospectionEndpoint;
use Stonewright\WpMcp\Authorization\WordPress\OAuthReply;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\IntrospectionEndpoint
 */
final class IntrospectionEndpointTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$this->http = new HttpRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @param array<string, string|list<string>> $fields */
	private function introspect( array $fields, bool $authorized = true ): OAuthReply {
		return ( new IntrospectionEndpoint( $this->http->storage ) )->handle( $this->http->form( $fields ), $authorized );
	}

	private static function assert_inactive( OAuthReply $reply ): void {
		self::assertSame( 200, $reply->status );
		self::assertSame( [ 'active' => false ], $reply->body );
	}

	public function test_an_active_access_token_reports_the_observed_claims(): void {
		[ $client, $pair, $outcome ] = $this->http->rig->connect();
		$this->http->rig->at( StorageRig::T + 5 );

		$reply = $this->introspect( [ 'token' => $pair->access_token, 'token_type_hint' => 'access_token' ] );

		self::assertSame( 200, $reply->status );
		self::assertSame(
			[
				'active'    => true,
				'sub'       => '7',
				'scope'     => 'mcp',
				'jti'       => $outcome->issuance->access_key,
				'exp'       => StorageRig::T + 3600,
				'iat'       => StorageRig::T,
				'client_id' => $client,
			],
			$reply->body
		);
		self::assertSame( 'no-store', $reply->headers['Cache-Control'] );
		self::assertContains( $pair->access_token, $reply->audit['sensitive_values'] );
	}

	public function test_a_refresh_token_reports_its_real_state(): void {
		[ $client, $pair, $outcome ] = $this->http->rig->connect();

		$active = $this->introspect( [ 'token' => $pair->refresh_token ] );
		self::assertSame( [ 'active' => true, 'sub' => '7', 'scope' => 'mcp', 'exp' => $outcome->issuance->refresh_deadline, 'client_id' => $client ], $active->body );

		$this->http->rig->at( StorageRig::T + 10 );
		$rotated = $this->http->rig->refresh( $pair->refresh_token, $client );
		$next = $this->http->rig->codec->encode( $rotated->issuance );

		self::assert_inactive( $this->introspect( [ 'token' => $pair->refresh_token ] ) );
		self::assertTrue( $this->introspect( [ 'token' => $next->refresh_token ] )->body['active'] );
	}

	public function test_revoked_expired_unknown_and_code_credentials_are_inactive(): void {
		[ $client, $pair, $outcome ] = $this->http->rig->connect();

		self::assert_inactive( $this->introspect( [ 'token' => 'garbage' ] ) );
		self::assert_inactive( $this->introspect( [ 'token' => $this->http->rig->authorize( $client ) ] ) );
		$this->http->rig->at( StorageRig::T + 3600 );
		self::assert_inactive( $this->introspect( [ 'token' => $pair->access_token ] ) );
		$this->http->rig->at( StorageRig::T + 10 );
		$this->http->rig->access->revoke( $outcome->issuance->access_key );
		self::assert_inactive( $this->introspect( [ 'token' => $pair->access_token ] ) );
	}

	public function test_an_access_token_whose_subject_lost_access_is_inactive(): void {
		[ , $pair ] = $this->http->rig->connect();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];

		self::assert_inactive( $this->introspect( [ 'token' => $pair->access_token ] ) );
	}

	public function test_a_missing_token_is_an_invalid_request_and_callers_must_be_authorized(): void {
		$missing = $this->introspect( [ 'token_type_hint' => 'access_token' ] );
		self::assertSame( 400, $missing->status );
		self::assertSame( 'invalid_request', $missing->body['error'] );

		$refused = $this->introspect( [ 'token' => 'garbage' ], false );
		self::assertSame( 401, $refused->status );
		self::assertSame( 'invalid_client', $refused->body['error'] );
	}

	public function test_a_metadata_document_client_is_reported_by_its_url(): void {
		$url = 'https://client.example.test/oauth/client.json';
		$this->http->publish_document( $url, [ 'client_id' => $url, 'redirect_uris' => [ StorageRig::REDIRECT ] ] );
		$key = $this->http->documents->resolve( $url )['client_id'];
		$outcome = $this->http->rig->exchange( $this->http->rig->authorize( $key ), $key );
		$pair = $this->http->rig->codec->encode( $outcome->issuance );

		self::assertSame( $url, $this->introspect( [ 'token' => $pair->access_token ] )->body['client_id'] );
	}
}

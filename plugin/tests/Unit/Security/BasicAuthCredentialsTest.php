<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\BasicAuthCredentials;

/**
 * @covers \Stonewright\WpMcp\Security\BasicAuthCredentials
 */
final class BasicAuthCredentialsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = [];

	protected function setUp(): void {
		$this->server_backup = $_SERVER;
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
	}

	public function test_hydrates_php_auth_from_basic_authorization_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'admin:abcd efgh ijkl' );

		BasicAuthCredentials::hydrate();

		self::assertSame( 'admin', $_SERVER['PHP_AUTH_USER'] ?? null );
		self::assertSame( 'abcd efgh ijkl', $_SERVER['PHP_AUTH_PW'] ?? null );
	}

	public function test_hydrates_from_redirect_authorization_when_http_header_is_empty(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'editor:token-value' );

		BasicAuthCredentials::hydrate();

		self::assertSame( 'editor', $_SERVER['PHP_AUTH_USER'] ?? null );
		self::assertSame( 'token-value', $_SERVER['PHP_AUTH_PW'] ?? null );
	}

	public function test_does_not_overwrite_existing_php_auth(): void {
		$_SERVER['PHP_AUTH_USER']      = 'already';
		$_SERVER['PHP_AUTH_PW']        = 'set';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'admin:other' );

		BasicAuthCredentials::hydrate();

		self::assertSame( 'already', $_SERVER['PHP_AUTH_USER'] );
		self::assertSame( 'set', $_SERVER['PHP_AUTH_PW'] );
	}

	public function test_ignores_bearer_headers(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-basic-token';

		BasicAuthCredentials::hydrate();

		self::assertArrayNotHasKey( 'PHP_AUTH_USER', $_SERVER );
		self::assertArrayNotHasKey( 'PHP_AUTH_PW', $_SERVER );
	}

	public function test_passthrough_keeps_the_incoming_user_id(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'admin:secret' );

		self::assertSame( 7, BasicAuthCredentials::hydrate_then_passthrough( 7 ) );
		self::assertSame( 'admin', $_SERVER['PHP_AUTH_USER'] ?? null );
	}
}

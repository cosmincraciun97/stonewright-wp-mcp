<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class OutcomeContractTest extends TestCase {

	public function test_failure_cannot_carry_a_family_creation_effect(): void {
		$this->expectException( \InvalidArgumentException::class );
		new CodeExchangeOutcome( AuthorizationCodeDecisionTest::code(), SyntheticFamilies::initial(), null, new OAuthFault( 'invalid_grant' ) );
	}

	public function test_active_family_cannot_deserialize_invalid_scope_authority(): void {
		$this->expectException( OAuthFault::class );
		SyntheticFamilies::initial( [ 'consented_scopes' => [ "mcp\r\nInjected" ] ] );
	}

	public function test_server_error_is_always_reported_with_http_500(): void {
		self::assertSame( 500, ( new OAuthFault( 'server_error' ) )->status() );
		self::assertSame( 400, ( new OAuthFault( 'invalid_grant' ) )->status() );
		self::assertSame( 401, ( new OAuthFault( 'invalid_client', 401 ) )->status() );
		$this->expectException( \InvalidArgumentException::class );
		new OAuthFault( 'server_error', 400 );
	}

	public function test_resource_paths_do_not_accept_authority_only_brackets(): void {
		$this->expectException( OAuthFault::class );
		( new \Stonewright\WpMcp\Authorization\Protocol\ResourceRules() )->require_authorized( [ 'https://example.test/mcp[1]' ], [ 'https://example.test/mcp[1]' ] );
	}
}

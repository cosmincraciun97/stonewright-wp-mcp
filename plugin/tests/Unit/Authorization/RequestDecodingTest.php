<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;

final class RequestDecodingTest extends TestCase {

	public function test_extension_lists_are_retained_and_singletons_cannot_repeat(): void {
		$bag = ( new RequestDecoder() )->form( 'resource=https%3A%2F%2Fexample.test%2Fa&resource=https%3A%2F%2Fexample.test%2Fb&state=a%2520b+c', 512 );
		self::assertSame( [ 'https://example.test/a', 'https://example.test/b' ], $bag->values( 'resource' ) );
		self::assertSame( 'a%20b c', $bag->one( 'state' ) );
		$this->expectException( OAuthFault::class );
		( new RequestDecoder() )->form( 'grant_type=one&grant_type=two', 512 )->one( 'grant_type' );
	}

	/** @dataProvider bad_form_inputs */
	public function test_malformed_or_oversized_input_is_rejected( string $body, int $limit ): void {
		$this->expectException( OAuthFault::class );
		( new RequestDecoder() )->form( $body, $limit );
	}

	public function bad_form_inputs(): array {
		return [ [ 'state=%XZ', 512 ], [ 'state=%', 512 ], [ 'abcdef', 5 ], [ 'a=1', 0 ], [ 'a[]=one', 512 ], [ 'a%00b=one', 512 ] ];
	}

	/** @dataProvider bad_json_inputs */
	public function test_registration_requires_a_bounded_json_object( string $body, int $limit ): void {
		$this->expectException( OAuthFault::class );
		( new RequestDecoder() )->registration( $body, $limit );
	}

	public function bad_json_inputs(): array {
		return [ [ '[]', 512 ], [ 'null', 512 ], [ '{', 512 ], [ '{"a":1}', 2 ], [ '{"a":1,"a":2}', 512 ] ];
	}

	public function test_parameters_sent_without_a_value_are_treated_as_omitted(): void {
		$bag = ( new RequestDecoder() )->form( 'grant_type=refresh_token&scope=&resource=&resource=https%3A%2F%2Fexample.test%2Fmcp', 512 );
		self::assertNull( $bag->one( 'scope' ) );
		self::assertSame( [], $bag->values( 'scope' ) );
		self::assertSame( [ 'https://example.test/mcp' ], $bag->values( 'resource' ) );
		self::assertSame( 'refresh_token', $bag->one( 'grant_type' ) );
	}

	public function test_incomplete_duplicate_member_scan_rejects_the_registration(): void {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '10' );
		try {
			( new RequestDecoder() )->registration( '{"client_name":"' . str_repeat( 'a', 20000 ) . '"}', 65536 );
			self::fail( 'A scan that cannot finish must not accept the body.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_request', $fault->error() );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $limit );
		}
	}

	public function test_registration_retains_metadata_types_for_validation(): void {
		self::assertSame( [ 'redirect_uris' => [ 'https://example.test/callback' ] ], ( new RequestDecoder() )->registration( '{"redirect_uris":["https://example.test/callback"]}', 512 ) );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\RedirectRules;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;

final class RedirectAndProofTest extends TestCase {

	public function test_native_loopback_port_exception_preserves_the_actual_approved_uri(): void {
		$rules = new RedirectRules();
		self::assertSame( 'http://127.0.0.1:43123/callback?x=1', $rules->approve( 'http://127.0.0.1:43123/callback?x=1', [ 'http://127.0.0.1/callback?x=1' ], true ) );
		self::assertSame( 'http://[::1]:43123/callback', $rules->approve( 'http://[::1]:43123/callback', [ 'http://[::1]/callback' ], true ) );
		$this->expectException( OAuthFault::class );
		$rules->require_exchange_match( 'http://127.0.0.1:43124/callback', 'http://127.0.0.1:43123/callback' );
	}

	/** @dataProvider rejected_redirects */
	public function test_port_matching_cannot_authorize_another_destination( string $requested, string $registered, bool $native ): void {
		$this->expectException( OAuthFault::class );
		( new RedirectRules() )->approve( $requested, [ $registered ], $native );
	}

	public function rejected_redirects(): array {
		return [
			[ 'http://127.0.0.1:42/other', 'http://127.0.0.1/callback', true ],
			[ 'http://localhost:42/callback', 'http://127.0.0.1/callback', true ],
			[ 'http://[::1]:42/callback', 'http://127.0.0.1/callback', true ],
			[ 'http://127.0.0.1:42/callback?a=2', 'http://127.0.0.1/callback?a=1', true ],
			[ 'http://127.0.0.1:42/callback', 'http://127.0.0.1/callback', false ],
			[ 'https://example.test:42/callback', 'https://example.test/callback', true ],
			[ 'https://example.test/callback#fragment', 'https://example.test/callback#fragment', true ],
			[ 'https://user@example.test/callback', 'https://user@example.test/callback', true ],
			[ 'http://[::1/callback', 'http://[::1/callback', true ],
			[ 'http://example.test/callback', 'http://example.test/callback', true ],
			[ 'javascript:alert(1)', 'javascript:alert(1)', true ],
		];
	}

	public function test_s256_matches_the_public_rfc_vector(): void {
		$proof = new CodeProof();
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		self::assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $proof->challenge( $verifier ) );
		$proof->require_match( $verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' );
		self::assertSame( 43, strlen( $proof->challenge( str_repeat( 'a', 128 ) ) ) );
	}

	/** @dataProvider rejected_proofs */
	public function test_pkce_rejects_invalid_verifiers_and_downgrades( string $verifier, string $method, string $challenge ): void {
		$this->expectException( OAuthFault::class );
		$proof = new CodeProof();
		$proof->require_s256( $method, $challenge );
		$proof->require_match( $verifier, $challenge );
	}

	public function rejected_proofs(): array {
		return [ [ str_repeat( 'a', 42 ), 'S256', str_repeat( 'a', 43 ) ], [ str_repeat( 'a', 129 ), 'S256', str_repeat( 'a', 43 ) ], [ str_repeat( '+', 43 ), 'S256', str_repeat( 'a', 43 ) ], [ str_repeat( 'a', 43 ), 'plain', str_repeat( 'a', 43 ) ], [ str_repeat( 'a', 43 ), 'S256', str_repeat( 'a', 42 ) ], [ str_repeat( 'a', 43 ), 'S256', str_repeat( 'a', 43 ) ] ];
	}
}

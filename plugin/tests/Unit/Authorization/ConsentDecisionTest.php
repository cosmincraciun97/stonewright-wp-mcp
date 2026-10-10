<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;

final class ConsentDecisionTest extends TestCase {

	public static function pending( array $changes = [] ): array {
		return array_replace( [ 'pending_key' => 'pending-a', 'client_key' => 'client-a', 'redirect_uri' => 'http://127.0.0.1:49152/callback', 'registered_redirects' => [ 'http://127.0.0.1/callback' ], 'native_client' => true, 'code_challenge' => ( new CodeProof() )->challenge( str_repeat( 'a', 43 ) ), 'code_challenge_method' => 'S256', 'scopes' => [ 'mcp', 'read' ], 'resources' => [ 'https://example.test/mcp' ], 'state' => 'state-a', 'expires_at' => 1700000600, 'used' => false ], $changes );
	}

	public function test_approval_preserves_the_actual_callback_and_binds_the_authenticated_subject(): void {
		$outcome = ( new ConsentDecision() )->decide( self::pending(), 'subject-a', true, true, true, 1700000000, 'code-a' );
		self::assertTrue( $outcome['pending']['used'] );
		self::assertSame( 'subject-a', $outcome['code']['subject_key'] );
		self::assertSame( 'http://127.0.0.1:49152/callback', $outcome['code']['redirect_uri'] );
		self::assertSame( 'state-a', $outcome['redirect_parameters']['state'] );
		self::assertFalse( $outcome['code']['used'] );
		self::assertSame( [ 'mcp', 'read' ], $outcome['code']['scopes'] );
	}

	public function test_denial_consumes_the_consent_transaction_without_creating_a_code(): void {
		$outcome = ( new ConsentDecision() )->decide( self::pending(), 'subject-a', true, true, false, 1700000000, 'unused' );
		self::assertTrue( $outcome['pending']['used'] );
		self::assertNull( $outcome['code'] );
		self::assertSame( [ 'error' => 'access_denied', 'state' => 'state-a' ], $outcome['redirect_parameters'] );
	}

	/** @dataProvider denied_contexts */
	public function test_invalid_context_never_produces_a_redirect_or_a_code( array $changes, string $subject, bool $form, bool $allowed, int $now ): void {
		$this->expectException( OAuthFault::class );
		( new ConsentDecision() )->decide( self::pending( $changes ), $subject, $form, $allowed, true, $now, 'code-a' );
	}

	public function denied_contexts(): array {
		return [ [ [], '', true, true, 1700000000 ], [ [], 'subject-a', false, true, 1700000000 ], [ [], 'subject-a', true, false, 1700000000 ], [ [ 'used' => true ], 'subject-a', true, true, 1700000000 ], [ [], 'subject-a', true, true, 1700000600 ], [ [ 'redirect_uri' => 'https://attacker.example/callback' ], 'subject-a', true, true, 1700000000 ], [ [ 'code_challenge_method' => 'plain' ], 'subject-a', true, true, 1700000000 ] ];
	}
}

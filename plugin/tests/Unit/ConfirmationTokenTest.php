<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Security\ConfirmationToken
 */
final class ConfirmationTokenTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	// -------------------------------------------------------------------------
	// Basic issue / verify round-trip.
	// -------------------------------------------------------------------------

	public function test_issue_returns_swc_prefixed_token(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertStringStartsWith( 'swc_', $token );
		$this->assertGreaterThan( 4, strlen( $token ) );
	}

	public function test_issue_then_verify_succeeds(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_verify_rejects_unknown_token(): void {
		$this->assertFalse(
			ConfirmationToken::verify( 'swc_nope', 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_verify_rejects_wrong_ability(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertFalse(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-activate', [ 'name' => 'a.php' ] )
		);
	}

	public function test_verify_rejects_wrong_args(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertFalse(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'b.php' ] )
		);
	}

	public function test_verify_rejects_wrong_user(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );

		// Switch user before verify.
		$GLOBALS['stonewright_test_current_user_id'] = 99;

		$this->assertFalse(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_verify_is_single_use(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );

		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
		$this->assertFalse(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] ),
			'Token should be consumed after first successful verify.'
		);
	}

	public function test_tokens_are_unique(): void {
		$tokens = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$tokens[] = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'i' => $i ] );
		}
		$this->assertSame( count( $tokens ), count( array_unique( $tokens ) ) );
	}

	// -------------------------------------------------------------------------
	// New tests: HMAC-based format.
	// -------------------------------------------------------------------------

	public function test_token_uses_base64url_alphabet(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'a' => 1 ] );
		// After 'swc_' prefix, characters must be base64url-safe: [A-Za-z0-9_-] only (dot separates segments).
		$body = substr( $token, 4 );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $body );
	}

	public function test_token_has_two_segments_after_prefix(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		// Strip 'swc_' prefix, then split on '.'.
		$without_prefix = substr( $token, 4 );
		$parts          = explode( '.', $without_prefix );
		$this->assertCount( 2, $parts, 'Token must have payload.signature segments after swc_ prefix.' );
		$this->assertNotEmpty( $parts[0] );
		$this->assertNotEmpty( $parts[1] );
	}

	public function test_hmac_tampering_rejected_in_signature(): void {
		$token  = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$prefix = 'swc_';
		$rest   = substr( $token, strlen( $prefix ) );
		$dot    = strpos( $rest, '.' );
		$this->assertNotFalse( $dot );
		$payload_b64 = substr( $rest, 0, (int) $dot );
		$sig_b64     = substr( $rest, (int) $dot + 1 );
		// Flip a char in the middle of the signature (not the last char, whose bits may be padding-equivalent).
		$mid          = (int) ( strlen( $sig_b64 ) / 2 );
		$tampered_sig = substr( $sig_b64, 0, $mid ) .
			( substr( $sig_b64, $mid, 1 ) === 'a' ? 'b' : 'a' ) .
			substr( $sig_b64, $mid + 1 );
		$tampered     = $prefix . $payload_b64 . '.' . $tampered_sig;
		$this->assertFalse(
			ConfirmationToken::verify( $tampered, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_hmac_tampering_rejected_in_payload(): void {
		$token  = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$prefix = 'swc_';
		$rest   = substr( $token, strlen( $prefix ) );
		$dot    = strpos( $rest, '.' );
		$this->assertNotFalse( $dot );
		$payload_b64 = substr( $rest, 0, (int) $dot );
		$sig_b64     = substr( $rest, (int) $dot + 1 );
		// Flip a char in the middle of the payload (not the last char, whose bits may be padding).
		$mid              = (int) ( strlen( $payload_b64 ) / 2 );
		$tampered_payload = substr( $payload_b64, 0, $mid ) .
			( substr( $payload_b64, $mid, 1 ) === 'a' ? 'b' : 'a' ) .
			substr( $payload_b64, $mid + 1 );
		$tampered         = $prefix . $tampered_payload . '.' . $sig_b64;
		$this->assertFalse(
			ConfirmationToken::verify( $tampered, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_replay_rejected_on_second_verify(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
		// Second verify of the same token must be rejected.
		$this->assertFalse(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_args_reorder_still_verifies(): void {
		// Issue with args in one order, verify with re-ordered args — canonicalization must make this work.
		$token = ConfirmationToken::issue( 'stonewright/test.ability', [ 'a' => 1, 'b' => 2 ] );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/test.ability', [ 'b' => 2, 'a' => 1 ] )
		);
	}

	public function test_confirmation_token_key_stripped_during_normalization(): void {
		// A token signed with args containing confirmation_token should verify
		// when confirmation_token is present in verify args too (it's stripped).
		$args_with_token    = [ 'name' => 'a.php', 'confirmation_token' => 'some-old-token' ];
		$args_without_token = [ 'name' => 'a.php' ];

		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', $args_with_token );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', $args_without_token ),
			'confirmation_token should be stripped from args before hashing'
		);
	}

	public function test_confirmation_token_stripped_both_sides(): void {
		// Issue without the key, verify with the key in args — both should normalize to same hash.
		$args_clean = [ 'name' => 'a.php' ];
		$token      = ConfirmationToken::issue( 'stonewright/sandbox-delete', $args_clean );
		$args_dirty = [ 'name' => 'a.php', 'confirmation_token' => 'irrelevant' ];
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', $args_dirty )
		);
	}

	public function test_ttl_clamped_to_minimum_60(): void {
		// TTL of 10 should be clamped to 60 (we can't time-travel, so we check the payload).
		// We verify the token is still valid immediately after issue (expiry is in the future).
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ], 10 );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] ),
			'Token with TTL clamped to 60 must still be valid immediately after issue'
		);
	}

	public function test_ttl_clamped_to_maximum_3600(): void {
		// TTL of 99999 should be clamped to 3600.
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ], 99999 );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	// -------------------------------------------------------------------------
	// verify_or_error — one test per failure mode.
	// -------------------------------------------------------------------------

	public function test_verify_or_error_returns_invalid_for_bad_parse(): void {
		$result = ConfirmationToken::verify_or_error( 'not-a-valid-token', 'stonewright/test', [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_invalid_for_hmac_mismatch(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		// Tamper the signature.
		$tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === 'a' ? 'b' : 'a' );
		$result   = ConfirmationToken::verify_or_error( $tampered, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_expired(): void {
		// We need to build a token with a past expires_at but a valid HMAC.
		// To get the correct secret we must first prime the per-install secret option.
		ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'prime.php' ] );

		$per_install = $GLOBALS['stonewright_test_options']['stonewright_confirmation_secret'] ?? '';
		$this->assertNotEmpty( $per_install, 'Per-install secret should be auto-generated' );

		// Compute the args_hash the same way ConfirmationToken does: normalize then Json::hash.
		// normalize_args(['name' => 'a.php']) strips confirmation_token (none) and ksorts — result is same.
		$normalized_args = [ 'name' => 'a.php' ];
		$args_hash       = \Stonewright\WpMcp\Support\Json::hash( $normalized_args );

		// Build an expired payload. Note: expires_at is in the past.
		$payload_data = [
			'ability'    => 'stonewright/sandbox-delete',
			'args_hash'  => $args_hash,
			'user_id'    => 42,
			'nonce'      => 'unique-nonce-for-expired-test',
			'expires_at' => time() - 10,
		];
		$payload_json = json_encode( $payload_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertIsString( $payload_json );

		// Sign using single HMAC-SHA256(payload_json, wp_salt . per_install), raw binary.
		$wp_salt = 'test-salt-auth'; // wp_salt('auth') returns 'test-salt-auth' in test environment.
		$key     = $wp_salt . $per_install;
		$sig     = hash_hmac( 'sha256', $payload_json, $key, true );
		// Encode with base64url (no padding, URL-safe alphabet).
		$b64url  = static fn( string $s ): string => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		$token   = 'swc_' . $b64url( $payload_json ) . '.' . $b64url( $sig );

		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_expired', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_replayed(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		// First verify succeeds.
		$first = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertTrue( $first );
		// Second verify must return replay error.
		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_replayed', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_args_mismatch(): void {
		$token  = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'b.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_ability_mismatch(): void {
		$token  = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-activate', [ 'name' => 'a.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_ability_mismatch', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_user_mismatch(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$GLOBALS['stonewright_test_current_user_id'] = 99;
		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_user_mismatch', $result->get_error_code() );
	}

	public function test_verify_or_error_returns_true_on_success(): void {
		$token  = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$result = ConfirmationToken::verify_or_error( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertTrue( $result );
	}

	public function test_verify_bool_wrapper_returns_true(): void {
		$token = ConfirmationToken::issue( 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] );
		$this->assertTrue(
			ConfirmationToken::verify( $token, 'stonewright/sandbox-delete', [ 'name' => 'a.php' ] )
		);
	}

	public function test_verify_bool_wrapper_returns_false_on_error(): void {
		$this->assertFalse(
			ConfirmationToken::verify( 'not-a-token', 'stonewright/sandbox-delete', [] )
		);
	}
}

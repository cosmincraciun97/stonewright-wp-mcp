<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\Security\CustomCodeGrant
 */
final class CustomCodeGrantTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode' => 'development',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		unset( $GLOBALS['stonewright_test_home_url'] );
	}

	// -- Giving a consumed grant back after a rollback that was not the change's doing ----------------

	private const CANDIDATE = '<?php // the approved bytes';

	/** @return array{token:string,grant_id:string,hash:string} An issued grant for CANDIDATE on functions.php, consumed once. */
	private function consumed_grant(): array {
		$hash   = hash( 'sha256', self::CANDIDATE );
		$issued = CustomCodeGrant::issue( [ 'path' => 'functions.php', 'after_sha256' => $hash, 'language' => 'php', 'high_risk' => true ] );
		self::assertIsArray( $issued );
		self::assertTrue( CustomCodeGrant::verify_and_consume( (string) $issued['token'], 'functions.php', $hash, 'php', 20, true ) );
		return [ 'token' => (string) $issued['token'], 'grant_id' => (string) $issued['grant_id'], 'hash' => $hash ];
	}

	/** @param array<string, mixed>|\WP_Error $result */
	private static function assert_release_refused( $result, string $why ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_grant_release_refused', $result->get_error_code() );
		self::assertSame( $why, $result->get_error_data()['reason'] );
	}

	public function test_a_consumed_grant_is_released_once_and_applies_the_same_bytes_once_more_within_its_lifetime(): void {
		$grant   = $this->consumed_grant();
		$key     = CustomCodeGrant::TRANSIENT_PREFIX . $grant['grant_id'];
		$expires = (int) $GLOBALS['stonewright_test_transients'][ $key ]['expires_at'];

		self::assertTrue( CustomCodeGrant::release( $grant['token'], 'functions.php', $grant['hash'], 'php', 'rolled_back_unattributed' ) );

		$stored = $GLOBALS['stonewright_test_transients'][ $key ];
		self::assertFalse( $stored['used'] );
		self::assertSame( 'rolled_back_unattributed', $stored['release_reason'] );
		self::assertGreaterThan( 0, $stored['released_at'] );
		self::assertSame( $expires, (int) $stored['expires_at'], 'The expiry does not move.' );
		self::assertLessThanOrEqual( $expires - time(), $GLOBALS['stonewright_test_transient_ttls'][ $key ], 'It is stored for what is left of its lifetime, no more.' );

		self::assert_release_refused( CustomCodeGrant::release( $grant['token'], 'functions.php', $grant['hash'], 'php', 'again' ), 'not_used' );
		self::assertTrue( CustomCodeGrant::verify_and_consume( $grant['token'], 'functions.php', $grant['hash'], 'php', 20, true ), 'The same bytes go in once more.' );
		$reuse = CustomCodeGrant::verify_and_consume( $grant['token'], 'functions.php', $grant['hash'], 'php', 20, true );
		self::assertInstanceOf( \WP_Error::class, $reuse );
		self::assertSame( 'stonewright_custom_code_grant_reused', $reuse->get_error_code(), 'And not a third time.' );
		self::assert_release_refused( CustomCodeGrant::release( $grant['token'], 'functions.php', $grant['hash'], 'php', 'rolled_back_unattributed' ), 'already_released' );
		self::assertTrue( $GLOBALS['stonewright_test_transients'][ $key ]['used'], 'A grant is released at most once: the second apply spent it for good.' );
	}

	/** @dataProvider releaseMismatchProvider */
	public function test_a_release_is_refused_when_the_write_does_not_match_the_grant( string $why, callable $arrange ): void {
		$grant = $this->consumed_grant();
		$args  = $arrange( $grant );

		self::assert_release_refused( CustomCodeGrant::release( $grant['token'], $args['path'], $args['hash'], $args['language'], 'rolled_back_unattributed' ), $why );
		self::assertTrue( $GLOBALS['stonewright_test_transients'][ CustomCodeGrant::TRANSIENT_PREFIX . $grant['grant_id'] ]['used'], 'The grant stays spent.' );
	}

	/** @return array<string, array{0:string,1:callable}> */
	public static function releaseMismatchProvider(): array {
		$same = static fn ( array $grant ): array => [ 'path' => 'functions.php', 'hash' => $grant['hash'], 'language' => 'php' ];
		return [
			'another hash'     => [ 'hash_mismatch', static fn ( array $grant ): array => array_merge( $same( $grant ), [ 'hash' => hash( 'sha256', 'other bytes' ) ] ) ],
			'another path'     => [ 'path_mismatch', static fn ( array $grant ): array => array_merge( $same( $grant ), [ 'path' => 'inc/other.php' ] ) ],
			'another language' => [ 'language_mismatch', static fn ( array $grant ): array => array_merge( $same( $grant ), [ 'language' => 'css' ] ) ],
			'another user'     => [
				'user_mismatch',
				static function ( array $grant ) use ( $same ): array {
					$GLOBALS['stonewright_test_current_user_id'] = 10;
					return $same( $grant );
				},
			],
			'another site'     => [
				'site_mismatch',
				static function ( array $grant ) use ( $same ): array {
					$GLOBALS['stonewright_test_home_url'] = 'https://other.example.test/';
					return $same( $grant );
				},
			],
		];
	}

	public function test_a_release_is_refused_for_a_grant_that_was_not_used_or_has_expired(): void {
		$hash   = hash( 'sha256', self::CANDIDATE );
		$issued = CustomCodeGrant::issue( [ 'path' => 'functions.php', 'after_sha256' => $hash, 'language' => 'php' ] );
		self::assertIsArray( $issued );
		self::assert_release_refused( CustomCodeGrant::release( (string) $issued['token'], 'functions.php', $hash, 'php', 'rolled_back_unattributed' ), 'not_used' );

		$grant = $this->consumed_grant();
		$GLOBALS['stonewright_test_transients'][ CustomCodeGrant::TRANSIENT_PREFIX . $grant['grant_id'] ]['expires_at'] = time() - 1;
		self::assert_release_refused( CustomCodeGrant::release( $grant['token'], 'functions.php', $grant['hash'], 'php', 'rolled_back_unattributed' ), 'expired' );
	}

	public function test_a_release_is_refused_for_a_token_that_is_not_a_grant(): void {
		self::assert_release_refused( CustomCodeGrant::release( 'not.a.token', 'functions.php', hash( 'sha256', 'x' ), 'php', 'rolled_back_unattributed' ), 'invalid_token' );

		$grant = $this->consumed_grant();
		unset( $GLOBALS['stonewright_test_transients'][ CustomCodeGrant::TRANSIENT_PREFIX . $grant['grant_id'] ] );
		self::assert_release_refused( CustomCodeGrant::release( $grant['token'], 'functions.php', $grant['hash'], 'php', 'rolled_back_unattributed' ), 'missing' );
	}

	public function test_grant_binds_hash_and_is_single_use(): void {
		$hash = hash( 'sha256', '<?php // candidate' );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => 'functions.php',
				'after_sha256' => $hash,
				'language'     => 'php',
			]
		);
		self::assertIsArray( $issued );
		self::assertArrayHasKey( 'token', $issued );

		$ok = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'functions.php',
			$hash,
			'php',
			100
		);
		self::assertTrue( $ok );

		$reuse = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'functions.php',
			$hash,
			'php',
			100
		);
		self::assertInstanceOf( \WP_Error::class, $reuse );
		self::assertSame( 'stonewright_custom_code_grant_reused', $reuse->get_error_code() );
	}

	public function test_hash_mismatch_rejected(): void {
		$hash = hash( 'sha256', 'a' );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => 'functions.php',
				'after_sha256' => $hash,
				'language'     => 'php',
			]
		);
		self::assertIsArray( $issued );

		$bad = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'functions.php',
			hash( 'sha256', 'b' ),
			'php',
			10
		);
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'stonewright_custom_code_grant_hash_mismatch', $bad->get_error_code() );
	}

	public function test_path_mismatch_rejected(): void {
		$hash = hash( 'sha256', 'a' );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => 'functions.php',
				'after_sha256' => $hash,
				'language'     => 'php',
			]
		);
		self::assertIsArray( $issued );

		$bad = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'style.css',
			$hash,
			'php',
			10
		);
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'stonewright_custom_code_grant_path_mismatch', $bad->get_error_code() );
	}

	public function test_missing_grant_proposal_is_unapplied(): void {
		$proposal = CustomCodeGrant::missing_grant_proposal( [ 'path' => 'functions.php' ] );
		self::assertFalse( $proposal['applied'] );
		self::assertTrue( $proposal['approval_required'] );
		self::assertTrue( $proposal['agent_must_stop'] );
		self::assertStringContainsString( 'Do not open the approval page', $proposal['message'] );
	}

	public function test_standard_grant_cannot_authorize_high_risk_php(): void {
		$hash = hash( 'sha256', 'candidate' );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => 'functions.php',
				'after_sha256' => $hash,
				'language'     => 'php',
				'high_risk'    => false,
			]
		);
		self::assertIsArray( $issued );

		$result = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'functions.php',
			$hash,
			'php',
			10,
			true
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_grant_risk_mismatch', $result->get_error_code() );
	}

	public function test_dry_run_proposal_can_be_approved_once_and_binds_requesting_user(): void {
		$hash = hash( 'sha256', '<?php // approved candidate' );
		$proposal = CustomCodeGrant::stage_proposal(
			[
				'path'              => 'functions.php',
				'after_sha256'      => $hash,
				'before_sha256'     => hash( 'sha256', '<?php // before' ),
				'language'          => 'php',
				'changed_bytes'     => 12,
				'max_changed_bytes' => 100,
				'risk_class'        => 'high_risk_active_theme_php',
				'native_gap'        => [
					'reason'        => 'No registered WordPress hook owns this site-specific behavior.',
					'methods_tried' => [ 'typed_api', 'admin_form' ],
				],
				'diff_preview'      => [ 'changed_lines' => 1, 'preview' => '+ approved candidate' ],
			]
		);

		self::assertIsArray( $proposal );
		self::assertStringContainsString( 'stonewright-custom-code-approval', $proposal['approval_url'] );

		$issued = CustomCodeGrant::approve_proposal( (string) $proposal['proposal_id'] );
		self::assertIsArray( $issued );

		$second = CustomCodeGrant::approve_proposal( (string) $proposal['proposal_id'] );
		self::assertInstanceOf( \WP_Error::class, $second );

		$verified = CustomCodeGrant::verify_and_consume(
			(string) $issued['token'],
			'functions.php',
			$hash,
			'php',
			12
		);
		self::assertTrue( $verified );
	}
}

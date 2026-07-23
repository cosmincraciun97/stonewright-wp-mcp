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
	}
}

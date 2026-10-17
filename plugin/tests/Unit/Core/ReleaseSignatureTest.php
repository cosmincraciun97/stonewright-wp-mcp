<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\ReleaseSignature;

/**
 * Every signing key here is a throwaway pair generated inside the test and never
 * stored; the RFC 8032 vectors carry public data only.
 *
 * @covers \Stonewright\WpMcp\Core\ReleaseSignature
 */
final class ReleaseSignatureTest extends TestCase {

	private const MANIFEST = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  stonewright-1.3.0-beta.30.zip\nbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  stonewright-companion-1.3.0-beta.30.tgz\n";

	public function test_a_signature_by_any_trusted_key_verifies_the_exact_manifest(): void {
		$active = self::throwaway_key();
		$backup = self::throwaway_key();

		self::assertTrue( ReleaseSignature::verify( self::MANIFEST, self::signature_line( self::MANIFEST, $active ), [ $active['public'], $backup['public'] ] ) );
		self::assertTrue( ReleaseSignature::verify( self::MANIFEST, self::signature_line( self::MANIFEST, $backup ), [ $active['public'], $backup['public'] ] ) );
	}

	public function test_lines_that_are_not_a_signature_are_skipped(): void {
		$old = self::throwaway_key();
		$new = self::throwaway_key();
		$file = "# signed for a key change\r\n"
			. rtrim( self::signature_line( self::MANIFEST, $old ) ) . "\r\n"
			. "a later format line\r\n"
			. self::signature_line( self::MANIFEST, $new );

		self::assertTrue( ReleaseSignature::verify( self::MANIFEST, $file, [ $new['public'] ] ) );
	}

	public function test_a_changed_manifest_or_an_untrusted_key_is_refused(): void {
		$trusted   = self::throwaway_key();
		$untrusted = self::throwaway_key();
		$tampered  = str_replace( 'aaaa', 'cccc', self::MANIFEST );

		$changed = ReleaseSignature::verify( $tampered, self::signature_line( self::MANIFEST, $trusted ), [ $trusted['public'] ] );
		$foreign = ReleaseSignature::verify( self::MANIFEST, self::signature_line( self::MANIFEST, $untrusted ), [ $trusted['public'] ] );

		foreach ( [ $changed, $foreign ] as $result ) {
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_update_signature_invalid', $result->get_error_code() );
		}
	}

	/**
	 * @dataProvider malformed_signature_files
	 */
	public function test_a_file_without_one_well_formed_signature_is_malformed( string $file ): void {
		$key = self::throwaway_key();

		$result = ReleaseSignature::verify( self::MANIFEST, $file, [ $key['public'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_update_signature_malformed', $result->get_error_code() );
	}

	/** @return iterable<string, array{string}> */
	public static function malformed_signature_files(): iterable {
		yield 'empty' => [ '' ];
		yield 'whitespace' => [ " \n\r\n" ];
		yield 'not base64' => [ str_repeat( '!', 86 ) . "==\n" ];
		yield 'raw signature bytes' => [ str_repeat( "\x01", 64 ) ];
		yield 'too short' => [ base64_encode( str_repeat( "\x01", 63 ) ) . "\n" ];
		yield 'too long' => [ base64_encode( str_repeat( "\x01", 65 ) ) . "\n" ];
		yield 'html error page' => [ "<!DOCTYPE html>\n<title>Not Found</title>\n" ];
	}

	public function test_an_oversized_file_is_refused_before_any_signature_is_read(): void {
		$key  = self::throwaway_key();
		$file = self::signature_line( self::MANIFEST, $key ) . str_repeat( "#\n", ReleaseSignature::MAX_SIGNATURE_FILE_BYTES );

		$result = ReleaseSignature::verify( self::MANIFEST, $file, [ $key['public'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_update_signature_malformed', $result->get_error_code() );
	}

	public function test_malformed_keys_are_skipped_and_no_valid_key_fails_closed(): void {
		$key       = self::throwaway_key();
		$signature = self::signature_line( self::MANIFEST, $key );
		$malformed = [ '', 'not-a-key', base64_encode( str_repeat( "\x02", 31 ) ), base64_encode( str_repeat( "\x02", 33 ) ) ];

		self::assertTrue( ReleaseSignature::verify( self::MANIFEST, $signature, [ ...$malformed, $key['public'] ] ) );
		foreach ( [ [], $malformed ] as $keys ) {
			$result = ReleaseSignature::verify( self::MANIFEST, $signature, $keys );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_update_signing_keys_invalid', $result->get_error_code() );
		}
	}

	public function test_errors_never_echo_keys_signatures_or_the_manifest(): void {
		$trusted   = self::throwaway_key();
		$untrusted = self::throwaway_key();
		$signature = self::signature_line( self::MANIFEST, $untrusted );

		$result = ReleaseSignature::verify( self::MANIFEST, $signature, [ $trusted['public'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$output = $result->get_error_message() . wp_json_encode( $result->get_error_data() );
		foreach ( [ $trusted['public'], $untrusted['public'], trim( $signature ), 'aaaaaaaa' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $output );
		}
	}

	public function test_the_rfc_8032_test_vectors_verify_as_the_release_script_checks_them(): void {
		// RFC 8032 section 7.1, TEST 1 (empty message) and TEST 2 (one byte, 0x72): public data
		// only. The Node signing script checks the same vectors, so both sides speak plain Ed25519.
		self::assertTrue(
			ReleaseSignature::verify(
				'',
				"5VZDAMNgrHKQhuLMgG6CioSHfx645dl02HPgZSJJAVVfuIIVkKM7rMYeOXAc+bRr0lv18FlbviRlUUFDjnoQCw==\n",
				[ '11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=' ]
			)
		);
		self::assertTrue(
			ReleaseSignature::verify(
				"\x72",
				"kqAJqfDUyrhyDoILX2QlQKKye1QWUD+Ps3YiI+vbadoIWsHkPhWZbkWPNhPQ8R2MOHsurrQwKu6wDSkWErsMAA==\n",
				[ 'PUAXw+hDiVqStwqnTRt+vJyYLM8uxJaMwM1V8Sr0Zgw=' ]
			)
		);
	}

	public function test_the_shipped_public_keys_are_valid_and_distinct(): void {
		$keys = ReleaseSignature::PUBLIC_KEYS;

		self::assertLessThanOrEqual( 4, count( $keys ), 'Ship the active key and a backup, plus at most two during a key change.' );
		self::assertSame( count( $keys ), count( array_unique( $keys ) ) );
		foreach ( $keys as $key ) {
			self::assertMatchesRegularExpression( '#^[A-Za-z0-9+/]{43}=$#', $key );
			self::assertSame( 32, strlen( (string) base64_decode( $key, true ) ) );
		}
	}

	/** @return array{public: string, secret: string} */
	private static function throwaway_key(): array {
		$pair = sodium_crypto_sign_keypair();
		return [
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => sodium_crypto_sign_secretkey( $pair ),
		];
	}

	/** @param array{public: string, secret: string} $key */
	private static function signature_line( string $manifest, array $key ): string {
		return base64_encode( sodium_crypto_sign_detached( $manifest, $key['secret'] ) ) . "\n";
	}
}

<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\DiffMask;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\DiffMask
 */
final class DiffMaskTest extends TestCase {

	/**
	 * @dataProvider secret_keys
	 */
	public function test_secret_key_names( string $key, bool $secret ): void {
		$this->assertSame( $secret, DiffMask::is_secret_key( $key ), $key );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function secret_keys(): array {
		return [
			'password'             => [ 'password', true ],
			'user_pass'            => [ 'user_pass', true ],
			'camel api key'        => [ 'googleApiKey', true ],
			'wp salt'              => [ 'secure_auth_salt', true ],
			'auth key'             => [ 'logged_in_key', true ],
			'confirmation secret'  => [ 'stonewright_confirmation_secret', true ],
			'oauth token'          => [ 'oauth2_access_token', true ],
			'license'              => [ 'plugin_license_key', true ],
			'application password' => [ 'application-passwords', true ],
			'blogname'             => [ 'blogname', false ],
			'post author'          => [ 'post_author', false ],
			'author name'          => [ 'authorName', false ],
			'passage'              => [ 'passage_title', false ],
			'numeric'              => [ '12', false ],
		];
	}

	public function test_sensitive_detects_credentials_and_known_token_formats(): void {
		$this->assertTrue( DiffMask::sensitive( '$api_' . "key = 'abcd1234efgh5678';" ) );
		$this->assertTrue( DiffMask::sensitive( 'Authorization: Bearer abcdefghijklmnop1234' ) );
		$this->assertTrue( DiffMask::sensitive( 'url = https://user:pw12345@example.com/x' ) );
		$this->assertTrue( DiffMask::sensitive( 'x ghp_' . str_repeat( 'a1B2', 9 ) . ' y' ) );
		$this->assertTrue( DiffMask::sensitive( 'AKIAIOSFODNN7EXAMPLE' ) );
		$this->assertFalse( DiffMask::sensitive( 'color: red;' ) );
		$this->assertFalse( DiffMask::sensitive( 'password: <your-password>' ) );
	}

	public function test_a_value_the_ledger_masked_when_it_stored_it_counts_as_masked(): void {
		$this->assertTrue( DiffMask::sensitive( '[masked line 3]' ) );
		$this->assertTrue( DiffMask::sensitive( "a\n[masked private key]\nb" ) );
		$this->assertTrue( DiffMask::sensitive( '[redacted]' ) );
		$this->assertSame( [ '[redacted]', true ], DiffMask::value( '[masked line 1]', 'value' ) );
		$this->assertFalse( DiffMask::sensitive( 'a note about [masked] things' ) );
	}

	public function test_pem_block_lines_are_flagged_between_markers(): void {
		$lines = [ 'ok', '-----BEGIN ' . 'PRIVATE KEY-----', 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASC', 'abc', '-----END PRIVATE KEY-----', 'after' ];
		$flags = DiffMask::pem_lines( $lines );
		$this->assertSame( [ 1 => true, 2 => true, 3 => true, 4 => true ], $flags );
	}

	public function test_value_masks_by_key_and_by_content(): void {
		$this->assertSame( [ '[redacted]', true ], DiffMask::value( 'hunter2hunter2', 'smtp_password' ) );
		$this->assertSame( [ '[redacted]', true ], DiffMask::value( 'token = abcdef123456', 'note' ) );
		$this->assertSame( [ 'Hello', false ], DiffMask::value( 'Hello', 'title' ) );
		$this->assertSame( [ 'true', false ], DiffMask::value( true, 'on' ) );
		$this->assertSame( [ 'null', false ], DiffMask::value( null, 'x' ) );
	}

	public function test_value_masks_secret_keys_nested_in_arrays(): void {
		[ $text, $redacted ] = DiffMask::value( [ 'host' => 'smtp.example.com', 'pass' . 'word' => 'hunter2hunter2' ], 'smtp' );
		$this->assertStringContainsString( 'smtp.example.com', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );
		$this->assertStringContainsString( '[redacted]', $text );
		$this->assertTrue( $redacted, 'A value that had something redacted inside it counts as masked.' );
	}

	/**
	 * @dataProvider nested_secrets
	 *
	 * @param mixed $value
	 */
	public function test_value_reports_a_secret_key_nested_at_any_depth_or_inside_lists( mixed $value, string $leaked ): void {
		[ $text, $redacted ] = DiffMask::value( $value, '' );

		$this->assertTrue( $redacted );
		$this->assertStringContainsString( '[redacted]', $text );
		$this->assertStringNotContainsString( $leaked, $text );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function nested_secrets(): array {
		return [
			'one level'            => [ [ 'memory_key' => 'rh2-note', 'v' => 1 ], 'rh2-note' ],
			'three levels'         => [ [ 'a' => [ 'b' => [ 'api_token' => 'zz-leak-1', 'c' => 1 ] ] ], 'zz-leak-1' ],
			'eleven levels'        => [ self::nest( 10, [ 'secret' => 'zz-leak-2' ] ), 'zz-leak-2' ],
			'inside a list'        => [ [ [ 'label' => 'a' ], [ 'label' => 'b', 'password' => 'zz-leak-3' ] ], 'zz-leak-3' ],
			'list inside a map'    => [ [ 'items' => [ [ 'x' => [ 'auth' => 'zz-leak-4' ] ] ] ], 'zz-leak-4' ],
			'an object'            => [ (object) [ 'inner' => (object) [ 'cookie' => 'zz-leak-5' ] ], 'zz-leak-5' ],
			'a typed prop content' => [ [ '$$type' => 'map', 'value' => [ 'bearer' => 'zz-leak-6' ] ], 'zz-leak-6' ],
		];
	}

	/**
	 * @dataProvider values_without_a_secret
	 *
	 * @param mixed $value
	 */
	public function test_value_does_not_report_nested_keys_that_are_not_secrets( mixed $value ): void {
		[ $text, $redacted ] = DiffMask::value( $value, '' );

		$this->assertFalse( $redacted );
		$this->assertStringNotContainsString( '[redacted]', $text );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function values_without_a_secret(): array {
		return [
			'plain nested keys'        => [ [ 'title' => 'A', 'meta' => [ 'author_name' => 'B', 'passage_title' => 'C' ] ] ],
			'a list of maps'           => [ [ [ 'label' => 'a' ], [ 'label' => 'b' ] ] ],
			'a list of scalars'        => [ [ 'a', 'b', 3 ] ],
			'an empty array'           => [ [] ],
			'a scalar'                 => [ 'plain text' ],
			'a value that says secret' => [ [ 'note' => 'keep this secret place' ] ],
			'too deep to read'         => [ self::nest( 20, [ 'label' => 'x' ] ) ],
		];
	}

	/**
	 * @param array<string, mixed> $leaf
	 * @return array<string, mixed>
	 */
	private static function nest( int $levels, array $leaf ): array {
		$value = $leaf;
		for ( $i = 0; $i < $levels; $i++ ) {
			$value = [ 'level' . $i => $value ];
		}
		return $value;
	}

	public function test_value_truncates_long_text_with_the_size(): void {
		[ $text ] = DiffMask::value( str_repeat( 'a', 2000 ), 'body', 100 );
		$this->assertLessThanOrEqual( 140, strlen( $text ) );
		$this->assertStringContainsString( '2000 bytes', $text );
	}

	public function test_value_never_returns_invalid_utf8(): void {
		[ $text ] = DiffMask::value( "bad \xB1\xB2 bytes", 'body' );
		$this->assertSame( 1, preg_match( '//u', $text ) );
		[ $cut ] = DiffMask::value( str_repeat( "\xC3\xA9", 100 ), 'body', 51 );
		$this->assertSame( 1, preg_match( '//u', $cut ) );
	}

	public function test_display_simplifies_typed_props(): void {
		$this->assertSame( 'Hello', DiffMask::display( [ '$$type' => 'string', 'value' => 'Hello' ] ) );
		$this->assertSame( '16px', DiffMask::display( [ '$$type' => 'size', 'value' => [ 'size' => 16, 'unit' => 'px' ] ] ) );
		$this->assertSame( 'e-a, e-b', DiffMask::display( [ '$$type' => 'classes', 'value' => [ 'e-a', 'e-b' ] ] ) );
		$this->assertSame(
			'{"top":"1px","left":"2px"}',
			DiffMask::display(
				[
					'$$type' => 'dimensions',
					'value'  => [
						'top'  => [ '$$type' => 'size', 'value' => [ 'size' => 1, 'unit' => 'px' ] ],
						'left' => [ '$$type' => 'size', 'value' => [ 'size' => 2, 'unit' => 'px' ] ],
					],
				]
			)
		);
	}
}

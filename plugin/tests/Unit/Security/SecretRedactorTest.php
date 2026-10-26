<?php
/**
 * Masking of secret values in a kept copy of executed code.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\SecretRedactor;

/**
 * @covers \Stonewright\WpMcp\Security\SecretRedactor
 */
final class SecretRedactorTest extends TestCase {

	public function test_plain_code_is_unchanged(): void {
		$code = <<<'PHP'
$posts = get_posts( [ 'post_type' => 'page', 'numberposts' => 5 ] );
$token_count = count( $posts );
$label = 'tokenize this sentence';
echo get_bloginfo( 'name' ), "\n";
return [ 'count' => $token_count, 'key' => 'abc', 'secretary' => true ];
PHP;

		self::assertSame( $code, SecretRedactor::redact( $code ) );
	}

	public function test_a_password_assignment_keeps_the_first_two_and_last_two_characters(): void {
		self::assertSame(
			"\$db_password = 'fi…02';",
			SecretRedactor::redact( "\$db_password = 'fixture-hunter202';" )
		);
		self::assertSame(
			'$DB_PASSWD = "co…le";',
			SecretRedactor::redact( '$DB_PASSWD = "correct horse battery staple";' )
		);
	}

	public function test_array_keys_are_masked_with_either_arrow_or_colon_and_either_quote(): void {
		$code = "\$args = [ 'client_secret' => 'fixture-0123456789', \"api-key\" => \"test-sk-9876543210\", 'access_token'=>'test-abcdefghijkl' ];"
			. "\n\$json = '{\"private_key\": \"-----BEGIN KEY-----ABCD\", \"apiKey\":\"zyxwvutsrqpo\"}';";

		$out = SecretRedactor::redact( $code );

		self::assertSame(
			"\$args = [ 'client_secret' => 'fi…89', \"api-key\" => \"te…10\", 'access_token'=>'te…kl' ];"
			. "\n\$json = '{\"private_key\": \"--…CD\", \"apiKey\":\"zy…po\"}';",
			$out
		);
	}

	public function test_a_secret_set_through_an_option_or_transient_call_is_masked(): void {
		$out = SecretRedactor::redact( "update_option( 'vendor_api_key', 'test-0123456789abcdef' );\nset_transient( 'vendor_token', 'test-abcdefghijklmnop', 60 );" );

		self::assertSame( "update_option( 'vendor_api_key', 'te…ef' );\nset_transient( 'vendor_token', 'te…op', 60 );", $out );
	}

	public function test_the_name_of_the_key_and_the_code_around_it_stay_readable(): void {
		$out = SecretRedactor::redact( "if ( 'yes' === \$mode ) {\n\t\$token = 'fixture-abcdefghijkl'; // rotate monthly\n}\nreturn \$token;" );

		self::assertSame( "if ( 'yes' === \$mode ) {\n\t\$token = 'fi…kl'; // rotate monthly\n}\nreturn \$token;", $out );
	}

	public function test_other_password_names_are_masked(): void {
		$out = SecretRedactor::redact( "\$user_pass = 'fixture-abcdefghij'; \$args = [ 'pwd' => 'test-opqrstuvwxyz', 'pass1' => 'fixture-horse-battery' ];" );

		self::assertSame( "\$user_pass = 'fi…ij'; \$args = [ 'pwd' => 'te…yz', 'pass1' => 'fi…ry' ];", $out );
	}

	public function test_a_password_passed_by_position_is_masked(): void {
		$out = SecretRedactor::redact(
			"wp_set_password( 'fixture-horse-battery', 5 );
"
			. "\$id = wp_create_user( 'alice', 'fixture-hunter202', 'alice@example.test' );
"
			. "\$db = new wpdb( 'dbuser', 'test-dbvalue-123456', 'wp', 'localhost' );
"
			. "wp_check_password( \"test-typed-secret-value\", \$hash );"
		);

		self::assertSame(
			"wp_set_password( 'fi…ry', 5 );
"
			. "\$id = wp_create_user( 'alice', 'fi…02', 'alice@example.test' );
"
			. "\$db = new wpdb( 'dbuser', 'te…56', 'wp', 'localhost' );
"
			. "wp_check_password( \"te…ue\", \$hash );",
			$out
		);
	}

	public function test_a_value_with_an_escaped_quote_is_masked_whole(): void {
		$out = SecretRedactor::redact( "\$secret = 'fixture-abc\\'defghijklm\\'nop'; echo 1;" );

		self::assertSame( "\$secret = 'fi…op'; echo 1;", $out );
		self::assertStringNotContainsString( 'defghij', $out );
	}

	public function test_a_value_too_short_to_show_four_characters_is_masked_whole(): void {
		self::assertSame( "\$password = '…';", SecretRedactor::redact( "\$password = 'abc12';" ) );
		self::assertSame( "\$password = '';", SecretRedactor::redact( "\$password = '';" ), 'an empty value has nothing to hide' );
	}

	public function test_wordpress_salts_and_database_password_in_define_are_masked(): void {
		$names = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_PASSWORD' ];
		$code  = '';
		foreach ( $names as $name ) {
			$code .= "define( '{$name}', 'put your unique phrase here' );\n";
		}
		$code .= "define( 'WP_DEBUG', true );\ndefine('WP_HOME', 'https://example.test');\n";

		$out = SecretRedactor::redact( $code );

		foreach ( $names as $name ) {
			self::assertStringContainsString( "define( '{$name}', 'pu…re' );", $out );
		}
		self::assertStringNotContainsString( 'unique phrase', $out );
		self::assertStringContainsString( "define( 'WP_DEBUG', true );", $out );
		self::assertStringContainsString( "define('WP_HOME', 'https://example.test');", $out );
	}

	public function test_authorization_headers_are_masked_for_bearer_and_basic(): void {
		$out = SecretRedactor::redact(
			"\$h = 'Authorization: Bearer fixture-eyJhbGciOi.payload.signature-1234';\n"
			. "\$g = \"authorization: Basic fixture-dXNlcjpwYXNzd29yZA==\";\n"
			. "\$r = wp_remote_get( \$url, [ 'headers' => [ 'Authorization' => 'Bearer fixture-ABCDEF0123' ] ] );"
		);

		self::assertSame(
			"\$h = 'Authorization: Bearer fi…34';\n"
			. "\$g = \"authorization: Basic fi…==\";\n"
			. "\$r = wp_remote_get( \$url, [ 'headers' => [ 'Authorization' => 'Be…23' ] ] );",
			$out
		);
	}

	public function test_multibyte_values_are_cut_on_character_boundaries(): void {
		$out = SecretRedactor::redact( "\$password = 'fixture-éàüöß-ñandú';" );

		self::assertSame( "\$password = 'fi…dú';", $out );
		self::assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_redacted_code_still_parses(): void {
		$code = "\$password = 'fixture-hunter202';\n\$a = [ 'token' => \"abc\\\"defghijkl\", 'x' => 1 ];\ndefine( 'AUTH_KEY', 'abcdefghijkl' );\nreturn \$a;";

		$out = SecretRedactor::redact( $code );

		self::assertNotSame( $code, $out );
		self::assertNotEmpty( token_get_all( '<?php ' . $out, TOKEN_PARSE ) );
	}

	public function test_a_long_run_of_identifier_characters_is_handled_without_backtracking_trouble(): void {
		$code = '$blob = "' . str_repeat( 'abcdefgh_', 40000 ) . '"; $password = \'fixture-abcdefghijkl\';';

		$out = SecretRedactor::redact( $code );

		self::assertStringContainsString( "\$password = 'fi…kl';", $out );
		self::assertStringContainsString( str_repeat( 'abcdefgh_', 40000 ), $out );
	}

	/**
	 * Runs in its own process: PHP keeps a compiled pattern, so the limits below only count for a pattern no other test used.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_regular_expression_withholds_the_code_instead_of_returning_it(): void {
		$jit   = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '10' );
		try {
			$out = SecretRedactor::redact( "\$password = 'fixture-" . str_repeat( 'a', 5000 ) . "';" );
		} finally {
			ini_set( 'pcre.jit', false === $jit ? '1' : (string) $jit );
			ini_set( 'pcre.backtrack_limit', false === $limit ? '1000000' : (string) $limit );
		}

		self::assertSame( SecretRedactor::WITHHELD, $out );
	}
}

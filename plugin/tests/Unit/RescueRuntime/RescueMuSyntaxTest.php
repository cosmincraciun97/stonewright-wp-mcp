<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The rescue MU-plugin has no dependencies and is valid on PHP 7.4.
 *
 * The suite runs on PHP 8.x, so the first two tests inspect the file; the third asks every PHP
 * binary listed in STONEWRIGHT_TEST_PHP_BINARIES (separated like PATH, for example a 7.4 binary) to
 * lint it.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueMuSyntaxTest extends TestCase {

	private static function code(): string {
		return (string) file_get_contents( MuRuntime::path() );
	}

	/** @return list<string> Names of the tokens in the file. */
	private static function token_names(): array {
		$names = [];
		foreach ( token_get_all( self::code() ) as $token ) {
			if ( is_array( $token ) ) {
				$names[] = token_name( $token[0] );
			}
		}
		return $names;
	}

	public function test_the_file_uses_no_syntax_newer_than_php_7_2(): void {
		$forbidden = [
			'T_MATCH', 'T_ATTRIBUTE', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ENUM', 'T_READONLY', 'T_FN',
			'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE', 'T_NAMESPACE', 'T_DECLARE',
			'T_COALESCE_EQUAL', 'T_REQUIRE', 'T_REQUIRE_ONCE', 'T_INCLUDE', 'T_INCLUDE_ONCE', 'T_EVAL',
		];
		$found = array_values( array_intersect( $forbidden, self::token_names() ) );

		self::assertSame( [], $found, 'newer syntax, a namespace or a file load in the MU-plugin' );
	}

	public function test_the_file_calls_no_function_or_constant_newer_than_php_7_2(): void {
		$code  = self::code();
		$names = [
			'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'get_resource_id',
			'array_key_first', 'array_key_last', 'is_countable', 'hrtime', 'json_validate', 'fdiv', 'preg_last_error_msg',
			'JSON_THROW_ON_ERROR', 'PHP_FLOAT_EPSILON', 'mb_str_split',
		];
		foreach ( $names as $name ) {
			self::assertDoesNotMatchRegularExpression( '/\b' . preg_quote( $name, '/' ) . '\b/', $code, $name );
		}
	}

	public function test_the_file_depends_on_no_stonewright_class_and_no_autoloader(): void {
		$code = '';
		foreach ( token_get_all( self::code() ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		self::assertDoesNotMatchRegularExpression( '/\bStonewright\\\\/', $code, 'no Stonewright namespace in the code' );
		self::assertDoesNotMatchRegularExpression( '/autoload|vendor\//i', $code );
		self::assertStringContainsString( 'Stonewright_Rescue', $code, 'the only name it declares' );
	}

	public function test_the_file_does_not_use_the_fatal_error_handler_drop_in(): void {
		// A site can have one fatal-error-handler.php drop-in and other plugins use it, so the helper uses a shutdown function.
		$code = '';
		foreach ( token_get_all( self::code() ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		self::assertStringNotContainsString( 'fatal-error-handler', $code );
		self::assertStringNotContainsString( 'wp_register_fatal_error_handler', $code );
		self::assertStringContainsString( 'register_shutdown_function', $code );
		foreach ( [ 'Core/RescueInstaller.php', 'Core/RescueBootstrap.php' ] as $file ) {
			self::assertStringNotContainsString( 'fatal-error-handler', (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file ), $file );
		}
	}

	public function test_the_file_ends_with_the_marker_the_installer_checks(): void {
		self::assertStringEndsWith( "/* STONEWRIGHT_RESCUE_MU_END */\n", self::code() );
		self::assertStringContainsString( '@stonewright-rescue-mu', substr( self::code(), 0, 1024 ) );
		self::assertStringNotContainsString( "\r", self::code(), 'LF line endings' );
	}

	/** @return array<string, array{0: string}> */
	public static function binaries(): array {
		$binaries = [ 'the PHP that runs the tests' => [ PHP_BINARY ] ];
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'STONEWRIGHT_TEST_PHP_BINARIES' ) ) as $extra ) {
			$extra = trim( $extra );
			if ( '' !== $extra && is_file( $extra ) ) {
				$binaries[ basename( dirname( $extra ) ) ] = [ $extra ];
			}
		}
		return $binaries;
	}

	/** @dataProvider binaries */
	public function test_the_file_lints_on_every_listed_php_binary( string $binary ): void {
		if ( ! function_exists( 'proc_open' ) ) {
			self::markTestSkipped( 'proc_open is not available.' );
		}
		$process = proc_open( [ $binary, '-l', MuRuntime::path() ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		self::assertSame( 0, proc_close( $process ), $output );
		self::assertStringContainsString( 'No syntax errors detected', $output );
	}
}

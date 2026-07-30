<?php
/**
 * The validator's only authority is the PHP parser.
 *
 * A regex heuristic that guesses at "corrupted" source cannot know PHP grammar,
 * so it rejects legitimate code: class constants, enum cases, HTML attributes in
 * inline templates, and assignments inside strings all look like a bare
 * assignment to a pattern match. The parser already rejects every genuine syntax
 * error, so these tests pin the contract that valid PHP is accepted and only
 * unparseable PHP is refused.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\PhpSyntaxValidator;

final class PhpSyntaxValidatorTest extends TestCase {

	/**
	 * @dataProvider valid_sources
	 */
	public function test_accepts_valid_php( string $label, string $source ): void {
		$result = PhpSyntaxValidator::validate_complete_file( $source );

		if ( $result instanceof \WP_Error ) {
			self::fail(
				sprintf(
					'%s should be accepted but was rejected as "%s": %s',
					$label,
					$result->get_error_code(),
					$result->get_error_message()
				)
			);
		}

		self::assertTrue( $result );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function valid_sources(): array {
		return [
			'class constant'         => [
				'A class constant',
				"<?php\nclass Kit {\n\tconst MODE = 'production-safe';\n}\n",
			],
			'typed property default' => [
				'A typed property with a default',
				"<?php\nclass Kit {\n\tpublic string \$mode = 'staging';\n}\n",
			],
			'enum case'              => [
				'An enum case with a backed value',
				"<?php\nenum Mode: string {\n\tcase Dev = 'development';\n}\n",
			],
			'mixed php and html'     => [
				'Inline HTML with attributes after a PHP block',
				"<?php\n\$title = 'Hello';\n?>\n<div class=\"card\" data-id=\"7\">\n\t<?php echo esc_html( \$title ); ?>\n</div>\n",
			],
			'escaped quotes'         => [
				'A string containing an escaped quote and an equals sign',
				"<?php\n\$sql = 'name = \\'value\\'';\n",
			],
			'assignment in string'   => [
				'An assignment-looking fragment inside a heredoc',
				"<?php\n\$snippet = <<<'CODE'\nfoo = bar;\nCODE;\n",
			],
			'named argument'         => [
				'A named argument call',
				"<?php\nsprintf( format: '%s', values: 'x' );\n",
			],
			'static call chain'      => [
				'A static call after a statement boundary',
				"<?php\n\$a = 1;\nKit::boot();\n",
			],
		];
	}

	public function test_rejects_genuine_parse_failure(): void {
		$result = PhpSyntaxValidator::validate_complete_file( "<?php\nfunction broken( {\n" );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );

		$data = $result->get_error_data();
		self::assertSame( 'php_candidate_invalid', $data['error_code'] );
		self::assertArrayHasKey( 'parse_message', $data );
		self::assertSame( 'token_get_all:TOKEN_PARSE', $data['validator'] );
	}

	public function test_rejects_bare_assignment_that_the_parser_rejects(): void {
		// The original incident shape. It stays blocked, but by the parser.
		$result = PhpSyntaxValidator::validate_complete_file( "<?php\nobfuscated = 'payload';\n" );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );
		self::assertSame( 'php_candidate_invalid', $result->get_error_data()['error_code'] );
	}

	public function test_parse_failures_carry_the_rule_attribution(): void {
		$result = PhpSyntaxValidator::validate_complete_file( "<?php\nfunction broken( {\n" );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'php-writes-must-parse', $result->get_error_data()['rule_id'] );
	}

	public function test_no_bare_assignment_cause_key_survives(): void {
		$sources = array_map(
			static fn( array $case ): string => $case[1],
			array_values( self::valid_sources() )
		);
		$sources[] = "<?php\nobfuscated = 'payload';\n";

		foreach ( $sources as $source ) {
			$result = PhpSyntaxValidator::validate_complete_file( $source );
			if ( ! $result instanceof \WP_Error ) {
				continue;
			}
			$data = $result->get_error_data();
			self::assertNotSame( 'php_candidate_bare_assignment', $data['error_code'] ?? '' );
			// The only cause key left is the rule attribution, not the heuristic's.
			self::assertNotSame( 'php_bare_assignment', $data['cause_key'] ?? '' );
		}
	}
}

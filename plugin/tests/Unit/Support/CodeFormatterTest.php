<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\CodeFormatter;

/**
 * @covers \Stonewright\WpMcp\Support\CodeFormatter
 */
final class CodeFormatterTest extends TestCase {

	public function test_expands_layout_escapes_outside_php_strings_and_comments(): void {
		$in  = '<?php\n$value = "keep \n literal";\n/* keep \t comment */\nreturn $value;';
		$out = CodeFormatter::normalize( $in, 'php' );

		self::assertSame(
			"<?php\n\$value = \"keep \\n literal\";\n/* keep \\t comment */\nreturn \$value;\n",
			$out
		);
	}

	/**
	 * @dataProvider protected_escape_fixtures
	 */
	public function test_preserves_string_and_comment_escapes_while_expanding_external_layout(
		string $language,
		string $in,
		string $expected
	): void {
		self::assertSame( $expected, CodeFormatter::normalize( $in, $language ) );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function protected_escape_fixtures(): array {
		return [
			'javascript string and comments' => [
				'javascript',
				'const label = "keep\nvalue";\n/* keep \t comment */\nrun( label );',
				"const label = \"keep\\nvalue\";\n/* keep \\t comment */\nrun( label );\n",
			],
			'javascript line comment'        => [
				'js',
				'const value = 1;\n// keep \n comment',
				"const value = 1;\n// keep \\n comment\n",
			],
			'json string'                    => [
				'json',
				'{"label":"keep\nvalue"}\n',
				"{\"label\":\"keep\\nvalue\"}\n",
			],
			'css string and comment'         => [
				'css',
				'.x::before { content: "keep\nvalue"; }\n/* keep \t comment */',
				".x::before { content: \"keep\\nvalue\"; }\n/* keep \\t comment */\n",
			],
			'html attribute and comment'     => [
				'html',
				'<div data-label="keep\nvalue">Text</div>\n<!-- keep \t comment -->',
				"<div data-label=\"keep\\nvalue\">Text</div>\n<!-- keep \\t comment -->\n",
			],
		];
	}

	public function test_leaves_clean_multi_line_payload_semantics_untouched(): void {
		$in = "<?php\n\$value = 'keep \\n literal';\n";

		self::assertSame( $in, CodeFormatter::normalize( $in, 'php' ) );
	}

	public function test_normalizes_crlf_to_lf(): void {
		self::assertSame( "a\nb\n", CodeFormatter::normalize( "a\r\nb\r\n", 'css' ) );
	}

	public function test_strips_trailing_whitespace_per_line(): void {
		self::assertSame( "a\nb\n", CodeFormatter::normalize( "a   \nb\t\n", 'css' ) );
	}

	public function test_keeps_original_when_php_layout_expansion_breaks_syntax(): void {
		$in = '<?php $value = 1;\nif (';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'php' ) );
	}

	public function test_keeps_intentional_single_line_php_on_one_line(): void {
		$in = '<?php function sw_demo() { if ( true ) { return 1; } return 0; }';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'php' ) );
	}

	public function test_is_idempotent(): void {
		$once  = CodeFormatter::normalize( '<?php\n$value = 1;\n', 'php' );
		$twice = CodeFormatter::normalize( $once, 'php' );

		self::assertSame( $once, $twice );
	}

	public function test_non_code_language_does_not_expand_literal_escapes(): void {
		$in = 'plain text with \n literal';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'text' ) );
	}
}

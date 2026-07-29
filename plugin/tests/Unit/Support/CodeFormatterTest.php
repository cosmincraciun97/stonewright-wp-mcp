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
		$out = CodeFormatter::normalize( $in, 'php', true );

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
		self::assertSame( $expected, CodeFormatter::normalize( $in, $language, true ) );
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

	public function test_does_not_decode_literal_layout_without_explicit_opt_in(): void {
		$in = '<?php\n$value = 1;\n';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'php' ) );
	}

	public function test_normalizes_crlf_to_lf(): void {
		self::assertSame( "a\nb\n", CodeFormatter::normalize( "a\r\nb\r\n", 'css' ) );
	}

	public function test_strips_trailing_whitespace_per_line(): void {
		self::assertSame( "a\nb\n", CodeFormatter::normalize( "a   \nb\t\n", 'css' ) );
	}

	public function test_keeps_original_when_php_layout_expansion_breaks_syntax(): void {
		$in = '<?php $value = 1;\nif (';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'php', true ) );
	}

	public function test_keeps_intentional_single_line_php_on_one_line(): void {
		$in = '<?php function sw_demo() { if ( true ) { return 1; } return 0; }';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'php' ) );
	}

	public function test_is_idempotent(): void {
		$once  = CodeFormatter::normalize( '<?php\n$value = 1;\n', 'php', true );
		$twice = CodeFormatter::normalize( $once, 'php', true );

		self::assertSame( $once, $twice );
	}

	public function test_non_code_language_does_not_expand_literal_escapes(): void {
		$in = 'plain text with \n literal';

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'text' ) );
	}

	public function test_opted_php_line_comment_ends_at_encoded_newline(): void {
		$in = '<?php\n// keep this comment\nreturn 1;';

		self::assertSame(
			"<?php\n// keep this comment\nreturn 1;\n",
			CodeFormatter::normalize( $in, 'php', true )
		);
	}

	public function test_opted_javascript_line_comment_ends_at_encoded_newline(): void {
		$in = 'const before = 1;\n// keep this comment\nconst after = 2;';

		self::assertSame(
			"const before = 1;\n// keep this comment\nconst after = 2;\n",
			CodeFormatter::normalize( $in, 'js', true )
		);
	}

	/**
	 * @dataProvider ambiguous_payload_fixtures
	 */
	public function test_ambiguous_grammar_falls_back_without_partial_decoding(
		string $language,
		string $in
	): void {
		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, $language, true ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function ambiguous_payload_fixtures(): array {
		return [
			'javascript regex literal'          => [
				'js',
				'const pattern = /\n/;\nrun( pattern );',
			],
			'javascript template interpolation' => [
				'javascript',
				'const view = `value ${format( "\n" )}`;\nrender( view );',
			],
			'php heredoc'                       => [
				'php',
				'<?php\n$value = <<<TEXT\nkeep \n value\nTEXT;\n',
			],
			'php nowdoc'                        => [
				'php',
				'<?php\n$value = <<<\'TEXT\'\nkeep \n value\nTEXT;\n',
			],
			'html script raw element'           => [
				'html',
				'<script>const pattern = /\n/;</script>\n<div>After</div>',
			],
			'html style raw element'            => [
				'html',
				'<style>.x::before { content: "\n"; }</style>\n<div>After</div>',
			],
		];
	}

	public function test_html_text_apostrophe_does_not_open_an_attribute_string(): void {
		$in = 'It\'s text\n<div data-label="keep\nvalue">After</div>';

		self::assertSame(
			"It's text\n<div data-label=\"keep\\nvalue\">After</div>\n",
			CodeFormatter::normalize( $in, 'html', true )
		);
	}

	/**
	 * @dataProvider protected_trailing_whitespace_fixtures
	 */
	public function test_preserves_trailing_whitespace_inside_multiline_protected_regions(
		string $language,
		string $in
	): void {
		self::assertSame( $in, CodeFormatter::normalize( $in, $language ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function protected_trailing_whitespace_fixtures(): array {
		return [
			'php multiline string'       => [
				'php',
				"<?php\n\$value = \"first  \nsecond\t\n\";\n",
			],
			'javascript template'        => [
				'js',
				"const view = `first  \nsecond\t\n`;\n",
			],
			'css block comment'          => [
				'css',
				"/* first  \nsecond\t\n*/\n",
			],
			'html comment'               => [
				'html',
				"<!-- first  \nsecond\t\n-->\n",
			],
		];
	}

	public function test_odd_backslash_run_before_encoded_crlf_falls_back_unchanged(): void {
		$in = <<<'CSS'
.x { value: \\\r\nnext; }
CSS;

		self::assertSame( $in . "\n", CodeFormatter::normalize( $in, 'css', true ) );
	}
}

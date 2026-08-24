<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\ReleaseNotesRenderer;

/**
 * @covers \Stonewright\WpMcp\Core\ReleaseNotesRenderer
 */
final class ReleaseNotesRendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wp_kses_calls']      = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wp_kses_calls']      = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls'] = [];
	}

	public function test_headings_paragraphs_and_lists_render_structurally(): void {
		$markdown = <<<'MD'
# Title
## Section
### Detail
#### Note

A paragraph of release notes.

- First
- Second

1. Alpha
2. Beta
MD;
		$html     = ReleaseNotesRenderer::render( $markdown );

		self::assertMatchesRegularExpression( '/<h1>\s*Title\s*<\/h1>/', $html );
		self::assertMatchesRegularExpression( '/<h2>\s*Section\s*<\/h2>/', $html );
		self::assertMatchesRegularExpression( '/<h3>\s*Detail\s*<\/h3>/', $html );
		self::assertMatchesRegularExpression( '/<h4>\s*Note\s*<\/h4>/', $html );
		self::assertMatchesRegularExpression( '/<p>\s*A paragraph of release notes\.\s*<\/p>/', $html );
		self::assertMatchesRegularExpression( '/<ul>.*<li>\s*First\s*<\/li>.*<li>\s*Second\s*<\/li>.*<\/ul>/s', $html );
		self::assertMatchesRegularExpression( '/<ol>.*<li>\s*Alpha\s*<\/li>.*<li>\s*Beta\s*<\/li>.*<\/ol>/s', $html );
		self::assertStringNotContainsString( '# Title', $html );
		self::assertStringNotContainsString( '<h5', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	public function test_inline_and_fenced_code_is_escaped_inside_code_and_pre(): void {
		$markdown = "Use `wp_kses()` and `<script>`.\n\n```\n<script>alert(1)</script>\nconst x = 1;\n```";
		$html     = ReleaseNotesRenderer::render( $markdown );

		self::assertMatchesRegularExpression( '/<code>wp_kses\(\)<\/code>/', $html );
		self::assertMatchesRegularExpression( '/<code>&lt;script&gt;<\/code>/', $html );
		self::assertMatchesRegularExpression( '/<pre><code>[\s\S]*&lt;script&gt;alert\(1\)&lt;\/script&gt;[\s\S]*<\/code><\/pre>/', $html );
		self::assertStringContainsString( 'const x = 1;', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	public function test_strong_and_emphasis_render_without_arbitrary_tags(): void {
		$html = ReleaseNotesRenderer::render( 'This is **bold** and *italic*, plus __strong__ and _em_.' );

		self::assertMatchesRegularExpression( '/<strong>\s*bold\s*<\/strong>/', $html );
		self::assertMatchesRegularExpression( '/<em>\s*italic\s*<\/em>/', $html );
		self::assertMatchesRegularExpression( '/<strong>\s*strong\s*<\/strong>/', $html );
		self::assertMatchesRegularExpression( '/<em>\s*em\s*<\/em>/', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringNotContainsString( '<i>', $html );
		self::assertStringNotContainsString( '<div>', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	public function test_valid_https_link_renders_with_safe_attributes(): void {
		$html = ReleaseNotesRenderer::render( 'See [the guide](https://example.com/docs).' );

		self::assertMatchesRegularExpression(
			'/<a href="https:\/\/example\.com\/docs"(?: rel="noopener noreferrer")?>the guide<\/a>/',
			$html
		);
		self::assertStringContainsString( 'rel="noopener noreferrer"', $html );
		self::assertStringNotContainsString( 'target=', $html );
		self::assertStringNotContainsString( 'onclick', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	public function test_raw_html_event_handlers_and_entity_tricks_remain_inert(): void {
		$markdown = <<<'MD'
<script>alert(1)</script>
<img src=x onerror="alert(1)">
<svg onload="alert(1)"></svg>
<iframe src="https://example.com"></iframe>
<style>body{display:none}</style>
<!-- comment -->
<div onclick="alert(1)">click</div>
<p>nested <em><strong>ok</p>
&lt;script&gt;alert(1)&lt;/script&gt;
MD;
		$html     = ReleaseNotesRenderer::render( $markdown );

		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( '&lt;iframe', $html );
		self::assertStringContainsString( '&lt;svg', $html );
		self::assertStringContainsString( '&lt;style', $html );
		self::assertStringContainsString( '&amp;lt;script&amp;gt;', $html );
		self::assertStringContainsString( 'click', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	/**
	 * @dataProvider rejected_link_cases
	 */
	public function test_rejected_links_are_not_clickable( string $markdown, string $label ): void {
		$html = ReleaseNotesRenderer::render( $markdown );

		self::assertStringNotContainsString( '<a ', $html );
		self::assertStringNotContainsString( 'href=', $html );
		self::assertStringContainsString( $label, $html );
		$this->assertNoExecutableMarkup( $html );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function rejected_link_cases(): array {
		return [
			'javascript scheme'   => [ '[Click](javascript:alert(1))', 'Click' ],
			'javascript encoded'  => [ '[Click](javascript&#58;alert(1))', 'Click' ],
			'data scheme'         => [ '[Click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)', 'Click' ],
			'http scheme'         => [ '[Click](http://example.com/docs)', 'Click' ],
			'protocol relative'   => [ '[Click](//example.com/docs)', 'Click' ],
			'userinfo'            => [ '[Click](https://user:pass@example.com/docs)', 'Click' ],
			'userinfo user only'  => [ '[Click](https://user@example.com/docs)', 'Click' ],
			'malformed empty'     => [ '[Click](https://)', 'Click' ],
			'malformed no scheme' => [ '[Click](/relative/path)', 'Click' ],
			'malformed spaces'    => [ '[Click](https://example.com/a b)', 'Click' ],
		];
	}

	public function test_link_labels_and_code_keep_markdown_metacharacters(): void {
		$html = ReleaseNotesRenderer::render(
			'See [Use *this* + more](https://example.com/path) and `**not-bold**` plus `*still code*`.'
		);

		self::assertMatchesRegularExpression( '/<a href="https:\/\/example\.com\/path"[^>]*>/', $html );
		self::assertStringContainsString( 'Use', $html );
		self::assertStringContainsString( '+', $html );
		self::assertMatchesRegularExpression( '/<code>\*\*not-bold\*\*<\/code>/', $html );
		self::assertMatchesRegularExpression( '/<code>\*still code\*<\/code>/', $html );
		self::assertStringNotContainsString( '<strong>not-bold</strong>', $html );
		$this->assertNoExecutableMarkup( $html );
	}

	/**
	 * @dataProvider href_metacharacter_cases
	 */
	public function test_href_with_markdown_metacharacters_stays_exact(
		string $url,
		string $markdown,
		bool $expect_italic
	): void {
		$html = ReleaseNotesRenderer::render( $markdown );

		self::assertStringContainsString( 'href="' . $url . '"', $html );
		if ( $expect_italic ) {
			self::assertMatchesRegularExpression( '/<em>\s*italic\s*<\/em>/', $html );
		}
		$this->assertNoExecutableMarkup( $html );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function href_metacharacter_cases(): array {
		return [
			'dunder init py'                   => [
				'https://github.com/foo/bar/blob/main/plugin/__init__.py',
				'[source](https://github.com/foo/bar/blob/main/plugin/__init__.py)',
				false,
			],
			'underscored path'                 => [
				'https://example.com/_foo_',
				'[path](https://example.com/_foo_)',
				false,
			],
			'asterisks in path'                => [
				'https://example.com/foo*bar*baz',
				'[path](https://example.com/foo*bar*baz)',
				false,
			],
			'single asterisk with later italic' => [
				'https://example.com/foo*bar',
				'[path](https://example.com/foo*bar) and *italic*',
				true,
			],
		];
	}

	public function test_empty_or_whitespace_body_returns_sanitized_human_fallback(): void {
		foreach ( [ '', '   ', "\n\t" ] as $body ) {
			$GLOBALS['stonewright_test_wp_kses_calls'] = [];
			$html                                      = ReleaseNotesRenderer::render( $body );
			self::assertStringContainsString( 'Release notes are not available for this version.', $html );
			self::assertMatchesRegularExpression( '/<p>Release notes are not available for this version\.<\/p>/', $html );
			self::assertNotEmpty( $GLOBALS['stonewright_test_wp_kses_calls'] );
			$this->assertNoExecutableMarkup( $html );
		}
	}

	public function test_sanitizes_with_explicit_wp_kses_allowlist_not_only_wp_kses_post(): void {
		ReleaseNotesRenderer::render( '# Hello' );

		self::assertNotEmpty( $GLOBALS['stonewright_test_wp_kses_calls'] );
		$call = $GLOBALS['stonewright_test_wp_kses_calls'][0];
		self::assertIsArray( $call['allowed_html'] );
		self::assertSame( [ 'https' ], $call['allowed_protocols'] );

		$allowed = array_change_key_case( $call['allowed_html'], CASE_LOWER );
		foreach ( [ 'h1', 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'pre', 'code', 'strong', 'em', 'a' ] as $tag ) {
			self::assertArrayHasKey( $tag, $allowed );
		}
		foreach ( [ 'script', 'iframe', 'svg', 'style', 'img', 'div', 'span', 'blockquote' ] as $tag ) {
			self::assertArrayNotHasKey( $tag, $allowed );
		}
		self::assertArrayHasKey( 'href', $allowed['a'] );
		self::assertArrayNotHasKey( 'onclick', $allowed['a'] );
		self::assertEmpty( $GLOBALS['stonewright_test_wp_kses_post_calls'] );
	}

	private function assertNoExecutableMarkup( string $html ): void {
		self::assertDoesNotMatchRegularExpression( '/<script\b/i', $html );
		self::assertDoesNotMatchRegularExpression( '/<iframe\b/i', $html );
		self::assertDoesNotMatchRegularExpression( '/<svg\b/i', $html );
		self::assertDoesNotMatchRegularExpression( '/<style\b/i', $html );
		self::assertDoesNotMatchRegularExpression( '/<img\b/i', $html );
		self::assertDoesNotMatchRegularExpression( '/<!--/', $html );
		self::assertDoesNotMatchRegularExpression( '/<[a-zA-Z][^>]*\son[a-z]+\s*=/i', $html );
	}
}

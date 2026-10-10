<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * The sandbox file name field carries a pattern attribute that browsers compile
 * with the unicodeSets flag, and that has to accept exactly the names the
 * server accepts.
 *
 * @covers \Stonewright\WpMcp\Admin\SandboxPage
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles::name_pattern
 */
final class SandboxNameFieldPatternTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_plugins' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
		$_POST                                       = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$_GET                                        = [];
		$_POST                                       = [];
	}

	private static function rendered_pattern(): string {
		// The new file form is its own view of the Drafts tab.
		$_GET = [ 'new' => '1' ];
		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertSame(
			1,
			preg_match( '/<input[^>]*id="stonewright_new_filename"[^>]*>/', $html, $input ),
			'The new file form renders a file name input.'
		);
		self::assertSame( 1, preg_match( '/\spattern="([^"]*)"/', $input[0], $pattern ), 'The input carries a pattern attribute.' );

		return html_entity_decode( $pattern[1], ENT_QUOTES );
	}

	public function test_the_pattern_has_no_unescaped_hyphen_inside_the_character_class(): void {
		$pattern = self::rendered_pattern();

		// With the unicodeSets flag a class may not end in a bare hyphen: it has to be escaped.
		self::assertDoesNotMatchRegularExpression( '/(?<!\\\\)-\]/', $pattern );
		self::assertSame( '[a-z0-9_\-]+\.php', $pattern );
	}

	public function test_the_field_pattern_is_the_server_name_rule(): void {
		$pattern = self::rendered_pattern();
		self::assertSame( SandboxFiles::name_pattern(), $pattern );

		$names = [
			'my-snippet.php'      => true,
			'a.php'               => true,
			'under_score_1.php'   => true,
			'-lead.php'           => true,
			'.php'                => false,
			'UPPER.php'           => false,
			'has space.php'       => false,
			'dir/name.php'        => false,
			'name.php.bak'        => false,
			'name.phtml'          => false,
			'name.draft'          => false,
			'a.b.php'             => false,
			"name.php\n"          => false,
		];
		foreach ( $names as $name => $valid ) {
			self::assertSame( $valid, SandboxFiles::valid_name( $name ), 'server: ' . $name );
			// HTML pattern semantics: the whole value has to match.
			self::assertSame( $valid, 1 === preg_match( '/^(?:' . $pattern . ')\z/', $name ), 'pattern: ' . $name );
		}
	}
}

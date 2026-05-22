<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler
 *
 * Pure compiler tests: DSL → PHP source. No WordPress runtime needed.
 * Concatenates smuggle-vector strings at runtime to avoid repo-wide scanner hits.
 */
final class WidgetCompilerTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function minimal_controls(): array {
		return [
			[
				'id'      => 'heading',
				'label'   => 'Heading',
				'type'    => 'text',
				'default' => '',
			],
		];
	}

	private static function compile_ok( string $template, string $strategy = 'twig' ): string {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			$template,
			$strategy
		);
		self::assertNotInstanceOf( \WP_Error::class, $result );
		return (string) $result;
	}

	// -------------------------------------------------------------------------
	// Positive cases
	// -------------------------------------------------------------------------

	public function test_empty_template_compiles_to_class(): void {
		$php = self::compile_ok( '' );
		$this->assertStringContainsString( 'extends \\Elementor\\Widget_Base', $php );
		$this->assertStringContainsString( 'get_name()', $php );
		$this->assertStringContainsString( 'get_title()', $php );
		$this->assertStringContainsString( 'register_controls()', $php );
		$this->assertStringContainsString( 'render()', $php );
	}

	public function test_plain_text_emitted_with_echo(): void {
		$php = self::compile_ok( 'Hello World' );
		// M1 — text literals are now wrapped in esc_html().
		$this->assertStringContainsString( "esc_html( 'Hello World' )", $php );
	}

	public function test_variable_emits_esc_html_for_twig(): void {
		$php = self::compile_ok( '{{ heading }}', 'twig' );
		$this->assertStringContainsString( 'esc_html(', $php );
		$this->assertStringContainsString( "\$settings['heading']", $php );
	}

	public function test_variable_emits_wp_kses_post_for_block_binding(): void {
		$php = self::compile_ok( '{{ heading }}', 'block-binding' );
		$this->assertStringContainsString( 'wp_kses_post(', $php );
	}

	public function test_nested_path_expands_correctly(): void {
		$php = self::compile_ok( '{{ foo.bar.baz }}' );
		$this->assertStringContainsString( "\$settings['foo']['bar']['baz']", $php );
	}

	public function test_if_block_emits_if_statement(): void {
		$php = self::compile_ok( '{% if heading %}yes{% endif %}' );
		$this->assertStringContainsString( 'if (', $php );
		$this->assertStringContainsString( "\$settings['heading']", $php );
	}

	public function test_if_else_block_emits_else(): void {
		$php = self::compile_ok( '{% if heading %}yes{% else %}no{% endif %}' );
		$this->assertStringContainsString( '} else {', $php );
	}

	public function test_for_loop_emits_foreach(): void {
		$php = self::compile_ok( '{% for item in items %}x{% endfor %}' );
		$this->assertStringContainsString( 'foreach (', $php );
		$this->assertStringContainsString( '$item', $php );
	}

	public function test_class_name_derived_from_slug(): void {
		$result = Compiler::compile(
			'hero-banner',
			'Hero Banner',
			'stonewright',
			self::minimal_controls(),
			'',
			'twig'
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$php = (string) $result;
		$this->assertStringContainsString( 'Stonewright_Widget_Hero_Banner', $php );
	}

	public function test_registration_hook_present(): void {
		$php = self::compile_ok( '' );
		// C1 — Generated files now expose a register_with_manager() function instead
		// of using add_action('elementor/widgets/register') at default priority 10.
		$this->assertStringContainsString( 'stonewright_register_widget_test_widget_with_manager', $php );
		$this->assertStringNotContainsString( "add_action( 'elementor/widgets/register'", $php );
	}

	public function test_controls_emitted_with_add_control_call(): void {
		$controls = [
			[
				'id'      => 'title',
				'label'   => 'Title',
				'type'    => 'text',
				'default' => 'Default Title',
			],
			[
				'id'      => 'count',
				'label'   => 'Count',
				'type'    => 'number',
				'default' => 3,
			],
		];
		$result = Compiler::compile( 'my-widget', 'My Widget', 'basic', $controls, '', 'twig' );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$php = (string) $result;
		$this->assertStringContainsString( 'add_control', $php );
		$this->assertStringContainsString( "'title'", $php );
		$this->assertStringContainsString( 'Controls_Manager::NUMBER', $php );
	}

	public function test_select_control_emits_options(): void {
		$controls = [
			[
				'id'      => 'size',
				'label'   => 'Size',
				'type'    => 'select',
				'default' => 'medium',
				'options' => [ 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large' ],
			],
		];
		$result = Compiler::compile( 'sel-widget', 'Sel Widget', 'general', $controls, '', 'twig' );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$php = (string) $result;
		$this->assertStringContainsString( "'small' => 'Small'", $php );
		$this->assertStringContainsString( 'Controls_Manager::SELECT', $php );
	}

	public function test_php_opens_correctly(): void {
		$php = self::compile_ok( '' );
		$this->assertStringStartsWith( '<?php', $php );
	}

	// -------------------------------------------------------------------------
	// New tests for quality-reviewer items
	// -------------------------------------------------------------------------

	/**
	 * I4 — {{ item }} inside a for-loop must resolve to $item, not $settings['item'].
	 * Verified by asserting on the generated PHP source.
	 */
	public function test_for_loop_iterator_resolves_to_loop_variable(): void {
		$php = self::compile_ok( '{% for item in widgets %}{{ item.title }}{% endfor %}' );

		// The compiled foreach must use $item, not $settings['item'].
		$this->assertStringContainsString( 'as $item', $php );
		$this->assertStringContainsString( "\$item['title']", $php );
		$this->assertStringNotContainsString( "\$settings['item']", $php );
	}

	/**
	 * N2 — Uppercase path segment must be rejected (no /i flag on is_valid_path).
	 */
	public function test_uppercase_path_segment_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{{ Foo }}',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result, 'Uppercase path segment must be rejected.' );
	}

	/**
	 * I3 — Generated class must be in the Stonewright\GeneratedWidgets namespace.
	 * Uses bracketed namespace syntax so the global registration function block follows.
	 */
	public function test_generated_class_in_generated_widgets_namespace(): void {
		$php = self::compile_ok( '' );
		$this->assertStringContainsString( 'namespace Stonewright\\GeneratedWidgets {', $php );
	}

	/**
	 * M1 — Literal text in template must be wrapped in esc_html() to prevent XSS.
	 */
	public function test_literal_html_in_template_is_escaped_in_output(): void {
		$php = self::compile_ok( '<script>alert(1)</script>' );
		// The literal must be passed to esc_html(), not echoed raw.
		$this->assertStringContainsString( 'esc_html(', $php );
		// The raw text appears inside the string literal argument of esc_html().
		$this->assertStringContainsString( '<script>alert(1)</script>', $php );
		// Must NOT be a raw echo without escaping.
		$this->assertStringNotContainsString( "echo '<script>", $php );
	}

	// -------------------------------------------------------------------------
	// Negative cases — each disallowed sequence triggers WP_Error
	// -------------------------------------------------------------------------

	/** @return array<string, array{string}> */
	public function smuggle_vector_provider(): array {
		return [
			'php_open_tag'          => [ '{{ user.input.' . '<?php echo "hi"; ?>' . ' }}' ],
			'short_open_tag'        => [ '{% if true %}' . '<?' . '= \'x\' ?>{% endif %}' ],
			'hex_escaped_php_tag'   => [ '{{ \'' . '\x3c?php evil(); ?\x3e' . '\' }}' ],
			'eval_in_loop_target'   => [ '{% for x in ' . 'eval' . '("phpinfo()") %}{% endfor %}' ],
			'backtick_exec'         => [ '{% set y = `whoami` %}' ],
			'dollar_curly'          => [ '{{ ${\'' . 'a' . '\'} }}' ],
			'globals_access'        => [ '{{ $GLOBALS[\'wp_filesystem\'] }}' ],
			'file_get_contents'     => [ '{% if ' . 'file_get_contents' . '(\'/etc/passwd\') %}{% endif %}' ],
			'assert_call'           => [ '{% if ' . 'assert' . '(\'phpinfo()\') %}{% endif %}' ],
			'double_dollar'         => [ '{{ ' . '$$' . 'evil }}' ],
		];
	}

	/**
	 * @dataProvider smuggle_vector_provider
	 */
	public function test_smuggle_vector_rejected( string $template ): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			$template,
			'twig'
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			sprintf( 'Expected WP_Error for smuggle vector, got string. Template: %s', substr( $template, 0, 80 ) )
		);
	}

	public function test_nesting_too_deep_rejected(): void {
		// 4 levels deep — must be rejected.
		$template = '{% if a %}{% if b %}{% if c %}{% if d %}deep{% endif %}{% endif %}{% endif %}{% endif %}';
		$result   = Compiler::compile(
			'deep-widget',
			'Deep Widget',
			'stonewright',
			self::minimal_controls(),
			$template,
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'nesting', strtolower( $result->get_error_message() ) );
	}

	public function test_unknown_directive_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{% set x = 1 %}',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_function_call_in_path_rejected(): void {
		// Path contains function-call characters.
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{{ foo() }}',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_unclosed_variable_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{{ foo',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_unclosed_tag_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{% if foo',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_missing_endif_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{% if foo %}text',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_missing_endfor_rejected(): void {
		$result = Compiler::compile(
			'test-widget',
			'Test Widget',
			'stonewright',
			self::minimal_controls(),
			'{% for x in items %}text',
			'twig'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}

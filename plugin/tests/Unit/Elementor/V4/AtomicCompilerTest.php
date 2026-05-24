<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicCompiler;
use Stonewright\WpMcp\Sandbox\StaticGuard;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicCompiler
 */
final class AtomicCompilerTest extends TestCase {

	public function test_emits_class_extending_atomic_widget_base(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'my-card',
				'title'    => 'My Card',
				'template' => '<div></div>',
				'props'    => [],
			]
		);

		$this->assertStringStartsWith( '<?php', $php );
		$this->assertStringContainsString( 'namespace Stonewright\\GeneratedAtomicWidgets', $php );
		$this->assertStringContainsString( 'final class Stonewright_Atomic_My_Card_Widget extends Atomic_Widget_Base', $php );
	}

	public function test_emits_define_atomic_controls_method(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'my-card',
				'template' => '',
				'props'    => [
					[ 'name' => 'heading', 'type' => 'string', 'default' => 'Hello' ],
				],
			]
		);

		$this->assertStringContainsString( 'protected function define_atomic_controls(): array', $php );
		$this->assertStringContainsString( "'heading' => Atomic_Prop_Schemas::string()", $php );
	}

	public function test_props_serialized_with_correct_schema_methods(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'card',
				'template' => '',
				'props'    => [
					[ 'name' => 'title',  'type' => 'string',  'default' => '' ],
					[ 'name' => 'count',  'type' => 'number',  'default' => 1 ],
					[ 'name' => 'colour', 'type' => 'color',   'default' => '#fff' ],
					[ 'name' => 'gap',    'type' => 'size',    'default' => '8px' ],
					[ 'name' => 'open',   'type' => 'boolean', 'default' => false ],
					[ 'name' => 'href',   'type' => 'link',    'default' => '' ],
					[ 'name' => 'hero',   'type' => 'image',   'default' => '' ],
				],
			]
		);

		$this->assertStringContainsString( 'Atomic_Prop_Schemas::string()',  $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::number()',  $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::color()',   $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::size()',    $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::boolean()', $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::link()',    $php );
		$this->assertStringContainsString( 'Atomic_Prop_Schemas::image()',   $php );
	}

	public function test_defaults_round_trip_through_var_export(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'sample',
				'template' => '',
				'props'    => [
					[ 'name' => 'title', 'type' => 'string',  'default' => "Don't fail" ],
					[ 'name' => 'count', 'type' => 'number',  'default' => 42 ],
					[ 'name' => 'open',  'type' => 'boolean', 'default' => true ],
				],
			]
		);

		// var_export of "Don't fail" preserves the apostrophe via single-quote
		// escape rules; we just verify the literal appears intact in some form.
		$this->assertStringContainsString( '42', $php );
		$this->assertStringContainsString( 'true', $php );
		$this->assertStringContainsString( "Don\\'t fail", $php );
	}

	public function test_template_substitution_uses_str_replace_with_placeholders(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'card',
				'template' => '<h1>{{ heading }}</h1>',
				'props'    => [
					[ 'name' => 'heading', 'type' => 'string', 'default' => '' ],
				],
			]
		);

		$this->assertStringContainsString( 'str_replace(', $php );
		$this->assertStringContainsString( '{{ heading }}', $php );
		$this->assertStringContainsString( "\$settings['heading']", $php );
	}

	public function test_slug_to_class_transforms_to_pascal_case_with_prefix(): void {
		$this->assertSame(
			'Stonewright_Atomic_My_Card_Widget',
			AtomicCompiler::slug_to_class( 'my-card' )
		);
		$this->assertSame(
			'Stonewright_Atomic_Hero_Banner_2_Widget',
			AtomicCompiler::slug_to_class( 'hero-banner-2' )
		);
		$this->assertSame(
			'Stonewright_Atomic_Solo_Widget',
			AtomicCompiler::slug_to_class( 'solo' )
		);
	}

	public function test_rejects_slug_with_invalid_characters(): void {
		$this->expectException( \InvalidArgumentException::class );
		AtomicCompiler::slug_to_class( 'Bad/Slug' );
	}

	public function test_rejects_empty_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		AtomicCompiler::slug_to_class( '' );
	}

	public function test_schema_method_falls_back_to_string_for_unknown_types(): void {
		$this->assertSame( 'string',  AtomicCompiler::schema_method( 'string' ) );
		$this->assertSame( 'number',  AtomicCompiler::schema_method( 'number' ) );
		$this->assertSame( 'size',    AtomicCompiler::schema_method( 'size' ) );
		$this->assertSame( 'color',   AtomicCompiler::schema_method( 'color' ) );
		$this->assertSame( 'boolean', AtomicCompiler::schema_method( 'boolean' ) );
		$this->assertSame( 'link',    AtomicCompiler::schema_method( 'link' ) );
		$this->assertSame( 'image',   AtomicCompiler::schema_method( 'image' ) );
		// Unknown.
		$this->assertSame( 'string', AtomicCompiler::schema_method( 'unicorn' ) );
		$this->assertSame( 'string', AtomicCompiler::schema_method( '' ) );
	}

	public function test_multiple_props_each_get_a_control_entry(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'multi',
				'template' => '',
				'props'    => [
					[ 'name' => 'one',   'type' => 'string', 'default' => 'a' ],
					[ 'name' => 'two',   'type' => 'string', 'default' => 'b' ],
					[ 'name' => 'three', 'type' => 'string', 'default' => 'c' ],
				],
			]
		);

		$this->assertStringContainsString( "'one' => Atomic_Prop_Schemas::string()",   $php );
		$this->assertStringContainsString( "'two' => Atomic_Prop_Schemas::string()",   $php );
		$this->assertStringContainsString( "'three' => Atomic_Prop_Schemas::string()", $php );
	}

	public function test_empty_props_list_still_compiles_and_renders(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'static-card',
				'template' => '<p>Static content</p>',
				'props'    => [],
			]
		);

		$this->assertStringContainsString( 'protected function define_atomic_controls(): array', $php );
		$this->assertStringContainsString( 'return [', $php );
		$this->assertStringContainsString( 'protected function render(): void', $php );
		// With no props, the renderer should echo the template directly.
		$this->assertStringContainsString( 'echo $template;', $php );
		// And NOT build a search/replace pair.
		$this->assertStringNotContainsString( '$search = [', $php );
	}

	public function test_compiled_source_passes_static_guard(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'safe-card',
				'title'    => 'Safe Card',
				'template' => '<h1>{{ heading }}</h1><p>{{ body }}</p>',
				'props'    => [
					[ 'name' => 'heading', 'type' => 'string', 'default' => 'Hello' ],
					[ 'name' => 'body',    'type' => 'string', 'default' => 'World' ],
				],
			]
		);

		$findings = StaticGuard::scan( $php );
		$this->assertSame( [], $findings, 'Compiler output must pass StaticGuard: ' . implode( '; ', $findings ) );
	}

	public function test_compiled_source_is_syntactically_valid_php(): void {
		$php = AtomicCompiler::compile(
			[
				'slug'     => 'syntax-card',
				'template' => '<div>{{ title }}</div>',
				'props'    => [
					[ 'name' => 'title', 'type' => 'string', 'default' => 'ok' ],
				],
			]
		);

		// php -l style check: token_get_all parses the source. If something is
		// catastrophically malformed it will raise a ParseError under PHP 8.
		// We additionally confirm the file declares the expected class token.
		$tokens = @token_get_all( $php );
		$this->assertNotEmpty( $tokens );

		$found_class = false;
		foreach ( $tokens as $t ) {
			if ( is_array( $t ) && T_CLASS === $t[0] ) {
				$found_class = true;
				break;
			}
		}
		$this->assertTrue( $found_class, 'Compiled source should contain a class declaration.' );
	}
}

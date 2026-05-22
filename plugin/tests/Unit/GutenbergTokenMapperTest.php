<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;
use Stonewright\WpMcp\Gutenberg\Renderer\Heading;
use Stonewright\WpMcp\Gutenberg\Renderer\Paragraph;
use Stonewright\WpMcp\Gutenberg\Renderer\Spacer;

/**
 * Tests for token-to-block-attribute mapping (Gap 2).
 *
 * @covers \Stonewright\WpMcp\Gutenberg\TokenMapper
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Heading
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Paragraph
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer\Spacer
 */
final class GutenbergTokenMapperTest extends TestCase {

	private string $fixture_dir;

	protected function setUp(): void {
		$this->fixture_dir = dirname( __DIR__ ) . '/fixtures/gutenberg';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fixture( string $name ): array {
		$path = $this->fixture_dir . '/' . $name . '.json';
		self::assertFileExists( $path, "Missing fixture: {$name}.json" );
		$raw     = file_get_contents( $path );
		self::assertIsString( $raw );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded, "Fixture {$name}.json is not valid JSON" );
		return $decoded;
	}

	// -------------------------------------------------------------------------
	// TokenMapper::apply — unit tests
	// -------------------------------------------------------------------------

	public function test_apply_color_hex_text_context(): void {
		$resolver = new Resolver( [ 'colors' => [ 'primary' => '#0073aa' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.primary' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( '#0073aa', $attrs['style']['color']['text'] );
	}

	public function test_apply_color_hex_background_context(): void {
		$resolver = new Resolver( [ 'colors' => [ 'bg' => '#ffffff' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.bg' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'background' );
		$this->assertSame( '#ffffff', $attrs['style']['color']['background'] );
	}

	public function test_apply_color_slug_text_context(): void {
		$resolver = new Resolver( [ 'colors' => [ 'accent' => 'primary' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.accent' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( 'primary', $attrs['textColor'] );
		$this->assertSame( 'var:preset|color|primary', $attrs['style']['color']['text'] );
	}

	public function test_apply_color_slug_background_context(): void {
		$resolver = new Resolver( [ 'colors' => [ 'bgslug' => 'secondary' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.bgslug' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'background' );
		$this->assertSame( 'secondary', $attrs['backgroundColor'] );
		$this->assertSame( 'var:preset|color|secondary', $attrs['style']['color']['background'] );
	}

	public function test_apply_font_size_px(): void {
		$resolver = new Resolver( [ 'fonts' => [ 'sizelg' => '24px' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'fontSize', 'token' => 'fonts.sizelg' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( '24px', $attrs['style']['typography']['fontSize'] );
	}

	public function test_apply_font_size_slug(): void {
		$resolver = new Resolver( [ 'fonts' => [ 'sizelg' => 'large' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'fontSize', 'token' => 'fonts.sizelg' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( 'large', $attrs['fontSize'] );
	}

	public function test_apply_spacing_numeric_normalizes_to_px(): void {
		$resolver = new Resolver( [ 'spacing' => [ 'md' => '16' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'spacing', 'token' => 'spacing.md' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( '16px', $attrs['style']['spacing']['padding']['top'] );
		$this->assertSame( '16px', $attrs['style']['spacing']['padding']['right'] );
		$this->assertSame( '16px', $attrs['style']['spacing']['padding']['bottom'] );
		$this->assertSame( '16px', $attrs['style']['spacing']['padding']['left'] );
	}

	public function test_apply_spacing_with_px_unit_kept(): void {
		$resolver = new Resolver( [ 'spacing' => [ 'lg' => '32px' ] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'spacing', 'token' => 'spacing.lg' ],
			],
		];
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( '32px', $attrs['style']['spacing']['padding']['top'] );
	}

	public function test_apply_no_tokens_returns_unchanged_attrs(): void {
		$resolver = new Resolver( [] );
		$existing = [ 'sizeSlug' => 'large', 'id' => 5 ];
		$node     = [ 'type' => 'image' ];
		$result   = TokenMapper::apply( $node, $existing, $resolver, 'text' );
		$this->assertSame( $existing, $result );
	}

	public function test_apply_missing_token_skips_gracefully(): void {
		$resolver = new Resolver( [ 'colors' => [] ] );
		$node     = [
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.nonexistent' ],
			],
		];
		// Should return empty attrs without throwing.
		$attrs = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( [], $attrs );
	}

	public function test_apply_is_deterministic(): void {
		$resolver = new Resolver( [
			'colors'  => [ 'primary' => '#0073aa' ],
			'spacing' => [ 'md' => '16' ],
		] );
		$node = [
			'tokens' => [
				[ 'property' => 'color',   'token' => 'colors.primary' ],
				[ 'property' => 'spacing', 'token' => 'spacing.md' ],
			],
		];
		$result1 = TokenMapper::apply( $node, [], $resolver, 'text' );
		$result2 = TokenMapper::apply( $node, [], $resolver, 'text' );
		$this->assertSame( $result1, $result2 );
	}

	// -------------------------------------------------------------------------
	// Renderer integration: color token on Paragraph → golden fixture
	// -------------------------------------------------------------------------

	public function test_paragraph_with_color_token_matches_fixture(): void {
		$resolver = new Resolver( [ 'colors' => [ 'primary' => '#0073aa' ] ] );
		$node     = [
			'type' => 'paragraph',
			'text' => 'Token test.',
			'tokens' => [
				[ 'property' => 'color', 'token' => 'colors.primary' ],
			],
		];
		$result = Paragraph::render( $node, 's0.b0', $resolver );
		$this->assertSame( $this->fixture( 'paragraph-tokens' ), $result );
	}

	// -------------------------------------------------------------------------
	// Renderer integration: fontSize token on Heading → golden fixture
	// -------------------------------------------------------------------------

	public function test_heading_with_font_size_token_matches_fixture(): void {
		$resolver = new Resolver( [ 'fonts' => [ 'h2' => '24px' ] ] );
		$node     = [
			'type'   => 'heading',
			'text'   => 'Token heading.',
			'level'  => 2,
			'tokens' => [
				[ 'property' => 'fontSize', 'token' => 'fonts.h2' ],
			],
		];
		$result = Heading::render( $node, 's0.b0', $resolver );
		$this->assertSame( $this->fixture( 'heading-tokens' ), $result );
	}

	// -------------------------------------------------------------------------
	// Renderer integration: spacing token on Spacer → golden fixture
	// -------------------------------------------------------------------------

	public function test_spacer_with_spacing_token_matches_fixture(): void {
		$resolver = new Resolver( [ 'spacing' => [ 'pad' => '16' ] ] );
		$node     = [
			'type'   => 'spacer',
			'height' => 24,
			'tokens' => [
				[ 'property' => 'spacing', 'token' => 'spacing.pad' ],
			],
		];
		$result = Spacer::render( $node, 's0.b0', $resolver );
		$this->assertSame( $this->fixture( 'spacer-tokens' ), $result );
	}
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\SerializedResultGuard;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\SerializedResultGuard
 */
final class SerializedResultGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/heading'   => (object) [
				'attributes' => [
					'level'   => [ 'type' => 'integer', 'default' => 2 ],
					'content' => [ 'type' => 'string', 'source' => 'html' ],
				],
			],
			'core/paragraph' => (object) [
				'attributes' => [
					'dropCap' => [ 'type' => 'boolean', 'default' => false ],
					'content' => [ 'type' => 'string', 'source' => 'html' ],
				],
			],
			'core/group'     => (object) [ 'attributes' => [ 'layout' => [ 'type' => 'object' ] ] ],
			'core/html'      => (object) [ 'attributes' => [ 'content' => [ 'type' => 'string' ] ] ],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	/**
	 * @dataProvider legitimateOutputProvider
	 * @param array<string,mixed> $spec
	 */
	public function test_accepts_what_the_native_editor_serializes_for_the_queued_spec( array $spec, string $html ): void {
		self::assertNull( SerializedResultGuard::refusal( $this->record( $spec ), $html ) );
	}

	/** @return array<string, array{0:array<string,mixed>,1:string}> */
	public static function legitimateOutputProvider(): array {
		return [
			'paragraph'                             => [
				self::spec( 'core/paragraph', [ 'content' => 'Hello' ] ),
				"<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
			],
			'paragraph with comment attributes in another order' => [
				self::spec( 'core/paragraph', [ 'content' => 'Hi', 'className' => 'Tom & Jerry', 'align' => 'center' ] ),
				"<!-- wp:paragraph {\"align\":\"center\",\"className\":\"Tom \\u0026 Jerry\"} -->\n<p class=\"has-text-align-center Tom &amp; Jerry\">Hi</p>\n<!-- /wp:paragraph -->",
			],
			'heading whose default level the editor leaves out' => [
				self::spec( 'core/heading', [ 'level' => 2, 'content' => 'Title' ] ),
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->",
			],
			'heading with an explicit level'        => [
				self::spec( 'core/heading', [ 'level' => 3, 'content' => 'Title' ] ),
				"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Title</h3>\n<!-- /wp:heading -->",
			],
			'heading to which the editor wrote its default level' => [
				self::spec( 'core/heading', [ 'content' => 'Title' ] ),
				"<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->",
			],
			'paragraph to which the editor wrote a default' => [
				self::spec( 'core/paragraph', [ 'content' => 'Hello' ] ),
				"<!-- wp:paragraph {\"dropCap\":false} -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
			],
			'numbers that differ only in type'      => [
				self::spec( 'core/cover', [ 'dimRatio' => 50.0 ] ),
				"<!-- wp:cover {\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"></div>\n<!-- /wp:cover -->",
			],
			'nested objects in another key order'   => [
				self::spec( 'core/group', [ 'layout' => [ 'type' => 'constrained', 'contentSize' => '40rem' ] ], [ self::spec( 'core/paragraph', [ 'content' => 'a' ] ) ] ),
				"<!-- wp:group {\"layout\":{\"contentSize\":\"40rem\",\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
			],
			'group with a heading and a paragraph'  => [
				self::spec( 'core/group', [], [ self::spec( 'core/heading', [ 'level' => 3 ] ), self::spec( 'core/paragraph' ) ] ),
				"<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">T</h3>\n<!-- /wp:heading --><!-- wp:paragraph -->\n<p>p</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
			],
			'dynamic block saved as a void delimiter' => [
				self::spec( 'core/latest-posts', [ 'postsToShow' => 3 ] ),
				'<!-- wp:latest-posts {"postsToShow":3} /-->',
			],
			'third-party block'                     => [
				self::spec( 'acme/card', [ 'title' => 'Stone' ] ),
				"<!-- wp:acme/card {\"title\":\"Stone\"} -->\n<div class=\"wp-block-acme-card\">Stone</div>\n<!-- /wp:acme/card -->",
			],
			'client-only block with an attribute the spec left out' => [
				self::spec( 'acme/widget' ),
				"<!-- wp:acme/widget {\"size\":\"large\"} -->\n<div>x</div>\n<!-- /wp:acme/widget -->",
			],
			'surrounding whitespace'                => [
				self::spec( 'core/paragraph' ),
				"\n  <!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n\n",
			],
		];
	}

	/**
	 * @dataProvider structureMismatchProvider
	 * @param array<string,mixed> $spec
	 */
	public function test_refuses_output_whose_blocks_or_attributes_do_not_match_the_spec( array $spec, string $html ): void {
		$refusal = SerializedResultGuard::refusal( $this->record( $spec ), $html );

		self::assertIsArray( $refusal );
		self::assertSame( 'serialized_structure_mismatch', $refusal['code'] );
		self::assertNotSame( '', $refusal['message'] );
		self::assertLessThanOrEqual( 500, strlen( $refusal['message'] ) );
	}

	/** @return array<string, array{0:array<string,mixed>,1:string}> */
	public static function structureMismatchProvider(): array {
		$paragraph = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
		return [
			'another core block'                    => [ self::spec( 'core/paragraph' ), '<!-- wp:heading --><h2>x</h2><!-- /wp:heading -->' ],
			'the same name in another namespace'    => [ self::spec( 'core/paragraph' ), '<!-- wp:acme/paragraph --><p>x</p><!-- /wp:acme/paragraph -->' ],
			'a second top-level block'              => [ self::spec( 'core/paragraph' ), $paragraph . $paragraph ],
			'a trailing extra block'                => [ self::spec( 'core/paragraph' ), $paragraph . '<!-- wp:html --><div>x</div><!-- /wp:html -->' ],
			'plain html outside any block'          => [ self::spec( 'core/paragraph' ), $paragraph . '<p>tail</p>' ],
			'plain html in place of the block'      => [ self::spec( 'core/paragraph' ), '<p>Different</p>' ],
			'no output at all'                      => [ self::spec( 'core/paragraph' ), '' ],
			'a block that is never closed'          => [ self::spec( 'core/paragraph' ), '<!-- wp:paragraph --><p>x</p>' ],
			'a closer for another block'            => [ self::spec( 'core/paragraph' ), '<!-- wp:paragraph --><p>x</p><!-- /wp:heading -->' ],
			'an inner block the spec does not have' => [ self::spec( 'core/group' ), '<!-- wp:group --><div><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ],
			'an inner block that is missing'        => [ self::spec( 'core/group', [], [ self::spec( 'core/paragraph' ) ] ), '<!-- wp:group --><div></div><!-- /wp:group -->' ],
			'inner blocks in the wrong order'       => [
				self::spec( 'core/group', [], [ self::spec( 'core/paragraph' ), self::spec( 'core/heading' ) ] ),
				'<!-- wp:group --><div><!-- wp:heading --><h2>x</h2><!-- /wp:heading --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			],
			'a deeper inner block of another name'  => [
				self::spec( 'core/group', [], [ self::spec( 'core/group', [], [ self::spec( 'core/paragraph' ) ] ) ] ),
				'<!-- wp:group --><div><!-- wp:group --><div><!-- wp:heading --><h2>x</h2><!-- /wp:heading --></div><!-- /wp:group --></div><!-- /wp:group -->',
			],
			'an attribute with another value'       => [ self::spec( 'core/heading', [ 'level' => 3 ] ), '<!-- wp:heading {"level":1} --><h1>x</h1><!-- /wp:heading -->' ],
			'an attribute value of another type'    => [ self::spec( 'acme/card', [ 'count' => 5 ] ), '<!-- wp:acme/card {"count":"5"} --><div></div><!-- /wp:acme/card -->' ],
			'an attribute the registered block does not default to' => [ self::spec( 'core/heading', [ 'content' => 'x' ] ), '<!-- wp:heading {"level":4} --><h4>x</h4><!-- /wp:heading -->' ],
			'an extra attribute on a registered block without defaults' => [ self::spec( 'core/group' ), '<!-- wp:group {"className":"injected"} --><div></div><!-- /wp:group -->' ],
			'a registered default with the other value' => [ self::spec( 'core/paragraph' ), '<!-- wp:paragraph {"dropCap":true} --><p>x</p><!-- /wp:paragraph -->' ],
			'attributes in a nested block'          => [
				self::spec( 'core/group', [], [ self::spec( 'core/heading', [ 'level' => 3 ] ) ] ),
				'<!-- wp:group --><div><!-- wp:heading {"level":5} --><h5>x</h5><!-- /wp:heading --></div><!-- /wp:group -->',
			],
		];
	}

	/** @dataProvider customCodeProvider */
	public function test_refuses_custom_code_the_change_was_not_approved_for( string $inside ): void {
		$spec = self::spec( 'core/paragraph' );

		foreach ( [
			'inside the block'   => '<!-- wp:paragraph -->' . $inside . '<!-- /wp:paragraph -->',
			'after the block'    => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' . $inside,
		] as $where => $html ) {
			$refusal = SerializedResultGuard::refusal( $this->record( $spec ), $html );
			self::assertIsArray( $refusal, $where );
			self::assertSame( 'serialized_markup_refused', $refusal['code'], $where );
		}
	}

	/** @return array<string, array{0:string}> */
	public static function customCodeProvider(): array {
		return [
			'script tag'        => [ '<p>x</p><script>alert(1)</script>' ],
			'style tag'         => [ '<p>x</p><style>p{color:red}</style>' ],
			'iframe'            => [ '<iframe src="https://example.test/"></iframe>' ],
			'onerror attribute' => [ '<p><img src=x onerror=alert(1)></p>' ],
			'javascript url'    => [ '<p><a href="javascript:alert(1)">x</a></p>' ],
		];
	}

	public function test_custom_code_is_accepted_only_for_the_kinds_the_change_was_approved_for(): void {
		$spec  = self::spec( 'core/html', [ 'content' => '<style>.a{color:red}</style>' ] );
		$style = "<!-- wp:html -->\n<style>.a{color:red}</style>\n<!-- /wp:html -->";
		$both  = "<!-- wp:html -->\n<style>.a{color:red}</style><script>alert(1)</script>\n<!-- /wp:html -->";

		self::assertNull( SerializedResultGuard::refusal( $this->record( $spec, [ 'style' ] ), $style ) );
		self::assertNull( SerializedResultGuard::refusal( $this->record( $spec, [ 'style', 'script' ] ), $both ) );

		$refusal = SerializedResultGuard::refusal( $this->record( $spec, [ 'style' ] ), $both );
		self::assertIsArray( $refusal );
		self::assertSame( 'serialized_markup_refused', $refusal['code'] );
		self::assertStringContainsString( 'script', $refusal['message'] );
		self::assertStringNotContainsString( 'style', $refusal['message'] );

		// Without any recorded approval, even the CSS the spec itself carried is refused.
		$bare = SerializedResultGuard::refusal( $this->record( $spec ), $style );
		self::assertIsArray( $bare );
		self::assertSame( 'serialized_markup_refused', $bare['code'] );
	}

	public function test_refusal_messages_never_echo_the_submitted_markup(): void {
		$marker = 'marker-text-for-test';
		$bad    = SerializedResultGuard::refusal(
			$this->record( self::spec( 'core/paragraph' ) ),
			'<!-- wp:paragraph {"note":"' . $marker . '"} --><p onclick="' . $marker . '">x</p><!-- /wp:paragraph --><!-- wp:html -->' . $marker . '<!-- /wp:html -->'
		);

		self::assertIsArray( $bad );
		self::assertStringNotContainsString( $marker, $bad['message'] );
	}

	public function test_a_top_level_classic_block_is_serialized_as_bare_html_and_may_not_carry_delimiters(): void {
		$spec = self::spec( 'core/freeform', [ 'content' => '<p>Right</p>' ] );

		self::assertNull( SerializedResultGuard::refusal( $this->record( $spec ), "<p>Right</p>\n" ) );

		$smuggled = SerializedResultGuard::refusal( $this->record( $spec ), '<p>Right</p><!-- wp:html --><div>x</div><!-- /wp:html -->' );
		self::assertIsArray( $smuggled );
		self::assertSame( 'serialized_structure_mismatch', $smuggled['code'] );

		$closer = SerializedResultGuard::refusal( $this->record( $spec ), '<p>Right</p><!-- /wp:group -->' );
		self::assertIsArray( $closer );
		self::assertSame( 'serialized_structure_mismatch', $closer['code'] );

		$code = SerializedResultGuard::refusal( $this->record( $spec ), '<p>Right</p><script>alert(1)</script>' );
		self::assertIsArray( $code );
		self::assertSame( 'serialized_markup_refused', $code['code'] );

		$nested = SerializedResultGuard::refusal( $this->record( self::spec( 'core/freeform', [], [ self::spec( 'core/paragraph' ) ] ) ), '<p>Right</p>' );
		self::assertIsArray( $nested );
		self::assertSame( 'serialized_structure_mismatch', $nested['code'] );

		// Inside another block the classic block keeps its delimiters like any other block.
		$inner = self::spec( 'core/group', [], [ self::spec( 'core/freeform', [ 'content' => '<p>x</p>' ] ) ] );
		self::assertNull( SerializedResultGuard::refusal( $this->record( $inner ), '<!-- wp:group --><div><!-- wp:freeform --><p>x</p><!-- /wp:freeform --></div><!-- /wp:group -->' ) );
	}

	public function test_a_record_without_a_usable_spec_refuses_everything(): void {
		$refusal = SerializedResultGuard::refusal( [ 'id' => 'change-1' ], '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );

		self::assertIsArray( $refusal );
		self::assertSame( 'serialized_structure_mismatch', $refusal['code'] );
	}

	/**
	 * @param array<string,mixed> $attributes
	 * @param list<array<string,mixed>> $inner
	 * @return array<string,mixed>
	 */
	private static function spec( string $name, array $attributes = [], array $inner = [] ): array {
		return [ 'name' => $name, 'attributes' => $attributes, 'innerBlocks' => $inner ];
	}

	/**
	 * @param array<string,mixed> $spec
	 * @param list<string> $approved
	 * @return array<string,mixed>
	 */
	private function record( array $spec, array $approved = [] ): array {
		return [ 'id' => 'change-1', 'block_spec' => $spec, 'custom_code' => $approved ];
	}
}

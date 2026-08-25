<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssTarget;
use Stonewright\WpMcp\Elementor\CssTargetResolver;

/** @covers \Stonewright\WpMcp\Elementor\CssTargetResolver */
final class CssTargetResolverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$GLOBALS['stonewright_test_posts']    = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_home_url'], $GLOBALS['stonewright_test_posts'] );
	}

	public function test_resolves_a_normal_page_to_post_css(): void {
		$resolver = $this->resolver(
			[
				101 => [
					'post_type'      => 'page',
					'document_name'  => 'wp-page',
					'template_type'  => '',
				],
			]
		);

		$post = $resolver->resolve( 101, 'auto' );

		self::assertInstanceOf( CssTarget::class, $post );
		self::assertSame( 'post', $post->kind() );
		self::assertSame( 'post-101.css', $post->filename() );
		self::assertSame( \Elementor\Core\Files\CSS\Post::class, $post->runtime_class() );
		self::assertSame( 101, $post->post_id() );
	}

	public function test_resolves_an_active_kit_to_post_css(): void {
		$target = $this->resolver(
			[
				4 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'kit',
					'template_type' => 'kit',
				],
			]
		)->resolve( 4, 'auto' );

		self::assertInstanceOf( CssTarget::class, $target );
		self::assertSame( 'post', $target->kind() );
		self::assertSame( 'post-4.css', $target->filename() );
	}

	public function test_resolves_a_saved_template_to_post_css(): void {
		$target = $this->resolver(
			[
				55 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'section',
					'template_type' => 'section',
				],
			]
		)->resolve( 55, 'auto' );

		self::assertInstanceOf( CssTarget::class, $target );
		self::assertSame( 'post', $target->kind() );
		self::assertSame( 'post-55.css', $target->filename() );
	}

	public function test_resolves_a_theme_builder_document_to_post_css(): void {
		$target = $this->resolver(
			[
				88 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'header',
					'template_type' => 'header',
				],
			]
		)->resolve( 88, 'auto' );

		self::assertInstanceOf( CssTarget::class, $target );
		self::assertSame( 'post', $target->kind() );
		self::assertSame( 'post-88.css', $target->filename() );
	}

	public function test_resolves_a_popup_to_post_css(): void {
		$target = $this->resolver(
			[
				77 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'popup',
					'template_type' => 'popup',
				],
			]
		)->resolve( 77, 'auto' );

		self::assertInstanceOf( CssTarget::class, $target );
		self::assertSame( 'post', $target->kind() );
		self::assertSame( 'post-77.css', $target->filename() );
	}

	public function test_resolves_a_loop_item_to_loop_css(): void {
		$loop = $this->resolver(
			[
				202 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'loop-item',
					'template_type' => 'loop-item',
				],
			]
		)->resolve( 202, 'auto' );

		self::assertInstanceOf( CssTarget::class, $loop );
		self::assertSame( 'loop', $loop->kind() );
		self::assertSame( 'loop-202.css', $loop->filename() );
		self::assertSame( 'ElementorPro\\Modules\\LoopBuilder\\Files\\Css\\Loop', $loop->runtime_class() );
	}

	public function test_rejects_an_explicit_kind_mismatch(): void {
		$resolver = $this->resolver(
			[
				101 => [
					'post_type'     => 'page',
					'document_name' => 'wp-page',
					'template_type' => '',
				],
				202 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'loop-item',
					'template_type' => 'loop-item',
				],
			]
		);

		$page_as_loop = $resolver->resolve( 101, 'loop' );
		self::assertInstanceOf( \WP_Error::class, $page_as_loop );
		self::assertSame( 'stonewright_elementor_css_kind_mismatch', $page_as_loop->get_error_code() );

		$loop_as_post = $resolver->resolve( 202, 'post' );
		self::assertInstanceOf( \WP_Error::class, $loop_as_post );
		self::assertSame( 'stonewright_elementor_css_kind_mismatch', $loop_as_post->get_error_code() );
	}

	public function test_does_not_infer_loop_kind_from_a_caller_claim(): void {
		$resolver = $this->resolver(
			[
				101 => [
					'post_type'     => 'page',
					'document_name' => 'wp-page',
					'template_type' => '',
				],
			]
		);

		$result = $resolver->resolve( 101, 'loop' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_kind_mismatch', $result->get_error_code() );
	}

	public function test_fails_when_elementor_core_css_class_is_missing(): void {
		$resolver = $this->resolver(
			[
				101 => [
					'post_type'     => 'page',
					'document_name' => 'wp-page',
					'template_type' => '',
				],
			],
			static fn( string $class ): bool => 'Elementor\\Core\\Files\\CSS\\Post' !== ltrim( $class, '\\' )
		);

		$result = $resolver->resolve( 101, 'auto' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_runtime_unavailable', $result->get_error_code() );
	}

	public function test_fails_when_pro_loop_css_class_is_missing(): void {
		$resolver = $this->resolver(
			[
				202 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'loop-item',
					'template_type' => 'loop-item',
				],
			],
			static fn( string $class ): bool => ! str_contains( $class, 'LoopBuilder\\Files\\Css\\Loop' )
		);

		$result = $resolver->resolve( 202, 'auto' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_runtime_unavailable', $result->get_error_code() );
	}

	public function test_fails_closed_for_an_ambiguous_template_type(): void {
		$resolver = $this->resolver(
			[
				303 => [
					'post_type'     => 'elementor_library',
					'document_name' => '',
					'template_type' => '',
				],
			]
		);

		$result = $resolver->resolve( 303, 'auto' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_target_ambiguous', $result->get_error_code() );
	}

	public function test_fails_when_document_and_template_evidence_conflict(): void {
		$resolver = $this->resolver(
			[
				404 => [
					'post_type'     => 'elementor_library',
					'document_name' => 'wp-page',
					'template_type' => 'loop-item',
				],
			]
		);

		$result = $resolver->resolve( 404, 'auto' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_target_ambiguous', $result->get_error_code() );
	}

	public function test_rejects_an_invalid_post_id(): void {
		$resolver = $this->resolver( [] );

		$result = $resolver->resolve( 0, 'auto' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_invalid_post', $result->get_error_code() );
	}

	public function test_rejects_an_unknown_requested_kind(): void {
		$resolver = $this->resolver(
			[
				101 => [
					'post_type'     => 'page',
					'document_name' => 'wp-page',
					'template_type' => '',
				],
			]
		);

		$result = $resolver->resolve( 101, 'kit' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_kind_invalid', $result->get_error_code() );
	}

	public function test_target_json_and_audit_output_omit_raw_paths(): void {
		$target = $this->resolver(
			[
				101 => [
					'post_type'     => 'page',
					'document_name' => 'wp-page',
					'template_type' => '',
				],
			]
		)->resolve( 101, 'auto' );

		self::assertInstanceOf( CssTarget::class, $target );
		$encoded = (string) wp_json_encode( $target );
		self::assertStringNotContainsString( $target->path(), $encoded );
		self::assertStringNotContainsString( $target->url(), $encoded );
		self::assertArrayNotHasKey( 'path', $target->jsonSerialize() );
		self::assertArrayNotHasKey( 'url', $target->jsonSerialize() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $target->jsonSerialize()['path_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $target->jsonSerialize()['url_sha256'] );
	}

	/**
	 * @param array<int, array{post_type:string,document_name:string,template_type:string}> $evidence
	 * @param callable(string):bool|null                                                     $class_exists
	 */
	private function resolver( array $evidence, ?callable $class_exists = null ): CssTargetResolver {
		return new CssTargetResolver(
			static function ( int $post_id ) use ( $evidence ): array|\WP_Error {
				if ( ! isset( $evidence[ $post_id ] ) ) {
					return new \WP_Error( 'stonewright_elementor_css_invalid_post', 'Unknown synthetic post.' );
				}
				return $evidence[ $post_id ];
			},
		$class_exists ?? static fn( string $class ): bool => true
		);
	}
}

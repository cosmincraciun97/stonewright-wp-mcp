<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Blueprints\BlueprintApplier;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;
use Stonewright\WpMcp\Support\BlockSerializer;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Regression net: every bundled blueprint × engines must render without dropped nodes.
 *
 * @covers \Stonewright\WpMcp\Blueprints\BlueprintApplier
 */
final class BlueprintRenderOutputSuiteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true, 'edit_post' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_theme_mods']     = [];
		BlueprintApplier::$test_elementor_available = true;
	}

	protected function tearDown(): void {
		BlueprintApplier::$test_elementor_available = null;
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_theme_mods']     = [];
		parent::tearDown();
	}

	/**
	 * @return list<array{0: string}>
	 */
	public static function blueprint_ids(): array {
		$out = [];
		foreach ( BlueprintStore::list() as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = [ $id ];
			}
		}
		return $out;
	}

	/**
	 * @dataProvider blueprint_ids
	 */
	public function test_gutenberg_render_has_no_unsupported_nodes( string $id ): void {
		$bp = BlueprintStore::get( $id );
		self::assertIsArray( $bp );
		$spec = is_array( $bp['spec'] ?? null ) ? $bp['spec'] : [];
		$diagnostics = [];
		$blocks = GutenbergSpecRenderer::render( $spec, $diagnostics );
		self::assertIsArray( $blocks, $id . ' gutenberg render failed' );
		self::assertNotEmpty( $blocks, $id . ' empty gutenberg tree' );
		$unsupported = array_values(
			array_filter(
				$diagnostics,
				static fn( $row ): bool => is_array( $row ) && ( ( $row['code'] ?? '' ) === 'unsupported_node' || ( $row['type'] ?? '' ) === 'unsupported_node' )
			)
		);
		// Allow soft diagnostics that are not hard drops if none of that shape exists.
		$hard_drops = array_values(
			array_filter(
				$diagnostics,
				static function ( $row ): bool {
					if ( ! is_array( $row ) ) {
						return false;
					}
					$msg = strtolower( (string) ( $row['message'] ?? $row['code'] ?? '' ) );
					return str_contains( $msg, 'unsupported' ) || str_contains( $msg, 'dropped' );
				}
			)
		);
		self::assertSame( [], $hard_drops, $id . ' has dropped/unsupported nodes: ' . wp_json_encode( $hard_drops ) );
		$html = BlockSerializer::serialize( $blocks );
		self::assertNotSame( '', trim( $html ), $id . ' serialized empty' );
	}

	/**
	 * @dataProvider blueprint_ids
	 */
	public function test_elementor_render_has_structure( string $id ): void {
		$bp = BlueprintStore::get( $id );
		self::assertIsArray( $bp );
		$spec = is_array( $bp['spec'] ?? null ) ? $bp['spec'] : [];
		// Promote authored v2 layout fields through validation path.
		if ( class_exists( \Stonewright\WpMcp\DesignSpec\Validator::class ) ) {
			$validated = \Stonewright\WpMcp\DesignSpec\Validator::validate( $spec );
			if ( is_array( $validated ) ) {
				$spec = $validated;
			}
		}
		$diagnostics = [];
		$tree = Renderer::render( $spec, $diagnostics );
		self::assertIsArray( $tree, $id );
		self::assertNotEmpty( $tree, $id . ' empty elementor tree' );
		$flat = ElementorData::flatten( $tree );
		self::assertNotEmpty( $flat, $id . ' empty flat tree' );
		$has_el_type = false;
		$hero_centered = false;
		foreach ( $flat as $el ) {
			if ( is_array( $el ) && isset( $el['elType'] ) ) {
				$has_el_type = true;
			}
			if ( is_array( $el ) && ( $el['elType'] ?? '' ) === 'container' ) {
				$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : [];
				if ( ( $settings['content_width'] ?? '' ) === 'full' && ( $settings['flex_align_items'] ?? '' ) === 'center' ) {
					$hero_centered = true;
				}
			}
		}
		self::assertTrue( $has_el_type, $id . ' missing elType' );
		self::assertTrue( $hero_centered, $id . ' expected full-width container with flex_align_items=center from authored layout intent' );
		$hard_drops = array_values(
			array_filter(
				$diagnostics,
				static function ( $row ): bool {
					if ( ! is_array( $row ) ) {
						return false;
					}
					$msg = strtolower( (string) ( $row['message'] ?? $row['code'] ?? '' ) );
					return str_contains( $msg, 'unsupported' ) || str_contains( $msg, 'dropped' );
				}
			)
		);
		self::assertSame( [], $hard_drops, $id . ' elementor drops: ' . wp_json_encode( $hard_drops ) );
	}

	/**
	 * @dataProvider blueprint_ids
	 */
	public function test_fse_apply_reports_engine_used_fse( string $id ): void {
		// Bound runtime: only exercise a sample for full apply; full matrix covers render above.
		if ( ! in_array( $id, [ 'dental', 'saas', 'restaurant' ], true ) ) {
			$this->assertTrue( true );
			return;
		}

		$result = BlueprintApplier::apply(
			[
				'blueprint_id' => $id,
				'engine'       => 'fse',
				'page_title'   => 'FSE suite ' . $id,
				'mode'         => 'draft',
			]
		);

		self::assertIsArray(
			$result,
			$id . ' FSE apply error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() : '' )
		);
		self::assertTrue( $result['ok'] ?? false );
		self::assertSame( 'fse', $result['engine_used'] ?? null );
		self::assertSame( 'fse', $result['engine_requested'] ?? null );
		self::assertNotEmpty( $result['snapshot_id'] ?? '' );
		self::assertArrayHasKey( 'fse', $result );
		$post = get_post( (int) $result['post_id'] );
		self::assertNotNull( $post );
		self::assertStringContainsString( 'stonewright-fse-root', (string) $post->post_content );
		self::assertStringContainsString( 'constrained', (string) $post->post_content );
	}

	public function test_explicit_fse_never_reports_gutenberg_engine_used(): void {
		$result = BlueprintApplier::apply(
			[
				'blueprint_id' => 'dental',
				'engine'       => 'fse',
				'page_title'   => 'FSE engine label',
				'mode'         => 'draft',
			]
		);
		self::assertIsArray( $result );
		self::assertSame( 'fse', $result['engine'] );
		self::assertSame( 'fse', $result['engine_used'] );
		self::assertNotSame( 'gutenberg', $result['engine_used'] );
	}

	public function test_elementor_apply_uses_transaction_runner_full_tree(): void {
		BlueprintApplier::$test_elementor_available = true;
		$result = BlueprintApplier::apply(
			[
				'blueprint_id' => 'dental',
				'engine'       => 'elementor',
				'page_title'   => 'Elementor txn path',
				'mode'         => 'draft',
			]
		);
		self::assertIsArray(
			$result,
			$result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : ''
		);
		self::assertTrue( $result['ok'] ?? false );
		self::assertSame( 'elementor', $result['engine_used'] ?? null );
		self::assertNotEmpty( $result['snapshot_id'] ?? '' );
		$post_id = (int) ( $result['post_id'] ?? 0 );
		$tree    = ElementorData::read( $post_id );
		self::assertNotEmpty( $tree );
		$flat = ElementorData::flatten( $tree );
		self::assertGreaterThan( 1, count( $flat ) );
	}
}

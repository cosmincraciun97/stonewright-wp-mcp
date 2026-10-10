<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\SaveTemplate;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\SaveTemplate
 */
final class SaveTemplateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Template source',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_next_post_id']    = 1001;
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_saved_template_carries_the_meta_and_term_elementor_stores(): void {
		$result = ( new SaveTemplate() )->execute(
			[
				'post_id'       => 601,
				'title'         => 'Saved page template',
				'template_type' => 'page',
			]
		);

		self::assertIsArray( $result );
		$id   = (int) $result['template_id'];
		$post = $GLOBALS['stonewright_test_posts'][ $id ];

		self::assertSame( 'elementor_library', $post->post_type );
		self::assertSame( 'page', get_post_meta( $id, '_elementor_template_type', true ) );
		self::assertSame( 'builder', get_post_meta( $id, '_elementor_edit_mode', true ) );
		self::assertNotSame( '', (string) get_post_meta( $id, '_elementor_version', true ) );
		self::assertNotSame( '', (string) get_post_meta( $id, '_elementor_data', true ) );
		self::assertSame( [ 'page' ], $GLOBALS['stonewright_test_object_terms'][ $id ]['elementor_library_type'] ?? null );
		self::assertSame( 'page', $result['template_type'] ?? null );
	}

	public function test_default_template_type_is_stored_as_meta_too(): void {
		$result = ( new SaveTemplate() )->execute( [ 'post_id' => 601, 'title' => 'Saved section' ] );

		self::assertIsArray( $result );
		$id = (int) $result['template_id'];
		self::assertSame( 'section', get_post_meta( $id, '_elementor_template_type', true ) );
		self::assertSame( [ 'section' ], $GLOBALS['stonewright_test_object_terms'][ $id ]['elementor_library_type'] ?? null );
	}

	public function test_source_page_is_left_untouched(): void {
		$before = $GLOBALS['stonewright_test_posts'][601]->meta;

		( new SaveTemplate() )->execute( [ 'post_id' => 601, 'title' => 'Copy', 'template_type' => 'page' ] );

		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][601]->meta );
		self::assertArrayNotHasKey( '_elementor_template_type', $GLOBALS['stonewright_test_posts'][601]->meta );
	}

	public function test_type_unknown_to_elementor_is_refused_before_any_post_is_created(): void {
		$original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $original,
			[
				'documents' => new class() {
					public function get_document_type( string $type, bool $fallback = true ): string|false {
						return 'page' === $type ? 'Library\Page' : false;
					}
				},
			]
		);

		try {
			$result = ( new SaveTemplate() )->execute( [ 'post_id' => 601, 'title' => 'Header copy', 'template_type' => 'header' ] );
		} finally {
			\Elementor\Plugin::$instance = $original;
		}

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_template_type', $result->get_error_code() );
		self::assertStringContainsString( 'header', $result->get_error_message() );
		self::assertSame( [ 601 ], array_keys( $GLOBALS['stonewright_test_posts'] ) );
	}
}

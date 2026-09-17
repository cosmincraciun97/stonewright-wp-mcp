<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\WireLoop;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\WireLoop
 */
final class WireLoopTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_types'] = [
			'elementor_library' => (object) [
				'cap' => (object) [
					'create_posts'  => 'edit_posts',
					'publish_posts' => 'publish_posts',
				],
			],
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_post'    => true,
			'edit_posts'   => true,
			'publish_posts'=> true,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_post_types']     = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [];
	}

	public function test_contract_rejects_missing_or_duplicate_template_source(): void {
		$ability = new WireLoop();
		$missing = $ability->execute( self::base_args() );
		$both    = $ability->execute(
			self::base_args() + [
				'template_id'   => 77,
				'template_spec' => self::minimal_spec(),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_loop_template_source_invalid', $missing->get_error_code() );
		self::assertInstanceOf( \WP_Error::class, $both );
		self::assertSame( 'stonewright_loop_template_source_invalid', $both->get_error_code() );
	}

	public function test_permission_requires_page_edit_and_template_creation_publication(): void {
		$ability = new WireLoop();

		self::assertTrue(
			$ability->permission_callback(
				[
					'post_id'       => 9049,
					'template_spec' => self::minimal_spec(),
				]
			)
		);
		$GLOBALS['stonewright_test_user_caps']['publish_posts'] = false;
		self::assertFalse(
			$ability->permission_callback(
				[
					'post_id'       => 9049,
					'template_spec' => self::minimal_spec(),
				]
			)
		);
	}

	public function test_template_spec_requires_confirmation_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new WireLoop() )->execute(
			array_merge(
				self::base_args(),
				[
					'template_spec' => self::minimal_spec(),
					'dry_run'       => false,
				]
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_public_contract_uses_compact_typed_intent(): void {
		$schema = ( new WireLoop() )->input_schema();

		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame(
			[ 'post_id', 'parent_id', 'display', 'post_type', 'idempotency_key', 'dry_run' ],
			$schema['required']
		);
		self::assertSame( [ 'carousel', 'grid' ], $schema['properties']['display']['enum'] );
		self::assertArrayHasKey( 'template_id', $schema['properties'] );
		self::assertArrayHasKey( 'template_spec', $schema['properties'] );
		self::assertArrayHasKey( 'responsive_scope', $schema['properties'] );
		self::assertArrayHasKey( 'instances', $schema['properties'] );
		self::assertArrayNotHasKey( 'settings', $schema['properties'] );
	}

	public function test_mobile_only_posts_per_page_is_rejected_by_the_public_contract(): void {
		require_once dirname( __DIR__ ) . '/Elementor/Loop/SyntheticLoopWidgets.php';
		$original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new \Stonewright\WpMcp\Tests\Unit\Elementor\Loop\SyntheticLoopWidgetManager(),
		];
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_options']['active_plugins'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts'] = [
			9049 => (object) [
				'ID'           => 9049,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode(
						[
							[
								'id'       => 'parent-a',
								'elType'   => 'container',
								'settings' => [ 'container_type' => 'flex' ],
								'elements' => [],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
				],
			],
			77   => (object) [
				'ID'           => 77,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Card',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_template_type' => 'loop-item',
					'_elementor_data'          => wp_json_encode( [] ),
					'_elementor_edit_mode'     => 'builder',
				],
			],
		];
		$GLOBALS['stonewright_test_post_types']['project'] = (object) [ 'name' => 'project', 'public' => true ];
		$GLOBALS['stonewright_test_search_posts'] = [ (object) [ 'ID' => 301 ] ];

		try {
			$result = ( new WireLoop() )->execute(
				array_merge(
					self::base_args(),
					[
						'template_id'      => 77,
						'query'            => [ 'posts_per_page' => 3 ],
						'responsive_scope' => [ 'mobile' ],
						'dry_run'          => true,
					]
				)
			);
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_loop_non_responsive_control', $result->get_error_code() );
		} finally {
			\Elementor\Plugin::$instance = $original;
			WidgetSchemaRepository::reset_request_cache();
			$GLOBALS['stonewright_test_posts'] = [];
			$GLOBALS['stonewright_test_search_posts'] = [];
			$GLOBALS['stonewright_test_transients'] = [];
		}
	}

	/** @return array<string, mixed> */
	private static function base_args(): array {
		return [
			'post_id'         => 9049,
			'parent_id'       => 'parent-a',
			'display'         => 'grid',
			'post_type'       => 'project',
			'idempotency_key' => 'wire-loop-test-9049',
			'dry_run'         => true,
		];
	}

	/** @return array<string, mixed> */
	private static function minimal_spec(): array {
		return [
			'page'     => [ 'title' => 'Loop card' ],
			'sections' => [
				[
					'id'     => 'card',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Project' ],
					],
				],
			],
		];
	}
}

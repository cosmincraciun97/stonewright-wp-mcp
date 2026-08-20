<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\SparseSettingsNormalizer;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\SparseSettingsNormalizer
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 */
final class SparseSettingsNormalizerTest extends TestCase {

	private object $original_elementor;

	/** @return array<string, array<string, mixed>> */
	private static function controls(): array {
		return [
			'title'    => [ 'type' => 'text' ],
			'flex_gap' => [ 'type' => 'slider', 'responsive' => true ],
			'padding'  => [ 'type' => 'dimensions', 'responsive' => true ],
			'subtitle' => [ 'type' => 'text' ],
		];
	}

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'sparse-card' => new SparseCardWidget() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
		$GLOBALS['stonewright_test_posts'] = [
			821 => (object) [
				'ID'           => 821,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Sparse write target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode(
						[
							[
								'id'       => 'root',
								'elType'   => 'container',
								'settings' => [
									'container_type' => 'flex',
									'flex_gap'       => [
										'unit'  => 'px',
										'size'  => '',
										'sizes' => [],
									],
								],
								'elements' => [
									[
										'id'         => 'card',
										'elType'     => 'widget',
										'widgetType' => 'sparse-card',
										'settings'   => [
											'title'    => 'Live title',
											'flex_gap' => [
												'unit'  => '%',
												'size'  => '',
												'sizes' => [],
											],
										],
										'elements'   => [],
									],
								],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_keeps_agent_values_and_omits_empty_responsive_defaults(): void {
		$supplied = [
			'title'    => 'Hero',
			'flex_gap' => [
				'unit'  => 'px',
				'size'  => '',
				'sizes' => [],
			],
			'padding'  => [
				'top'      => '',
				'right'    => '',
				'bottom'   => '',
				'left'     => '',
				'unit'     => 'px',
				'isLinked' => true,
			],
		];

		$normalized = SparseSettingsNormalizer::normalize(
			$supplied,
			self::controls(),
			$supplied
		);

		self::assertSame( [ 'title' => 'Hero' ], $normalized );
	}

	public function test_preserves_dynamic_tags_and_deliberately_empty_strings(): void {
		$supplied = [
			'title'       => '',
			'__dynamic__' => [
				'title' => '[elementor-tag id="x" name="post-title"]',
			],
		];

		$normalized = SparseSettingsNormalizer::normalize(
			$supplied,
			self::controls(),
			$supplied
		);

		self::assertSame( '', $normalized['title'] );
		self::assertSame( $supplied['__dynamic__'], $normalized['__dynamic__'] );
	}

	public function test_omits_absent_defaults_and_keeps_unknown_keys(): void {
		$validated = [
			'title'    => 'Hero',
			'subtitle' => 'Default subtitle',
			'invented' => 'keep-me',
		];
		$supplied  = [
			'title'    => 'Hero',
			'invented' => 'keep-me',
		];

		$normalized = SparseSettingsNormalizer::normalize(
			$validated,
			self::controls(),
			$supplied
		);

		self::assertSame( 'Hero', $normalized['title'] );
		self::assertArrayNotHasKey( 'subtitle', $normalized );
		self::assertSame( 'keep-me', $normalized['invented'] );
	}

	public function test_keeps_required_empty_objects(): void {
		$supplied = [
			'flex_gap' => [
				'unit'  => 'px',
				'size'  => '',
				'sizes' => [],
			],
		];

		$normalized = SparseSettingsNormalizer::normalize(
			$supplied,
			self::controls(),
			$supplied,
			[ 'flex_gap' ]
		);

		self::assertSame( $supplied['flex_gap'], $normalized['flex_gap'] );
	}

	public function test_write_helper_does_not_strip_live_document_keys(): void {
		$existing = [
			'title'    => 'Live title',
			'flex_gap' => [
				'unit'  => '%',
				'size'  => '',
				'sizes' => [],
			],
		];
		$incoming = [
			'title' => 'Patched',
		];

		$next = SparseSettingsNormalizer::for_write(
			array_merge( $existing, $incoming ),
			self::controls(),
			$incoming,
			$existing
		);

		self::assertSame( 'Patched', $next['title'] );
		self::assertSame( $existing['flex_gap'], $next['flex_gap'] );
	}

	public function test_unknown_keys_still_error_before_sparse_normalize(): void {
		$result = SettingsValidator::validate( 'sparse-card', [ 'made_up' => 'x' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'unknown_setting', $result->get_error_data()['violations'][0]['code'] );
	}

	public function test_add_widget_persists_sparse_new_settings_only(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'     => 821,
				'parent_id'   => 'root',
				'widget_type' => 'sparse-card',
				'settings'    => [
					'title'    => 'New card',
					'flex_gap' => [
						'unit'  => 'px',
						'size'  => '',
						'sizes' => [],
					],
				],
			]
		);

		self::assertIsArray( $result, (string) ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][821]->meta['_elementor_data'] ), true );
		$widget = $tree[0]['elements'][1];
		self::assertSame( 'sparse-card', $widget['widgetType'] );
		self::assertSame( 'New card', $widget['settings']['title'] );
		self::assertArrayNotHasKey( 'flex_gap', $widget['settings'] );
	}

	public function test_batch_mutate_add_widget_strips_empty_defaults_from_new_payload(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 821,
				'dry_run'    => true,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'sparse-card',
						'settings'    => [
							'title'    => 'Batch card',
							'flex_gap' => [
								'unit'  => 'px',
								'size'  => '',
								'sizes' => [],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result, (string) ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$widget = $result['preview'][0]['elements'][1] ?? null;
		self::assertIsArray( $widget );
		self::assertSame( 'Batch card', $widget['settings']['title'] );
		self::assertArrayNotHasKey( 'flex_gap', $widget['settings'] );
	}

	public function test_update_element_does_not_rewrite_live_empty_defaults(): void {
		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => 821,
				'element_id' => 'card',
				'settings'   => [
					'title' => 'Patched live',
				],
			]
		);

		self::assertIsArray( $result, (string) ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][821]->meta['_elementor_data'] ), true );
		$card = $tree[0]['elements'][0];
		self::assertSame( 'Patched live', $card['settings']['title'] );
		self::assertSame(
			[
				'unit'  => '%',
				'size'  => '',
				'sizes' => [],
			],
			$card['settings']['flex_gap']
		);
	}
}

final class SparseCardWidget {
	public function get_title(): string {
		return 'Sparse Card';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'basic' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title'    => [
				'type'    => 'text',
				'label'   => 'Title',
				'tab'     => 'content',
				'section' => 'content',
			],
			'flex_gap' => [
				'type'       => 'slider',
				'label'      => 'Gap',
				'tab'        => 'content',
				'section'    => 'content',
				'responsive' => true,
			],
			'padding'  => [
				'type'       => 'dimensions',
				'label'      => 'Padding',
				'tab'        => 'advanced',
				'section'    => 'layout',
				'responsive' => true,
			],
			'subtitle' => [
				'type'    => 'text',
				'label'   => 'Subtitle',
				'tab'     => 'content',
				'section' => 'content',
			],
		];
	}
}

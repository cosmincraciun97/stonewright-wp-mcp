<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Renderer\Container;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\Elementor\ElementorCustomCssGate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 * @covers \Stonewright\WpMcp\Elementor\Schema\PatchValidator
 */
final class ElementorCustomCssGateTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'               => 'development',
			'stonewright_allow_html_widgets' => true,
			'elementor_active_kit'           => 44,
		];
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'              => true,
			'manage_options'    => true,
			'edit_post'         => true,
			'edit_posts'        => true,
			'edit_theme_options'=> true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			10 => (object) [
				'ID'           => 10,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Settings Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'settings-page',
				'meta'         => [
					'_elementor_page_settings' => [
						'custom_css' => '.elementor-10{overflow-x:hidden;}',
					],
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
			44 => (object) [
				'ID'           => 44,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Active Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'active-kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'container_width' => [ 'size' => 1200, 'unit' => 'px' ],
					],
				],
			],
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Update target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $this->original_elementor,
			[
				'widgets_manager' => new class() {
					public function get_widget_types( ?string $name = null ): array|object|null {
						$widgets = [ 'heading' => new GateHeadingWidgetForTest() ];
						return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
					}
				},
			]
		);
	}

	protected function tearDown(): void {
		ElementorCustomCssGate::reset();
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_custom_css_without_grant_returns_approval_required_payload(): void {
		$result = ElementorCustomCssGate::assert_incoming(
			[ 'custom_css' => '.hero{color:red;}' ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'custom_css', $data['offending_key'] );
		self::assertSame( 'stonewright/theme-custom-css', $data['gated_tool'] );
		self::assertSame( 'stonewright-theme-custom-css', $data['gated_mcp_tool'] );
		self::assertTrue( (bool) $data['approval_required'] );
		self::assertTrue( (bool) $data['agent_must_stop'] );
		self::assertIsArray( $data['approval_flow'] );
		self::assertNotEmpty( $data['approval_flow'] );
	}

	public function test_valid_grant_allows_custom_css_and_is_single_use(): void {
		$css  = '.hero{color:red;}';
		$hash = hash( 'sha256', $css );
		$issued = CustomCodeGrant::issue(
			[
				'path'         => ElementorCustomCssGate::GRANT_PATH,
				'after_sha256' => $hash,
				'language'     => 'css',
			]
		);
		self::assertIsArray( $issued );

		$ok = ElementorCustomCssGate::assert_incoming(
			[ 'custom_css' => $css ],
			[ 'custom_code_grant' => (string) $issued['token'] ]
		);
		self::assertTrue( $ok );

		ElementorCustomCssGate::reset();
		$reuse = ElementorCustomCssGate::assert_incoming(
			[ 'custom_css' => $css ],
			[ 'custom_code_grant' => (string) $issued['token'] ]
		);
		self::assertInstanceOf( \WP_Error::class, $reuse );
		self::assertSame( 'stonewright_custom_code_grant_reused', $reuse->get_error_code() );
	}

	public function test_unapproved_css_classes_list_allowlist(): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'sw-header' ];

		$result = ElementorCustomCssGate::assert_incoming(
			[ '_css_classes' => 'not-approved' ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_css_classes_not_approved', $result->get_error_code() );
		self::assertSame( [ 'sw-header' ], $result->get_error_data()['approved_css_classes'] );
		self::assertContains( 'not-approved', $result->get_error_data()['rejected_classes'] );
	}

	public function test_allowlisted_css_classes_pass(): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'sw-header', 'sw-header-inner' ];

		$result = ElementorCustomCssGate::assert_incoming(
			[ '_css_classes' => 'sw-header sw-header-inner' ]
		);

		self::assertTrue( $result );
	}

	public function test_renderer_emitted_css_classes_remain_approved(): void {
		$node = [
			'layout'      => 'flex',
			'css_classes' => 'sw-header',
		];
		$element = Container::render( $node, \Stonewright\WpMcp\DesignTokens\Resolver::from_spec( [ 'tokens' => [] ] ), 's0' );

		self::assertSame( 'sw-header', $element['settings']['_css_classes'] );

		$result = ElementorCustomCssGate::assert_incoming(
			[ '_css_classes' => $element['settings']['_css_classes'] ]
		);
		self::assertTrue( $result );
	}

	public function test_html_style_tag_requires_grant_and_is_not_stripped(): void {
		$html = '<div>ok</div><style>.x{color:red}</style>';
		$result = ElementorCustomCssGate::assert_incoming(
			[ 'html' => $html ],
			[],
			'html'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame( 'html', $result->get_error_data()['offending_key'] );
		self::assertSame( $html, $result->get_error_data()['candidate'] );
	}

	public function test_html_without_style_is_allowed(): void {
		$result = ElementorCustomCssGate::assert_incoming(
			[ 'html' => '<div class="note">Hello</div>' ],
			[],
			'html'
		);
		self::assertTrue( $result );
	}

	public function test_update_page_settings_rejects_custom_css_without_grant(): void {
		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [
					'custom_css' => '.elementor-10{overflow-x:hidden;}',
				],
				'mode'     => 'merge',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame(
			'.elementor-10{overflow-x:hidden;}',
			$GLOBALS['stonewright_test_posts'][10]->meta['_elementor_page_settings']['custom_css']
		);
	}

	public function test_kit_batch_mutate_rejects_custom_css_in_settings_and_layout(): void {
		$settings = ( new KitBatchMutate() )->execute(
			[
				'dry_run'    => true,
				'operations' => [
					[
						'group'    => 'settings',
						'settings' => [ 'custom_css' => 'body{display:none;}' ],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $settings );
		self::assertSame( 'stonewright_custom_code_approval_required', $settings->get_error_code() );

		$layout = ( new KitBatchMutate() )->execute(
			[
				'dry_run'    => true,
				'operations' => [
					[
						'group'   => 'layout',
						'setting' => 'custom_css',
						'value'   => 'body{display:none;}',
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $layout );
		self::assertSame( 'stonewright_custom_code_approval_required', $layout->get_error_code() );
	}

	public function test_update_element_rejects_custom_css_even_with_preserve_unknown(): void {
		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => 601,
				'element_id' => 'root',
				'mode'       => 'merge',
				'settings'   => [ 'custom_css' => '.x{color:red;}' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
	}

	public function test_patch_validator_rejects_live_custom_css_control(): void {
		$original = \Elementor\Plugin::$instance;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'pro-css' => new GateProCssWidgetForTest() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = PatchValidator::widget(
			'pro-css',
			[ 'title' => 'Before' ],
			[ 'custom_css' => '.x{color:red;}' ]
		);

		\Elementor\Plugin::$instance = $original;
		WidgetSchemaRepository::reset_request_cache();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
	}

	public function test_patch_validator_rejects_smuggled_custom_css_via_preserve_unknown(): void {
		$result = PatchValidator::container(
			[ 'container_type' => 'flex' ],
			[ '_custom_css' => '.smuggle{display:none;}' ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		self::assertSame( '_custom_css', $result->get_error_data()['offending_key'] );
	}

	public function test_batch_mutate_html_widget_style_is_rejected_when_html_widgets_allowed(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 10,
				'dry_run'    => true,
				'operations' => [
					[
						'action'            => 'add_widget',
						'parent_id'         => 'root',
						'widget_type'       => 'html',
						'allow_html_widget' => true,
						'settings'          => [
							'html' => '<style>body{display:none}</style>',
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
	}

	public function test_existing_custom_css_is_not_gated_when_incoming_patch_omits_it(): void {
		$result = ( new UpdatePageSettings() )->execute(
			[
				'post_id'  => 10,
				'settings' => [ 'hide_title' => 'yes' ],
				'mode'     => 'merge',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 10, $result['post_id'] );
	}
}

final class GateHeadingWidgetForTest {
	public function get_title(): string {
		return 'Heading';
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title' => [ 'type' => 'text', 'label' => 'Title' ],
		];
	}
}

final class GateProCssWidgetForTest {
	public function get_title(): string {
		return 'Pro CSS';
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title'      => [ 'type' => 'text', 'label' => 'Title' ],
			'custom_css' => [ 'type' => 'code', 'label' => 'Custom CSS' ],
		];
	}
}

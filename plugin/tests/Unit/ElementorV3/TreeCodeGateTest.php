<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Abilities\ElementorV3\TransactionRun;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\ElementorTreeCodeGate;
use Stonewright\WpMcp\Elementor\HtmlWidgetPolicy;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Whole-tree writers (elementor-build-tree, elementor-v3-transaction-run with replace_tree) apply the HTML
 * widget policy and the custom code gate to every element, as add-widget and batch-mutate do per operation.
 *
 * @covers \Stonewright\WpMcp\Elementor\ElementorTreeCodeGate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildTree
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\TransactionRun
 */
final class TreeCodeGateTest extends TestCase {

	private const STYLE_HTML  = '<style>.hero{color:red}</style><script>window.x=1</script><p>Hi</p>';
	private const EXISTING    = '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]';

	protected function setUp(): void {
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_posts'] = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Tree target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => self::EXISTING,
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'read' => true, 'manage_options' => true, 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function html_tree( string $html = self::STYLE_HTML ): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[
						'id'         => 'raw1',
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => [ 'html' => $html ],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function css_tree(): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex', 'custom_css' => 'selector{color:red}' ],
				'elements' => [
					[
						'id'         => 'h1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Hello' ],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function plain_tree(): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[
						'id'         => 'h1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Hello' ],
						'elements'   => [],
					],
				],
			],
		];
	}

	private function assert_nothing_written(): void {
		$writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $call ): bool => '_elementor_data' === ( $call['meta_key'] ?? '' )
		);
		self::assertSame( [], array_values( $writes ), 'the refused tree must not reach _elementor_data' );
		self::assertSame( self::EXISTING, $GLOBALS['stonewright_test_posts'][601]->meta['_elementor_data'] );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $extra
	 * @return array<string, mixed>|\WP_Error
	 */
	private function build_tree( array $tree, array $extra = [] ): array|\WP_Error {
		return ( new BuildTree() )->execute( array_merge( [ 'post_id' => 601, 'tree' => $tree ], $extra ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>             $extra
	 * @return array<string, mixed>|\WP_Error
	 */
	private function replace_tree( array $tree, array $extra = [] ): array|\WP_Error {
		return ( new TransactionRun() )->execute(
			array_merge(
				[
					'post_id'    => 601,
					'operations' => [ [ 'action' => 'replace_tree', 'tree' => $tree ] ],
				],
				$extra
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function writers(): array {
		return [
			'elementor-build-tree'                          => [ 'build_tree' ],
			'elementor-v3-transaction-run (replace_tree)'   => [ 'replace_tree' ],
		];
	}

	/**
	 * @dataProvider writers
	 */
	public function test_html_widget_is_refused_while_the_site_option_is_off( string $writer ): void {
		$result = $this->{$writer}( $this->html_tree() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_per_call_flag_cannot_override_the_site_option( string $writer ): void {
		$result = $this->{$writer}( $this->html_tree(), [ 'allow_html_widget' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_html_widget_needs_the_explicit_flag_when_the_site_allows_it( string $writer ): void {
		$GLOBALS['stonewright_test_options'][ HtmlWidgetPolicy::OPTION ] = true;

		$result = $this->{$writer}( $this->html_tree( '<p>Plain</p>' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'html_widget_requires_explicit_approval', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_style_tag_in_an_html_widget_needs_a_custom_code_grant( string $writer ): void {
		$GLOBALS['stonewright_test_options'][ HtmlWidgetPolicy::OPTION ] = true;

		$result = $this->{$writer}( $this->html_tree(), [ 'allow_html_widget' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_custom_css_in_a_container_needs_a_custom_code_grant( string $writer ): void {
		$result = $this->{$writer}( $this->css_tree() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_unapproved_css_classes_are_refused( string $writer ): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'sw-header' ];
		$tree                                                                    = $this->plain_tree();
		$tree[0]['settings']['_css_classes']                                     = 'not-approved';

		$result = $this->{$writer}( $tree );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_css_classes_not_approved', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	public function test_build_tree_dry_run_applies_the_same_gate(): void {
		$result = $this->build_tree( $this->html_tree(), [ 'dry_run' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_transaction_dry_run_applies_the_same_gate(): void {
		$result = $this->replace_tree( $this->html_tree(), [ 'dry_run' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_dry_run_with_a_grant_does_not_consume_it(): void {
		$GLOBALS['stonewright_test_options'][ HtmlWidgetPolicy::OPTION ] = true;
		$issued = CustomCodeGrant::issue(
			[
				'path'         => ElementorCustomCssGate::GRANT_HTML_PATH,
				'after_sha256' => hash( 'sha256', self::STYLE_HTML ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );
		$args = [ 'allow_html_widget' => true, 'custom_code_grant' => (string) $issued['token'] ];

		$dry = $this->build_tree( $this->html_tree(), $args + [ 'dry_run' => true ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );

		ElementorCustomCssGate::reset();
		$write = $this->build_tree( $this->html_tree(), $args );
		self::assertIsArray( $write, $write instanceof \WP_Error ? $write->get_error_message() : '' );
		self::assertTrue( $write['ok'] );
	}

	public function test_a_grant_for_the_exact_html_lets_the_tree_through_once(): void {
		$GLOBALS['stonewright_test_options'][ HtmlWidgetPolicy::OPTION ] = true;
		$issued = CustomCodeGrant::issue(
			[
				'path'         => ElementorCustomCssGate::GRANT_HTML_PATH,
				'after_sha256' => hash( 'sha256', self::STYLE_HTML ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );
		$args = [ 'allow_html_widget' => true, 'custom_code_grant' => (string) $issued['token'] ];

		$first = $this->replace_tree( $this->html_tree(), $args );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );
		self::assertTrue( $first['ok'] );

		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_posts'][601]->meta['_elementor_data'] = self::EXISTING;
		$replay = $this->replace_tree( $this->html_tree(), $args );
		self::assertInstanceOf( \WP_Error::class, $replay );
		self::assertSame( 'stonewright_custom_code_grant_reused', $replay->get_error_code() );
	}

	public function test_a_grant_for_other_html_is_refused(): void {
		$GLOBALS['stonewright_test_options'][ HtmlWidgetPolicy::OPTION ] = true;
		$issued = CustomCodeGrant::issue(
			[
				'path'         => ElementorCustomCssGate::GRANT_HTML_PATH,
				'after_sha256' => hash( 'sha256', '<style>.other{}</style>' ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );

		$result = $this->build_tree( $this->html_tree(), [ 'allow_html_widget' => true, 'custom_code_grant' => (string) $issued['token'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
		$this->assert_nothing_written();
	}

	/**
	 * @dataProvider writers
	 */
	public function test_a_tree_without_code_is_unaffected( string $writer ): void {
		$result = $this->{$writer}( $this->plain_tree() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'hero', ElementorData::read( 601 )[0]['id'] );
	}

	public function test_gate_finds_an_html_widget_at_any_depth_and_an_alias_widget_type(): void {
		$tree = [
			[
				'id'       => 'a',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'       => 'b',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'c',
								'elType'     => 'widget',
								'widgetType' => 'raw-html',
								'settings'   => [ 'html' => '<p>x</p>' ],
								'elements'   => [],
							],
						],
					],
				],
			],
		];

		$result = ElementorTreeCodeGate::assert_tree( $tree, [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_gate_passes_a_tree_without_code(): void {
		self::assertTrue( ElementorTreeCodeGate::assert_tree( $this->plain_tree(), [] ) );
		self::assertTrue( ElementorTreeCodeGate::assert_tree( [], [] ) );
	}
}

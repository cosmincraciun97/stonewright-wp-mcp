<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * An unsafe colour or typography value is refused on write, and a page that
 * already stores one is never handed to the CSS generator.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\CssValueGuard
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 */
final class ElementorCssSafetyTest extends TestCase {

	private const BREAKOUT = 'red;}body{display:none}';

	private string $css_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			701 => (object) [
				'ID'           => 701,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'CSS safety target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
					'_elementor_css'       => [ 'time' => 1 ],
				],
			],
		];
		$GLOBALS['stonewright_test_css_regenerate_events'] = [];
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']             = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_asset_responses']       = [];
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css();
	}

	protected function tearDown(): void {
		Post::$factory = null;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_asset_responses'] = [];
		unset( $GLOBALS['stonewright_test_css_regenerate_events'] );
		$this->remove_css();
	}

	private function stored_tree(): array {
		return json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_data'] ), true );
	}

	/** Generator that prints stored settings into CSS the way Elementor does: values are not escaped. */
	private function install_css_generator( int &$calls ): void {
		Post::$factory = function ( int $post_id ) use ( &$calls ): object {
			return new class( $post_id, $this->css_dir, $calls ) {
				public function __construct( private int $post_id, private string $css_dir, private int &$calls ) {
				}

				public function update_file(): void {
					++$this->calls;
					$css  = '';
					$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][ $this->post_id ]->meta['_elementor_data'] ), true );
					foreach ( (array) $tree as $element ) {
						foreach ( (array) ( $element['settings'] ?? [] ) as $key => $value ) {
							if ( is_string( $value ) && str_ends_with( (string) $key, '_color' ) ) {
								$css .= '.elementor-element-' . $element['id'] . '{' . str_replace( '_', '-', (string) $key ) . ':' . $value . '}';
							}
						}
					}
					file_put_contents( $this->get_path(), $css . '.elementor-' . $this->post_id . '{margin:0}' );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}

	public function test_refused_colour_leaves_stored_data_and_generated_css_clean(): void {
		$update = ( new UpdateElement() )->execute(
			[ 'post_id' => 701, 'element_id' => 'root', 'settings' => [ 'background_color' => self::BREAKOUT ] ]
		);

		self::assertInstanceOf( \WP_Error::class, $update );
		self::assertSame( 'stonewright_elementor_settings_invalid', $update->get_error_code() );
		self::assertStringContainsString( 'background_color', $update->get_error_message() );
		self::assertArrayNotHasKey( 'background_color', $this->stored_tree()[0]['settings'] );
		self::assertStringNotContainsString( 'display:none', (string) $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_data'] );

		$calls = 0;
		$this->install_css_generator( $calls );
		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 701 ] );

		self::assertIsArray( $result );
		self::assertSame( 1, $calls );
		$css = (string) file_get_contents( $this->css_dir . '/post-701.css' );
		self::assertStringNotContainsString( 'body{display:none}', $css );
		self::assertStringNotContainsString( ';}', $css );
	}

	public function test_the_final_document_write_gate_refuses_an_unsafe_colour(): void {
		$tree = $this->stored_tree();
		$tree[0]['settings']['background_color'] = self::BREAKOUT;

		self::assertFalse( ElementorData::write( 701, $tree ) );
		self::assertSame( 'stonewright_elementor_settings_invalid', ElementorData::last_write_error()?->get_error_code() );
		self::assertArrayNotHasKey( 'background_color', $this->stored_tree()[0]['settings'] );

		$tree[0]['settings']['background_color'] = '#abcdef';
		self::assertTrue( ElementorData::write( 701, $tree ) );
		self::assertSame( '#abcdef', $this->stored_tree()[0]['settings']['background_color'] );
	}

	public function test_update_element_refuses_each_injection_string_and_accepts_a_real_colour(): void {
		foreach ( [ self::BREAKOUT, 'not-a-color', 'javascript:alert(1)', 'url(//example.com/x.png)' ] as $bad ) {
			$result = ( new UpdateElement() )->execute( [ 'post_id' => 701, 'element_id' => 'root', 'settings' => [ 'border_color' => $bad ] ] );
			self::assertInstanceOf( \WP_Error::class, $result, $bad );
			self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		}
		self::assertArrayNotHasKey( 'border_color', $this->stored_tree()[0]['settings'] );

		$ok = ( new UpdateElement() )->execute( [ 'post_id' => 701, 'element_id' => 'root', 'settings' => [ 'border_color' => '#112233' ] ] );
		self::assertIsArray( $ok );
		self::assertSame( '#112233', $this->stored_tree()[0]['settings']['border_color'] );
	}

	public function test_regenerate_refuses_a_page_that_already_stores_a_breakout_value(): void {
		$GLOBALS['stonewright_test_posts'][701]->meta['_elementor_data'] = (string) wp_json_encode(
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex' ],
					'elements' => [
						[
							'id'         => 'head1234',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => [ 'title' => 'Hi', 'title_color' => self::BREAKOUT ],
							'elements'   => [],
						],
					],
				],
			]
		);
		$calls = 0;
		$this->install_css_generator( $calls );

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 701 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_unsafe_value', $result->get_error_code() );
		self::assertStringContainsString( 'title_color', $result->get_error_message() );
		self::assertSame( 0, $calls );
		self::assertFileDoesNotExist( $this->css_dir . '/post-701.css' );
		self::assertNotContains( 'backup', $GLOBALS['stonewright_test_css_regenerate_events'] );
		self::assertNotContains( 'update_file', $GLOBALS['stonewright_test_css_regenerate_events'] );
	}

	public function test_regenerate_refuses_breakout_in_page_or_kit_settings(): void {
		$GLOBALS['stonewright_test_posts'][701]->meta['_elementor_page_settings'] = [
			'custom_colors' => [ [ '_id' => 'brand', 'title' => 'Brand', 'color' => self::BREAKOUT ] ],
		];
		$calls = 0;
		$this->install_css_generator( $calls );

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 701 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_css_unsafe_value', $result->get_error_code() );
		self::assertSame( [ 'page_settings.custom_colors.0.color' ], $result->get_error_data()['paths'] );
		self::assertSame( 0, $calls );
	}

	public function test_regenerate_ignores_gated_custom_css_and_clean_values(): void {
		$GLOBALS['stonewright_test_posts'][701]->meta['_elementor_data'] = (string) wp_json_encode(
			[
				[
					'id'       => 'root',
					'elType'   => 'container',
					'settings' => [ 'container_type' => 'flex', 'custom_css' => 'selector{color:red;}', 'background_color' => '#fff', '__globals__' => [ 'border_color' => 'globals/colors?id=primary' ] ],
					'elements' => [],
				],
			]
		);
		$calls = 0;
		$this->install_css_generator( $calls );

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 701 ] );

		self::assertIsArray( $result );
		self::assertSame( 1, $calls );
	}

	/** @return array<string, array{0:string}> */
	public static function breakout_values(): array {
		return [
			'semicolon'  => [ 'red;color' ],
			'open brace' => [ 'red{' ],
			'close'      => [ '}' ],
			'less than'  => [ '<style>' ],
			'greater'    => [ 'a>b' ],
			'url'        => [ 'URL (//example.com/x.png)' ],
			'expression' => [ 'Expression(1)' ],
			'import'     => [ '@import "x"' ],
			'comment'    => [ 'red/*x*/' ],
			'backslash'  => [ 'red\\3b' ],
			'js'         => [ 'javascript:alert(1)' ],
		];
	}

	/**
	 * @dataProvider breakout_values
	 */
	public function test_guard_flags_every_breakout_marker( string $value ): void {
		self::assertTrue( CssValueGuard::contains_breakout( $value ), $value );
	}

	public function test_guard_does_not_flag_ordinary_values(): void {
		foreach ( [ '#fff', 'rgba(0,0,0,.5)', 'Open Sans', 'var(--e-global-color-primary)', 'globals/colors?id=primary', '' ] as $value ) {
			self::assertFalse( CssValueGuard::contains_breakout( $value ), $value );
		}
	}

	public function test_guard_scan_reports_the_path_of_each_unsafe_value_in_a_tree(): void {
		$tree = [
			[
				'id'       => 'a1',
				'elType'   => 'container',
				'settings' => [ 'background_color' => 'red;}', 'custom_css' => 'selector{x:y;}' ],
				'elements' => [
					[
						'id'         => 'b2',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [
							'typography_font_family' => 'Arial;}',
							'typography_font_size'   => [ 'size' => 1, 'unit' => 'px;}' ],
							'title'                  => 'Plain { text }',
							'title_color'            => '#000',
						],
						'elements'   => [],
					],
				],
			],
		];

		self::assertSame(
			[
				'root.0.settings.background_color',
				'root.0.elements.0.settings.typography_font_family',
				'root.0.elements.0.settings.typography_font_size.unit',
			],
			array_column( CssValueGuard::unsafe_values_in_tree( $tree ), 'path' )
		);
	}

	private function remove_css(): void {
		foreach ( [ 'post-701.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}

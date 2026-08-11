<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\IconValueNormalizer;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\IconValueNormalizer
 * @covers \Stonewright\WpMcp\Elementor\Renderer\Button
 */
final class IconValueNormalizerTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_normalizes_fa_arrow_circle_right(): void {
		$result = IconValueNormalizer::normalize( 'fas fa-arrow-circle-right' );
		self::assertIsArray( $result );
		self::assertSame( 'fas fa-arrow-circle-right', $result['value'] );
		self::assertSame( 'fa-solid', $result['library'] );
	}

	public function test_normalizes_fa_long_form(): void {
		$result = IconValueNormalizer::normalize( 'fa-solid fa-arrow-circle-right' );
		self::assertIsArray( $result );
		self::assertSame( 'fa-solid', $result['library'] );
		self::assertStringContainsString( 'fa-arrow-circle-right', $result['value'] );
	}

	public function test_normalizes_eicon(): void {
		$result = IconValueNormalizer::normalize( 'eicon-arrow-right' );
		self::assertIsArray( $result );
		self::assertSame( 'eicon-arrow-right', $result['value'] );
		self::assertSame( 'eicons', $result['library'] );
	}

	public function test_structured_eicon_object(): void {
		$result = IconValueNormalizer::normalize(
			[
				'value'   => 'arrow-right',
				'library' => 'eicon',
			]
		);
		self::assertIsArray( $result );
		self::assertSame( 'eicon-arrow-right', $result['value'] );
		self::assertSame( 'eicons', $result['library'] );
	}

	public function test_svg_media_requires_url_or_id(): void {
		$error = IconValueNormalizer::normalize( [ 'library' => 'svg', 'value' => [] ] );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( IconValueNormalizer::ERROR_CODE, $error->get_error_code() );
	}

	public function test_svg_media_accepted(): void {
		$result = IconValueNormalizer::normalize(
			[
				'library' => 'svg',
				'value'   => [ 'url' => 'https://example.test/icon.svg', 'id' => 12 ],
			]
		);
		self::assertIsArray( $result );
		self::assertSame( 'svg', $result['library'] );
		self::assertSame( 'https://example.test/icon.svg', $result['value']['url'] );
	}

	public function test_rejects_icon_class_injection_and_library_mismatch(): void {
		$injected_fa = IconValueNormalizer::normalize( 'fas fa-star" onclick="alert(1)' );
		self::assertInstanceOf( \WP_Error::class, $injected_fa );

		$injected_eicon = IconValueNormalizer::normalize(
			[ 'library' => 'eicons', 'value' => 'eicon-plus onmouseover=alert(1)' ]
		);
		self::assertInstanceOf( \WP_Error::class, $injected_eicon );

		$mismatch = IconValueNormalizer::normalize(
			[ 'library' => 'fa-solid', 'value' => 'fab fa-github' ]
		);
		self::assertInstanceOf( \WP_Error::class, $mismatch );
		self::assertSame( 'library_mismatch', $mismatch->get_error_data()['reason'] );
	}

	public function test_rejects_non_http_svg_url(): void {
		$result = IconValueNormalizer::normalize(
			[
				'library' => 'svg',
				'value'   => [ 'url' => 'javascript:alert(1)', 'id' => 12 ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'svg_url_invalid', $result->get_error_data()['reason'] );
	}

	public function test_icon_position_aliases(): void {
		self::assertSame( 'row', IconValueNormalizer::normalize_position( 'left' ) );
		self::assertSame( 'row-reverse', IconValueNormalizer::normalize_position( 'after' ) );
		self::assertNull( IconValueNormalizer::normalize_position( 'upside-down' ) );
	}

	public function test_button_rejects_invalid_icon_without_partial_write(): void {
		$diagnostics = [];
		$result      = Button::render(
			[
				'type' => 'button',
				'text' => 'Go',
				'url'  => 'https://example.test',
				'icon' => 'not-a-real-icon-format!!!',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertNotEmpty( $diagnostics );
		self::assertSame( IconValueNormalizer::ERROR_CODE, $diagnostics[0]['code'] );
	}

	public function test_button_applies_fa_and_eicon_with_position_spacing(): void {
		$diagnostics = [];
		$fa          = Button::render(
			[
				'type'          => 'button',
				'text'          => 'Next',
				'icon'          => 'fas fa-arrow-circle-right',
				'icon_position' => 'right',
				'icon_spacing'  => 10,
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertIsArray( $fa );
		self::assertSame( 'fas fa-arrow-circle-right', $fa['settings']['selected_icon']['value'] );
		self::assertSame( 'fa-solid', $fa['settings']['selected_icon']['library'] );
		self::assertArrayNotHasKey( 'icon', $fa['settings'] );
		self::assertSame( 'row-reverse', $fa['settings']['icon_align'] );
		self::assertSame( 10, $fa['settings']['icon_indent']['size'] );
		self::assertSame( [], $diagnostics );

		$eicon = Button::render(
			[
				'type' => 'button',
				'text' => 'Plus',
				'icon' => 'eicon-plus',
			],
			new Resolver( [] ),
			's0.b1',
			$diagnostics
		);
		self::assertIsArray( $eicon );
		self::assertSame( 'eicons', $eicon['settings']['selected_icon']['library'] );
		self::assertSame( 'eicon-plus', $eicon['settings']['selected_icon']['value'] );
	}

	public function test_button_refuses_icon_when_live_schema_has_no_icon_control(): void {
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $this->original_elementor,
			[
				'widgets_manager' => new class() {
					public function get_widget_types( ?string $name = null ): array|object|null {
						$button = new class() {
							public function get_title(): string {
								return 'Button';
							}

							/** @return array<string, array<string, mixed>> */
							public function get_controls(): array {
								return [ 'text' => [ 'type' => 'text', 'section' => 'content' ] ];
							}
						};
						return null === $name ? [ 'button' => $button ] : ( 'button' === $name ? $button : null );
					}
				},
			]
		);
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];

		$diagnostics = [];
		$result      = Button::render(
			[ 'type' => 'button', 'text' => 'No partial icon', 'icon' => 'fas fa-star' ],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertSame( 'icon_control_unavailable', $diagnostics[0]['data']['reason'] );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\ListWidgets;
use Stonewright\WpMcp\Elementor\Schema\PlainLlmSchemaConverter;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\PlainLlmSchemaConverter
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ListWidgets
 */
final class PlainLlmSchemaConverterTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']    = [
			'active_plugins' => [],
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [ 'edit_posts' => true ];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [
						'plain-heading' => new PlainLlmHeadingWidget(),
						'pro-gated'     => new PlainLlmProWidget(),
					];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	/**
	 * Synthetic control array: enveloped JSON-schema-ish defaults vs the plain
	 * values an LLM should see. No customer content.
	 *
	 * @return array{enveloped:array<string,mixed>,plain:array<string,mixed>}
	 */
	private static function fixture(): array {
		$enveloped = [
			'title'       => [
				'type'        => 'text',
				'label'       => 'Title',
				'description' => 'Visible heading copy',
				'default'     => [
					'$$type' => 'string',
					'value'  => 'Hello',
				],
			],
			'padding'     => [
				'type'    => 'dimensions',
				'default' => [
					'$$type' => 'dimensions',
					'value'  => [
						'top'    => [
							'$$type' => 'string',
							'value'  => '8',
						],
						'unit'   => 'px',
					],
				],
			],
			'size'        => [
				'description' => 'Scale',
				'anyOf'       => [
					[
						'type' => 'string',
						'enum' => [ 'sm', 'md' ],
					],
					[
						'type' => 'string',
						'enum' => [ 'sm', 'md' ],
					],
				],
			],
			'weight'      => [
				'oneOf' => [
					[ 'type' => 'number' ],
					[ 'type' => 'string' ],
					[ 'type' => 'number' ],
				],
			],
			'header_size' => [
				'type'    => 'select',
				'label'   => 'HTML Tag',
				'options' => [
					'h1' => 'H1',
					'h2' => 'H2',
				],
			],
			'motion_fx'   => [
				'type'         => 'switcher',
				'label'        => 'Motion',
				'pro_required' => true,
				'source'       => 'elementor-pro',
			],
			'nested'      => [
				'type'   => 'repeater',
				'fields' => [
					'pro_only_field' => [
						'type'         => 'text',
						'pro_required' => true,
					],
					'label'          => [
						'type' => 'text',
					],
				],
			],
		];

		$plain = [
			'title'       => [
				'type'        => 'text',
				'label'       => 'Title',
				'description' => 'Visible heading copy',
				'default'     => 'Hello',
			],
			'padding'     => [
				'type'    => 'dimensions',
				'default' => [
					'top'  => '8',
					'unit' => 'px',
				],
			],
			'size'        => [
				'description' => 'Scale',
				'type'        => 'string',
				'enum'        => [ 'sm', 'md' ],
			],
			'weight'      => [
				'oneOf' => [
					[ 'type' => 'number' ],
					[ 'type' => 'string' ],
				],
			],
			'header_size' => [
				'type'    => 'select',
				'label'   => 'HTML Tag',
				'options' => [
					'h1' => 'H1',
					'h2' => 'H2',
				],
				'enum'    => [ 'h1', 'h2' ],
			],
			'nested'      => [
				'type'   => 'repeater',
				'fields' => [
					'label' => [
						'type' => 'text',
					],
				],
			],
		];

		return [
			'enveloped' => $enveloped,
			'plain'     => $plain,
		];
	}

	public function test_unwraps_envelopes_dedups_unions_enriches_enums_and_drops_inactive_pro_controls(): void {
		$fixture = self::fixture();

		$plain = PlainLlmSchemaConverter::convert(
			$fixture['enveloped'],
			[ 'elementor_pro_active' => false ]
		);

		self::assertSame( $fixture['plain'], $plain );
		self::assertArrayNotHasKey( 'motion_fx', $plain );
		self::assertArrayNotHasKey( 'pro_only_field', $plain['nested']['fields'] );
	}

	public function test_keeps_pro_controls_when_elementor_pro_plugin_is_active(): void {
		$fixture = self::fixture();

		$plain = PlainLlmSchemaConverter::convert(
			$fixture['enveloped'],
			[ 'elementor_pro_active' => true ]
		);

		self::assertArrayHasKey( 'motion_fx', $plain );
		self::assertArrayHasKey( 'pro_only_field', $plain['nested']['fields'] );
		self::assertSame( 'Hello', $plain['title']['default'] );
	}

	public function test_summary_mode_returns_only_type_and_description_per_control(): void {
		$summary = PlainLlmSchemaConverter::convert(
			self::fixture()['enveloped'],
			[
				'elementor_pro_active' => false,
				'mode'                 => 'summary',
			]
		);

		self::assertSame(
			[
				'title'       => [
					'type'        => 'text',
					'description' => 'Visible heading copy',
				],
				'padding'     => [
					'type'        => 'dimensions',
					'description' => '',
				],
				'size'        => [
					'type'        => 'string',
					'description' => 'Scale',
				],
				'weight'      => [
					'type'        => '',
					'description' => '',
				],
				'header_size' => [
					'type'        => 'select',
					'description' => 'HTML Tag',
				],
				'nested'      => [
					'type'        => 'repeater',
					'description' => '',
				],
			],
			$summary
		);
	}

	public function test_get_widget_schema_emits_converted_plain_schema(): void {
		$full = ( new GetWidgetSchema() )->execute(
			[
				'name'         => 'plain-heading',
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $full );
		self::assertSame( 'full', $full['response_mode'] );
		$by_name = [];
		foreach ( (array) $full['controls'] as $control ) {
			$by_name[ (string) ( $control['name'] ?? $control['key'] ?? '' ) ] = $control;
		}
		self::assertSame( 'Hello', $by_name['title']['default'] );
		self::assertSame( [ 'h1', 'h2' ], $by_name['header_size']['enum'] );
		self::assertArrayNotHasKey( 'motion_fx', $by_name );
		$encoded = (string) wp_json_encode( $full );
		self::assertStringNotContainsString( '$$type', $encoded );
	}

	public function test_elementor_schema_full_mode_emits_converted_plain_controls(): void {
		$result = ( new ElementorSchema() )->execute(
			[
				'mode'        => 'full',
				'widget_type' => 'plain-heading',
				'per_page'    => 50,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'Hello', $result['controls']['title']['default'] );
		self::assertSame( [ 'h1', 'h2' ], $result['controls']['header_size']['enum'] );
		self::assertArrayNotHasKey( 'motion_fx', $result['controls'] );
		self::assertStringNotContainsString( '$$type', (string) wp_json_encode( $result ) );
	}

	public function test_widget_list_converter_drops_elementor_pro_entries_when_plugin_inactive(): void {
		$items = [
			[
				'name'          => 'plain-heading',
				'source_plugin' => 'elementor/elementor.php',
				'pro_required'  => false,
			],
			[
				'name'          => 'form',
				'source_plugin' => 'elementor-pro/elementor-pro.php',
				'pro_required'  => true,
			],
		];

		$filtered = PlainLlmSchemaConverter::convert_widget_list( $items, false );
		self::assertSame( [ 'plain-heading' ], array_column( $filtered, 'name' ) );

		$kept = PlainLlmSchemaConverter::convert_widget_list( $items, true );
		self::assertSame( [ 'plain-heading', 'form' ], array_column( $kept, 'name' ) );
	}

	public function test_list_widgets_emits_converted_plain_items(): void {
		$result = ( new ListWidgets() )->execute( [] );

		self::assertIsArray( $result );
		$names = array_map(
			static fn( array $widget ): string => (string) ( $widget['name'] ?? $widget['widget_type'] ?? '' ),
			(array) $result['widgets']
		);
		self::assertContains( 'plain-heading', $names );
		self::assertStringNotContainsString( '$$type', (string) wp_json_encode( $result ) );
	}
}

final class PlainLlmHeadingWidget {
	public function get_title(): string {
		return 'Plain Heading';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'basic' ];
	}

	/** @return list<string> */
	public function get_keywords(): array {
		return [ 'heading' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title'       => [
				'type'        => 'text',
				'label'       => 'Title',
				'description' => 'Visible heading copy',
				'tab'         => 'content',
				'section'     => 'content',
				'default'     => [
					'$$type' => 'string',
					'value'  => 'Hello',
				],
			],
			'header_size' => [
				'type'    => 'select',
				'label'   => 'HTML Tag',
				'tab'     => 'content',
				'section' => 'content',
				'options' => [
					'h1' => 'H1',
					'h2' => 'H2',
				],
			],
			'motion_fx'   => [
				'type'         => 'switcher',
				'label'        => 'Motion',
				'tab'          => 'advanced',
				'section'      => 'motion',
				'pro_required' => true,
				'source'       => 'elementor-pro',
			],
		];
	}
}

final class PlainLlmProWidget {
	public function get_title(): string {
		return 'Pro Gated';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return list<string> */
	public function get_keywords(): array {
		return [ 'pro' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'form_name' => [
				'type'    => 'text',
				'label'   => 'Form Name',
				'tab'     => 'content',
				'section' => 'content',
			],
		];
	}
}

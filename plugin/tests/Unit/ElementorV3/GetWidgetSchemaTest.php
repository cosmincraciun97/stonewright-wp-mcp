<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema
 */
final class GetWidgetSchemaTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [ 'edit_posts' => true ];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widget = new class() {
						public function get_title(): string {
							return 'Schema Modes';
						}

						/** @return list<string> */
						public function get_categories(): array {
							return [ 'basic' ];
						}

						/** @return array<string, array<string, mixed>> */
						public function get_controls(): array {
							return [
								'title' => [
									'type'        => 'text',
									'label'       => 'Title',
									'description' => 'Visible copy',
									'tab'         => 'content',
									'section'     => 'content',
									'default'     => [
										'$$type' => 'string',
										'value'  => 'Hello',
									],
								],
							];
						}
					};
					$widgets = [ 'schema-modes' => $widget ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_response_mode_enum_includes_summary_compact_and_full(): void {
		$schema = ( new GetWidgetSchema() )->input_schema();

		self::assertSame( [ 'summary', 'compact', 'full' ], $schema['properties']['responseMode']['enum'] );
		self::assertContains( $schema['properties']['responseMode']['default'], [ 'summary', 'compact' ] );
	}

	public function test_summary_mode_returns_only_type_and_description_per_control(): void {
		$result = ( new GetWidgetSchema() )->execute(
			[
				'name'         => 'schema-modes',
				'responseMode' => 'summary',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'summary', $result['response_mode'] );
		$control = $result['controls']['title'] ?? $result['controls'][0] ?? null;
		self::assertIsArray( $control );
		self::assertSame( [ 'type', 'description' ], array_keys( $control ) );
		self::assertSame( 'text', $control['type'] );
		self::assertSame( 'Visible copy', $control['description'] );
	}

	public function test_compact_mode_keeps_control_identity_without_defaults(): void {
		$result = ( new GetWidgetSchema() )->execute(
			[
				'name'         => 'schema-modes',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'compact', $result['response_mode'] );
		self::assertSame( 'title', $result['controls'][0]['name'] );
		self::assertSame( 'text', $result['controls'][0]['type'] );
		self::assertArrayNotHasKey( 'default', $result['controls'][0] );
	}
}

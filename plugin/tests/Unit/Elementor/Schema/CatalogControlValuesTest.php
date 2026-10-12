<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * The bundled widget catalog lists the controls of the Elementor widgets with their defaults and options. Elementor
 * stores those values itself, so the settings check accepts every one of them for the control that lists it.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 */
final class CatalogControlValuesTest extends TestCase {

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

	/** @return array<string, array<string, mixed>> Catalog entries by widget type. */
	private static function entries(): array {
		$entries = [];
		foreach ( glob( dirname( __DIR__, 4 ) . '/includes/Elementor/WidgetRegistry/catalog/shards/*.php' ) ?: [] as $file ) {
			$entry = include $file;
			if ( is_array( $entry ) && is_string( $entry['widget_type'] ?? null ) && is_array( $entry['sections'] ?? null ) ) {
				$entries[ $entry['widget_type'] ] = $entry;
			}
		}

		return $entries;
	}

	/** Whether a catalog value could not be read from the Elementor source (a PHP expression kept as a marker). */
	private static function unresolved( mixed $value ): bool {
		return str_contains( (string) wp_json_encode( $value ), '__unresolved__' );
	}

	/** @param array<string, array<string, mixed>> $entries */
	private function serve( array $entries ): void {
		$controls = [];
		foreach ( $entries as $type => $entry ) {
			$controls[ $type ] = [];
			foreach ( $entry['sections'] as $section ) {
				foreach ( $section['controls'] ?? [] as $control ) {
					if ( ! is_string( $control['key'] ?? null ) || ! is_string( $control['type'] ?? null ) ) {
						continue;
					}
					$live = [
						'type'       => $control['type'],
						'label'      => is_string( $control['label'] ?? null ) ? $control['label'] : '',
						'responsive' => ! empty( $control['responsive'] ),
					];
					if ( is_array( $control['options'] ?? null ) && ! self::unresolved( $control['options'] ) ) {
						$live['options'] = $control['options'];
					}
					$controls[ $type ][ $control['key'] ] = $live;
				}
			}
		}
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class( $controls ) {
				/** @param array<string, array<string, array<string, mixed>>> $controls */
				public function __construct( private array $controls ) {}

				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$make = fn( string $type ): object => new class( $type, $this->controls[ $type ] ) {
						/** @param array<string, array<string, mixed>> $controls */
						public function __construct( private string $type, private array $controls ) {}
						public function get_title(): string {
							return $this->type;
						}
						/** @return array<string, array<string, mixed>> */
						public function get_controls(): array {
							return $this->controls;
						}
					};
					if ( null === $name ) {
						return array_map( $make, array_combine( array_keys( $this->controls ), array_keys( $this->controls ) ) );
					}

					return isset( $this->controls[ $name ] ) ? $make( $name ) : null;
				}
			},
		];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_every_default_and_option_the_catalog_lists_is_accepted_for_its_control(): void {
		$entries = self::entries();
		self::assertGreaterThan( 50, count( $entries ), 'The catalog shards are missing.' );
		$this->serve( $entries );

		$refused = [];
		$checked = 0;
		foreach ( $entries as $type => $entry ) {
			foreach ( $entry['sections'] as $section ) {
				foreach ( $section['controls'] ?? [] as $control ) {
					if ( ! is_string( $control['key'] ?? null ) || ! is_string( $control['type'] ?? null ) ) {
						continue;
					}
					$values = [];
					if ( array_key_exists( 'default', $control ) && null !== $control['default'] && ! self::unresolved( $control['default'] ) ) {
						$values['default'] = $control['default'];
					}
					if ( in_array( $control['type'], [ 'select', 'choose' ], true ) && is_array( $control['options'] ?? null ) && ! self::unresolved( $control['options'] ) ) {
						foreach ( array_keys( $control['options'] ) as $option ) {
							$values[ 'option ' . $option ] = (string) $option;
						}
					}
					foreach ( $values as $label => $value ) {
						++$checked;
						$result = SettingsValidator::validate( $type, [ $control['key'] => $value ], false, false );
						if ( $result instanceof \WP_Error ) {
							$refused[] = $type . '.' . $control['key'] . ' (' . $control['type'] . ') ' . $label . ' = ' . wp_json_encode( $value ) . ' -> ' . ( $result->get_error_data()['violations'][0]['code'] ?? $result->get_error_code() );
						}
					}
				}
			}
		}

		self::assertGreaterThan( 1500, $checked );
		self::assertSame( [], $refused, implode( "\n", array_slice( $refused, 0, 20 ) ) );
	}

	public function test_a_slider_that_holds_only_its_unit_and_an_unset_choice_are_accepted(): void {
		$this->serve( self::entries() );

		$image = SettingsValidator::validate( 'image', [ 'width' => [ 'unit' => '%' ], 'caption_align' => '' ], false, false );

		self::assertIsArray( $image, $image instanceof \WP_Error ? $image->get_error_message() : '' );
	}

	public function test_the_example_a_refusal_offers_for_a_control_is_itself_accepted(): void {
		$entries = self::entries();
		$this->serve( $entries );

		$rejected = [];
		$checked  = 0;
		foreach ( array_keys( $entries ) as $type ) {
			$schema = WidgetSchemaRepository::get( $type );
			self::assertIsArray( $schema );
			foreach ( (array) $schema['controls'] as $key => $control ) {
				$example = \Stonewright\WpMcp\Elementor\Schema\ControlHints::example_value( (string) $key, (array) $control );
				if ( null === $example ) {
					continue;
				}
				++$checked;
				$result = SettingsValidator::validate( $type, [ (string) $key => $example ], false, false );
				if ( $result instanceof \WP_Error ) {
					$rejected[] = $type . '.' . $key . ' (' . $control['type'] . ') ' . wp_json_encode( $example );
				}
			}
		}

		self::assertGreaterThan( 1000, $checked );
		self::assertSame( [], $rejected, implode( "\n", array_slice( $rejected, 0, 20 ) ) );
	}
}

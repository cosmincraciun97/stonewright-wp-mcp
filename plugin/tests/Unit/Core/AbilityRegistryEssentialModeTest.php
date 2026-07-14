<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRegistryEssentialModeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities' => [],
			'stonewright_essential_tools_mode' => false,
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_essential_mode_is_default_when_option_is_unset(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] );

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/wp-cli-batch-run', $names );
		self::assertContains( 'stonewright/theme-builder-apply-template', $names );
		self::assertContains( 'stonewright/content-model-loop-grid-flow', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertLessThanOrEqual( 20, count( $names ) );
	}

	public function test_essential_mode_filters_to_compact_fast_path(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $names );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertContains( 'stonewright/wp-cli-batch-run', $names );
		self::assertContains( 'stonewright/theme-builder-apply-template', $names );
		self::assertContains( 'stonewright/content-model-loop-grid-flow', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
		self::assertLessThanOrEqual( 20, count( $names ) );
	}

	public function test_essential_mode_keeps_explicit_extras_visible(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [ 'stonewright/sandbox-write' ];

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/sandbox-write', $names );
	}

	public function test_empty_schema_properties_encode_as_json_objects(): void {
		$abilities = [];
		foreach ( AbilityRegistry::enabled_abilities() as $ability ) {
			$abilities[ (string) $ability['name'] ] = $ability;
		}

		$json    = json_encode( $abilities['stonewright/system-instructions-get']['input_schema'], JSON_THROW_ON_ERROR );
		$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );

		self::assertInstanceOf( \stdClass::class, $decoded->properties );
	}

	public function test_public_input_schema_arrays_declare_items_for_strict_clients(): void {
		$errors = [];
		foreach ( AbilityRegistry::enabled_abilities() as $ability ) {
			self::collect_missing_array_items(
				$ability['input_schema'],
				(string) $ability['name'] . '.input_schema',
				$errors
			);
		}

		self::assertSame( [], $errors );
	}

	public function test_public_output_schema_arrays_declare_items_for_strict_clients(): void {
		$errors = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability = new $class();
			self::collect_missing_array_items(
				self::registry_output_schema_for_ability( $ability ),
				$ability->name() . '.output_schema',
				$errors
			);
		}

		self::assertSame( [], $errors );
	}

	/**
	 * @param mixed             $schema
	 * @param array<int,string> $errors
	 */
	private static function collect_missing_array_items( mixed $schema, string $path, array &$errors ): void {
		if ( ! is_array( $schema ) ) {
			return;
		}

		if ( 'array' === ( $schema['type'] ?? null ) && ! array_key_exists( 'items', $schema ) ) {
			$errors[] = $path . ' is array without items';
		}

		foreach ( $schema as $key => $value ) {
			self::collect_missing_array_items( $value, $path . '.' . (string) $key, $errors );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function registry_output_schema_for_ability( Ability $ability ): array {
		$method = new \ReflectionMethod( AbilityRegistry::class, 'output_schema_for_ability' );

		/** @var array<string, mixed> $schema */
		$schema = $method->invoke( null, $ability );
		return $schema;
	}
}

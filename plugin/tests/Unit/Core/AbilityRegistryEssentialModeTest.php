<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Admin\ConfigurationPage;
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

		self::assertContains( 'stonewright/task-start', $names );
		self::assertNotContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertNotContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/wp-cli-batch-run', $names );
		self::assertContains( 'stonewright/theme-builder-apply-template', $names );
		self::assertContains( 'stonewright/content-model-loop-grid-flow', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertContains( 'stonewright/elementor-post-write-verify', $names );
		self::assertContains( 'stonewright/elementor-wire-loop', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertLessThanOrEqual( 30, count( $names ) );
	}

	public function test_essential_includes_blueprint_and_clone_path(): void {
		$names = AbilityRegistry::essential_ability_names_for_test();
		foreach (
			[
				'stonewright/blueprint-list',
				'stonewright/blueprint-get',
				'stonewright/blueprint-apply',
				'stonewright/brand-kit-list',
				'stonewright/brand-kit-apply',
				'stonewright/elementor-page-digest',
				'stonewright/elementor-post-write-verify',
				'stonewright/site-pulse',
				'stonewright/learning-record',
				'stonewright/design-direction-brief',
			] as $name
		) {
			self::assertContains( $name, $names, $name . ' missing from essential profile' );
		}
	}

	public function test_essential_surface_includes_theme_file_patch_within_budget(): void {
		$names = AbilityRegistry::essential_ability_names_for_test();

		self::assertContains( 'stonewright/theme-file-patch', $names );
		self::assertLessThanOrEqual(
			\Stonewright\WpMcp\Support\TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS,
			count( $names )
		);
	}

	public function test_essential_mode_filters_to_compact_fast_path(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/task-start', $names );
		self::assertNotContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertNotContains( 'stonewright/php-execute', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $names );
		self::assertContains( 'stonewright/elementor-post-write-verify', $names );
		self::assertContains( 'stonewright/elementor-wire-loop', $names );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertContains( 'stonewright/wp-cli-batch-run', $names );
		self::assertContains( 'stonewright/theme-builder-apply-template', $names );
		self::assertContains( 'stonewright/content-model-loop-grid-flow', $names );
		self::assertContains( 'stonewright/blueprint-apply', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
		self::assertLessThanOrEqual( 30, count( $names ) );
	}

	/**
	 * Design Direction tools reach clients through the design profile, never
	 * through the startup surfaces. The write trio in particular replaces live
	 * design intent, so it must not be one tab-complete away at session start.
	 */
	public function test_design_direction_abilities_stay_out_of_startup_surfaces(): void {
		$bootstrap = AbilityRegistry::bootstrap_ability_names();
		$essential = AbilityRegistry::essential_ability_names_for_test();

		foreach ( [ 'list', 'get', 'save', 'activate', 'restore' ] as $verb ) {
			$name = 'stonewright/design-direction-' . $verb;
			self::assertNotContains( $name, $bootstrap, $name . ' must not ship in the bootstrap surface' );
			self::assertNotContains( $name, $essential, $name . ' must not ship in the essential surface' );
		}

		self::assertLessThanOrEqual(
			\Stonewright\WpMcp\Support\TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS,
			count( $essential )
		);
	}

	public function test_essential_mode_keeps_explicit_extras_visible(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [ 'stonewright/sandbox-write' ];

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/sandbox-write', $names );
	}

	public function test_v4_mutators_keep_envelope_metadata_when_flag_hides_mcp_tools(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled']              = true;
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']          = 'full';
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = false;
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;

		$public   = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		$envelope = [];
		foreach ( AbilityRegistry::all_abilities() as $row ) {
			$envelope[ (string) $row['name'] ] = $row;
		}

		self::assertNotContains( 'stonewright/elementor-v4-update-node', $public );
		self::assertArrayHasKey( 'stonewright/elementor-v4-update-node', $envelope );

		$schema   = $envelope['stonewright/elementor-v4-update-node']['input_schema'];
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];
		self::assertArrayHasKey( 'stonewright_context_token', $schema['properties'] );
		self::assertContains( 'stonewright_context_token', $required );
	}

	public function test_v4_abilities_hidden_when_flag_off_on_every_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled']              = true;
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;

		foreach ( [ 'bootstrap', 'essential', 'full' ] as $surface ) {
			$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = $surface;
			$public = self::public_ability_names();
			$mcp    = AbilityRegistry::mcp_server_ability_names();
			$this->assert_experimental_v4_abilities_hidden( $public, 'public_classes on ' . $surface );
			$this->assert_experimental_v4_abilities_hidden( $mcp, 'mcp_server_ability_names on ' . $surface );
			if ( 'full' === $surface ) {
				self::assertContains( 'stonewright/elementor-v4-status', $public );
				self::assertContains( 'stonewright/elementor-v4-status', $mcp );
			}
		}

		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'essential';
		$GLOBALS['stonewright_test_transients'] = [];
		$_SERVER['HTTP_MCP_SESSION_ID'] = 'v4-flag-off-session';
		try {
			self::assertTrue( AbilityRegistry::set_session_tool_profile( 'full', [] ) );
			$public = self::public_ability_names();
			$this->assert_experimental_v4_abilities_hidden( $public, 'public_classes on essential with session full' );
			$this->assert_experimental_v4_abilities_hidden(
				AbilityRegistry::mcp_server_ability_names(),
				'mcp_server_ability_names on essential with session full'
			);
			self::assertContains( 'stonewright/elementor-v4-status', $public );
		} finally {
			unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
			$GLOBALS['stonewright_test_transients'] = [];
		}
	}

	public function test_enabling_v4_flag_restores_abilities_and_bumps_surface_revision(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled']              = true;
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']          = 'full';
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		$GLOBALS['stonewright_test_registered_settings']                         = [];

		self::assertNotContains( 'stonewright/elementor-v4-update-node', AbilityRegistry::mcp_server_ability_names() );

		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = true;
		$names = AbilityRegistry::mcp_server_ability_names();
		self::assertContains( 'stonewright/elementor-v4-status', $names );
		self::assertContains( 'stonewright/elementor-v4-update-node', $names );
		self::assertContains( 'stonewright/elementor-v4-read-atomic-tree', $names );

		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = false;
		ConfigurationPage::register_settings();
		$setting = array_values(
			array_filter(
				$GLOBALS['stonewright_test_registered_settings'],
				static fn( array $registered ): bool => 'stonewright_elementor_v4_atomic' === $registered['option']
			)
		)[0];
		$sanitize = $setting['args']['sanitize_callback'];
		$start    = AbilityRegistry::surface_revision();
		self::assertTrue( $sanitize( true ) );
		self::assertSame( $start + 1, AbilityRegistry::surface_revision() );
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

	public function test_every_public_schema_fragment_encodes_as_an_object_for_strict_clients(): void {
		$errors = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			/** @var Ability $ability */
			$ability = new $class();
			foreach (
				[
					'input_schema'  => self::registry_input_schema_for_ability( $ability ),
					'output_schema' => self::registry_output_schema_for_ability( $ability ),
				] as $kind => $schema
			) {
				$encoded = json_encode( $schema, JSON_THROW_ON_ERROR );
				$decoded = json_decode( $encoded, false, 512, JSON_THROW_ON_ERROR );
				self::collect_invalid_schema_fragments(
					$decoded,
					$ability->name() . '.' . $kind,
					$errors
				);
			}
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
	 * @param array<int,string> $errors
	 */
	private static function collect_invalid_schema_fragments( mixed $schema, string $path, array &$errors ): void {
		if ( is_bool( $schema ) ) {
			return;
		}

		if ( ! $schema instanceof \stdClass ) {
			$errors[] = $path . ' must encode as a JSON Schema object or boolean';
			return;
		}

		foreach ( [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ] as $key ) {
			if ( ! property_exists( $schema, $key ) || ! $schema->{$key} instanceof \stdClass ) {
				continue;
			}

			foreach ( get_object_vars( $schema->{$key} ) as $name => $fragment ) {
				self::collect_invalid_schema_fragments( $fragment, $path . '.' . $key . '.' . $name, $errors );
			}
		}

		foreach ( [ 'additionalProperties', 'contains', 'contentSchema', 'else', 'if', 'items', 'not', 'propertyNames', 'then', 'unevaluatedItems', 'unevaluatedProperties' ] as $key ) {
			if ( property_exists( $schema, $key ) ) {
				self::collect_invalid_schema_fragments( $schema->{$key}, $path . '.' . $key, $errors );
			}
		}

		foreach ( [ 'allOf', 'anyOf', 'oneOf', 'prefixItems' ] as $key ) {
			if ( ! property_exists( $schema, $key ) || ! is_array( $schema->{$key} ) ) {
				continue;
			}

			foreach ( $schema->{$key} as $index => $fragment ) {
				self::collect_invalid_schema_fragments( $fragment, $path . '.' . $key . '.' . $index, $errors );
			}
		}
	}

	/**
	 * @param list<string> $names
	 */
	private function assert_experimental_v4_abilities_hidden( array $names, string $surface ): void {
		$leaked = array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => str_starts_with( $name, 'stonewright/elementor-v4-' )
					&& 'stonewright/elementor-v4-status' !== $name
			)
		);
		self::assertSame( [], $leaked, 'experimental V4 abilities leaked on ' . $surface . ': ' . implode( ', ', $leaked ) );
	}

	/**
	 * @return list<string>
	 */
	private static function public_ability_names(): array {
		$method = new \ReflectionMethod( AbilityRegistry::class, 'public_classes' );
		$names  = [];
		foreach ( $method->invoke( null ) as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var Ability $ability */
			$ability = new $class();
			$names[] = $ability->name();
		}

		return $names;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function registry_input_schema_for_ability( Ability $ability ): array {
		$method = new \ReflectionMethod( AbilityRegistry::class, 'input_schema_for_ability' );

		/** @var array<string, mixed> $schema */
		$schema = $method->invoke( null, $ability );
		return $schema;
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

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\McpSchemaWire;
use WP\MCP\Domain\Utils\SchemaTransformer;
use WP\McpSchema\Server\Tools\DTO\Tool;

/**
 * Every ability schema that leaves the plugin (MCP tools/list and the REST ability list)
 * is a JSON Schema 2020-12 node: an object or a boolean at every schema position.
 *
 * @covers \Stonewright\WpMcp\Core\McpSchemaWire
 */
final class McpSchemaWireTest extends TestCase {

	private const STRUCTURAL_ID = 'https://example.test/schemas/structural-2020-12.schema.json';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_filters']['rest_post_dispatch'] );
	}

	public function test_every_tool_schema_served_by_tools_list_is_valid_json_schema(): void {
		$tools = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$tools[] = $this->tool_as_the_adapter_builds_it( new $class() );
		}

		$response = McpSchemaWire::repair_response( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'tools' => $tools ] ] );
		$decoded  = json_decode( (string) json_encode( $response ), false, 512, JSON_THROW_ON_ERROR );

		$failures = [];
		foreach ( $decoded->result->tools as $tool ) {
			foreach ( [ 'inputSchema', 'outputSchema' ] as $kind ) {
				if ( ! isset( $tool->{$kind} ) ) {
					continue;
				}
				$failures = array_merge( $failures, $this->structural_failures( $tool->{$kind}, $tool->name . '.' . $kind ) );
			}
		}

		self::assertGreaterThan( 300, count( $decoded->result->tools ) );
		self::assertSame( [], array_slice( $failures, 0, 20 ), count( $failures ) . ' invalid schema nodes in tools/list.' );
	}

	public function test_every_schema_in_the_rest_ability_list_is_valid_json_schema(): void {
		$abilities = json_decode( (string) json_encode( AbilityRegistry::all_abilities() ), false, 512, JSON_THROW_ON_ERROR );

		$failures = [];
		foreach ( $abilities as $ability ) {
			$failures = array_merge( $failures, $this->structural_failures( $ability->input_schema, $ability->name . '.input_schema' ) );
		}

		self::assertGreaterThan( 300, count( $abilities ) );
		self::assertSame( [], array_slice( $failures, 0, 20 ), count( $failures ) . ' invalid schema nodes in the REST ability list.' );
	}

	public function test_the_adapter_round_trip_loses_empty_objects_without_the_wire_repair(): void {
		$tool = $this->tool_as_the_adapter_builds_it( new \Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks() );

		$decoded = json_decode( (string) json_encode( $tool ), false, 512, JSON_THROW_ON_ERROR );

		self::assertIsArray( $decoded->outputSchema->properties->blocks->items, 'The adapter turns {} into [] for a permissive items schema.' );
	}

	public function test_repair_restores_empty_object_maps_and_permissive_schemas(): void {
		$tool = [
			'name'         => 'x',
			'inputSchema'  => [
				'type'       => 'object',
				'properties' => [
					'list'  => [ 'type' => 'array', 'items' => [] ],
					'map'   => [ 'type' => 'object', 'properties' => [] ],
					'any'   => [],
					'enums' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [] ],
				],
				'required'   => [],
			],
			'outputSchema' => [
				'type'       => 'object',
				'properties' => [
					'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'cells' => [ 'type' => 'array', 'items' => [] ] ] ] ],
					'one'  => [ 'oneOf' => [ [], [ 'type' => 'string' ] ] ],
				],
			],
		];

		$repaired = McpSchemaWire::repair_response( [ 'result' => [ 'tools' => [ $tool ] ] ] );
		$json     = (string) json_encode( $repaired );

		self::assertStringContainsString( '"list":{"type":"array","items":{}}', $json );
		self::assertStringContainsString( '"map":{"type":"object","properties":{}}', $json );
		self::assertStringContainsString( '"any":{}', $json );
		self::assertStringContainsString( '"default":[]', $json, 'A default value is data, not a schema.' );
		self::assertStringContainsString( '"required":[]', $json );
		self::assertStringContainsString( '"cells":{"type":"array","items":{}}', $json );
		self::assertStringContainsString( '"oneOf":[{},{"type":"string"}]', $json );
	}

	public function test_a_property_named_like_a_keyword_is_still_a_schema_entry(): void {
		$tool = [
			'name'        => 'x',
			'inputSchema' => [
				'type'       => 'object',
				'properties' => [
					'items'      => [],
					'properties' => [ 'type' => 'array', 'items' => [] ],
					'default'    => [],
				],
			],
		];

		$json = (string) json_encode( McpSchemaWire::repair_response( [ 'result' => [ 'tools' => [ $tool ] ] ] ) );

		self::assertStringContainsString( '"properties":{"items":{},"properties":{"type":"array","items":{}},"default":{}}', $json );
	}

	public function test_repair_leaves_other_json_rpc_results_untouched(): void {
		$call = [ 'jsonrpc' => '2.0', 'id' => 2, 'result' => [ 'structuredContent' => [ 'items' => [], 'properties' => [] ], 'isError' => false ] ];

		self::assertSame( $call, McpSchemaWire::repair_response( $call ) );
		self::assertSame( [ 'error' => [ 'code' => -32003 ] ], McpSchemaWire::repair_response( [ 'error' => [ 'code' => -32003 ] ] ) );
		self::assertNull( McpSchemaWire::repair_response( null ) );
	}

	public function test_repair_handles_batch_responses(): void {
		$batch = [
			[ 'id' => 1, 'result' => [ 'tools' => [ [ 'name' => 'a', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'l' => [ 'type' => 'array', 'items' => [] ] ] ] ] ] ] ],
			[ 'id' => 2, 'result' => [ 'content' => [] ] ],
		];

		$json = (string) json_encode( McpSchemaWire::repair_response( $batch ) );

		self::assertStringContainsString( '"items":{}', $json );
		self::assertStringContainsString( '"content":[]', $json );
	}

	public function test_the_response_filter_repairs_only_the_stonewright_mcp_routes(): void {
		$payload = [ 'result' => [ 'tools' => [ [ 'name' => 'a', 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'l' => [ 'type' => 'array', 'items' => [] ] ] ] ] ] ] ];

		foreach ( [ '/mcp/stonewright', '/mcp/stonewright-oauth' ] as $route ) {
			$response = $this->settable_response( $payload );
			McpSchemaWire::filter_response( $response, new \stdClass(), new \WP_REST_Request( 'POST', $route ) );
			self::assertStringContainsString( '"items":{}', (string) json_encode( $response->get_data() ), $route );
		}

		$other = $this->settable_response( $payload );
		McpSchemaWire::filter_response( $other, new \stdClass(), new \WP_REST_Request( 'POST', '/other/route' ) );
		self::assertSame( $payload, $other->get_data() );
	}

	public function test_the_response_filter_is_registered(): void {
		McpSchemaWire::register();

		self::assertArrayHasKey( 'rest_post_dispatch', $GLOBALS['stonewright_test_filters'] );
		self::assertSame( [ McpSchemaWire::class, 'filter_response' ], $GLOBALS['stonewright_test_filters']['rest_post_dispatch'] );
	}

	/**
	 * Builds the tool array the way the MCP adapter does: schema transform, DTO, toArray().
	 *
	 * @return array<string, mixed>
	 */
	private function tool_as_the_adapter_builds_it( Ability $ability ): array {
		$input_method  = new \ReflectionMethod( AbilityRegistry::class, 'input_schema_for_ability' );
		$output_method = new \ReflectionMethod( AbilityRegistry::class, 'output_schema_for_ability' );

		$input  = SchemaTransformer::transform_to_object_schema( $input_method->invoke( null, $ability ) );
		$output = SchemaTransformer::transform_to_object_schema( $output_method->invoke( null, $ability ), 'result' );

		return Tool::fromArray(
			[
				'name'         => AbilityRegistry::mcp_tool_name( $ability->name() ),
				'inputSchema'  => $input['schema'],
				'outputSchema' => $output['schema'],
			]
		)->toArray();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function settable_response( array $data ): \WP_REST_Response {
		return new class( $data ) extends \WP_REST_Response {
			private mixed $payload;

			/** @param array<string, mixed> $data */
			public function __construct( array $data ) {
				parent::__construct( $data );
				$this->payload = $data;
			}

			public function get_data(): mixed {
				return $this->payload;
			}

			public function set_data( mixed $data ): void {
				$this->payload = $data;
			}
		};
	}

	/**
	 * @return list<string> One entry per schema node that is not valid JSON Schema.
	 */
	private function structural_failures( mixed $schema, string $label ): array {
		static $validator = null;
		if ( null === $validator ) {
			$validator = new Validator();
			$validator->resolver()->registerRaw( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/json-schema/structural-2020-12.schema.json' ) );
		}

		$failures = $this->misplaced_arrays( $schema, $label );
		$result   = $validator->validate( $schema, self::STRUCTURAL_ID );
		if ( ! $result->isValid() && [] === $failures ) {
			$lines      = ( new ErrorFormatter() )->formatFlat( $result->error() );
			$failures[] = $label . ': ' . ( $lines[0] ?? 'not a valid schema' );
		}

		return array_merge( $failures, $this->invalid_patterns( $schema, $label ) );
	}

	/**
	 * Names every schema position that holds a JSON array where an object or boolean is required.
	 *
	 * @return list<string>
	 */
	private function misplaced_arrays( mixed $node, string $path ): array {
		if ( is_bool( $node ) ) {
			return [];
		}
		if ( ! $node instanceof \stdClass ) {
			return [ $path . ' is ' . gettype( $node ) . ', expected object' ];
		}

		$failures = [];
		foreach ( [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ] as $keyword ) {
			if ( ! property_exists( $node, $keyword ) ) {
				continue;
			}
			if ( ! $node->{$keyword} instanceof \stdClass ) {
				$failures[] = $path . '.' . $keyword . ' is not an object';
				continue;
			}
			foreach ( get_object_vars( $node->{$keyword} ) as $name => $child ) {
				$failures = array_merge( $failures, $this->misplaced_arrays( $child, $path . '.' . $keyword . '.' . $name ) );
			}
		}
		foreach ( [ 'additionalProperties', 'contains', 'else', 'if', 'items', 'not', 'propertyNames', 'then', 'unevaluatedItems', 'unevaluatedProperties' ] as $keyword ) {
			if ( property_exists( $node, $keyword ) ) {
				$failures = array_merge( $failures, $this->misplaced_arrays( $node->{$keyword}, $path . '.' . $keyword ) );
			}
		}
		foreach ( [ 'allOf', 'anyOf', 'oneOf', 'prefixItems' ] as $keyword ) {
			if ( ! property_exists( $node, $keyword ) ) {
				continue;
			}
			foreach ( (array) $node->{$keyword} as $index => $child ) {
				$failures = array_merge( $failures, $this->misplaced_arrays( $child, $path . '.' . $keyword . '.' . $index ) );
			}
		}

		return $failures;
	}

	/**
	 * `pattern` values must compile as regular expressions.
	 *
	 * @return list<string>
	 */
	private function invalid_patterns( mixed $node, string $path ): array {
		$failures = [];
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $child ) {
				$failures = array_merge( $failures, $this->invalid_patterns( $child, $path . '/' . $key ) );
			}
			return $failures;
		}
		if ( ! $node instanceof \stdClass ) {
			return [];
		}
		foreach ( get_object_vars( $node ) as $key => $child ) {
			if ( 'pattern' === $key && is_string( $child ) && false === @preg_match( '~' . str_replace( '~', '\~', $child ) . '~u', '' ) ) {
				$failures[] = $path . ': pattern does not compile';
			}
			$failures = array_merge( $failures, $this->invalid_patterns( $child, $path . '/' . $key ) );
		}
		return $failures;
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use stdClass;

/**
 * Keeps ability schemas valid JSON Schema on the MCP wire.
 *
 * The abilities hold empty schema fragments as `stdClass` so they encode as `{}`. The MCP
 * adapter rebuilds every tool schema from plain arrays before it answers `tools/list`, and an
 * empty PHP array encodes as `[]`, which is not a schema. This filter restores `{}` at every
 * schema position of the tool schemas in the JSON-RPC response of the Stonewright MCP routes.
 * Values such as `default`, `enum` and `examples` are data and are never changed.
 */
final class McpSchemaWire {

	/** Keywords whose value is a map from a name to a schema. */
	private const SCHEMA_MAPS = [ '$defs', 'definitions', 'dependentSchemas', 'patternProperties', 'properties' ];

	/** Keywords whose value is one schema. */
	private const SCHEMAS = [ 'additionalProperties', 'contains', 'contentSchema', 'else', 'if', 'items', 'not', 'propertyNames', 'then', 'unevaluatedItems', 'unevaluatedProperties' ];

	/** Keywords whose value is a list of schemas. */
	private const SCHEMA_LISTS = [ 'allOf', 'anyOf', 'oneOf', 'prefixItems' ];

	public static function register(): void {
		add_filter( 'rest_post_dispatch', [ self::class, 'filter_response' ], 10, 3 );
	}

	/**
	 * @param mixed $response A REST response (WP_REST_Response) or any other dispatch result.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The REST request.
	 * @return mixed
	 */
	public static function filter_response( mixed $response, mixed $server, mixed $request ): mixed {
		unset( $server );

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( ! in_array( $route, self::routes(), true ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( self::repair_response( $data ) );
		}

		return $response;
	}

	/**
	 * Restores `{}` in the tool schemas of a JSON-RPC response or of a batch of responses.
	 *
	 * @param mixed $data JSON-RPC response (array) or batch (list of arrays).
	 * @return mixed
	 */
	public static function repair_response( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( array_is_list( $data ) ) {
			foreach ( $data as $index => $message ) {
				$data[ $index ] = self::repair_response( $message );
			}
			return $data;
		}

		// The adapter may answer with objects (initialize does), so read the result either way.
		$result = $data['result'] ?? null;
		if ( $result instanceof stdClass ) {
			if ( ! isset( $result->tools ) ) {
				return $data;
			}
			$result = (array) $result;
		}
		if ( ! is_array( $result ) || ! isset( $result['tools'] ) || ! is_array( $result['tools'] ) ) {
			return $data;
		}

		$tools = $result['tools'];
		foreach ( $tools as $index => $tool ) {
			if ( $tool instanceof stdClass ) {
				$tool = (array) $tool;
			}
			if ( ! is_array( $tool ) ) {
				continue;
			}
			foreach ( [ 'inputSchema', 'outputSchema' ] as $key ) {
				if ( isset( $tool[ $key ] ) && ( is_array( $tool[ $key ] ) || $tool[ $key ] instanceof stdClass ) ) {
					$tool[ $key ] = self::repair_schema( $tool[ $key ] );
				}
			}
			$tools[ $index ] = $tool;
		}
		$result['tools'] = $tools;
		$data['result']  = $result;

		return $data;
	}

	/**
	 * Returns a schema fragment in which every empty schema or schema map is a `stdClass`.
	 *
	 * The adapter hands back its property schemas as `stdClass` objects that hold plain arrays,
	 * so both arrays and objects are read as schema nodes.
	 *
	 * @param array<int|string, mixed>|stdClass $schema JSON Schema fragment.
	 * @return array<int|string, mixed>|stdClass
	 */
	public static function repair_schema( array|stdClass $schema ): array|stdClass {
		$schema = self::node( $schema ) ?? [];
		if ( [] === $schema ) {
			return new stdClass();
		}

		foreach ( self::SCHEMA_MAPS as $key ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$map = self::node( $schema[ $key ] );
			if ( null === $map ) {
				continue;
			}
			if ( [] === $map ) {
				$schema[ $key ] = new stdClass();
				continue;
			}
			foreach ( $map as $name => $fragment ) {
				if ( is_array( $fragment ) || $fragment instanceof stdClass ) {
					$map[ $name ] = self::repair_schema( $fragment );
				}
			}
			$schema[ $key ] = $map;
		}

		foreach ( self::SCHEMAS as $key ) {
			if ( isset( $schema[ $key ] ) && ( is_array( $schema[ $key ] ) || $schema[ $key ] instanceof stdClass ) ) {
				$schema[ $key ] = self::repair_schema( $schema[ $key ] );
			}
		}

		foreach ( self::SCHEMA_LISTS as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
			foreach ( $schema[ $key ] as $index => $fragment ) {
				if ( is_array( $fragment ) || $fragment instanceof stdClass ) {
					$schema[ $key ][ $index ] = self::repair_schema( $fragment );
				}
			}
		}

		return $schema;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	private static function node( mixed $value ): ?array {
		if ( $value instanceof stdClass ) {
			return (array) $value;
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * @return list<string>
	 */
	private static function routes(): array {
		return [
			'/' . ServerRegistration::ROUTE_NAMESPACE . '/' . ServerRegistration::ROUTE,
			'/' . ServerRegistration::ROUTE_NAMESPACE . '/' . ServerRegistration::OAUTH_ROUTE,
		];
	}
}

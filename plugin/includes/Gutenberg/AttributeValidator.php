<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

/**
 * Validates Gutenberg block attributes against a block.json-style schema.
 *
 * Unknown keys are refused with `offending_keys` — never silently dropped.
 */
final class AttributeValidator {

	/**
	 * @param array<string, mixed>      $attrs
	 * @param array<string, mixed>|null $schema Injected attribute schema. Null reads the live registry.
	 * @return true|\WP_Error
	 */
	public static function validate( string $block_name, array $attrs, ?array $schema = null ): true|\WP_Error {
		$block_name = sanitize_text_field( $block_name );
		if ( null === $schema ) {
			$schema = self::schema_for( $block_name );
		}
		if ( null === $schema ) {
			return true;
		}

		$unknown = [];
		foreach ( $attrs as $key => $_value ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $schema ) || ! is_array( $schema[ $key ] ) ) {
				$unknown[] = (string) $key;
			}
		}
		if ( [] !== $unknown ) {
			return self::error(
				'unknown_block_attributes',
				__( 'Block attributes are not declared by the registered block schema.', 'stonewright' ),
				$block_name,
				$unknown
			);
		}

		$missing = [];
		foreach ( $schema as $key => $definition ) {
			if ( ! is_string( $key ) || ! is_array( $definition ) ) {
				continue;
			}
			if ( ! empty( $definition['required'] ) && ! array_key_exists( $key, $attrs ) ) {
				$missing[] = $key;
			}
		}
		if ( [] !== $missing ) {
			return self::error(
				'invalid_block_attributes',
				__( 'Required block attributes are missing.', 'stonewright' ),
				$block_name,
				$missing
			);
		}

		foreach ( $attrs as $key => $value ) {
			$definition = $schema[ $key ];
			if ( ! self::type_matches( $value, $definition['type'] ?? null ) ) {
				return self::error(
					'invalid_block_attributes',
					__( 'A block attribute does not match the registered type.', 'stonewright' ),
					$block_name,
					[ $key ]
				);
			}
			if ( isset( $definition['enum'] ) && is_array( $definition['enum'] ) && ! in_array( $value, $definition['enum'], true ) ) {
				return self::error(
					'invalid_block_attributes',
					__( 'A block attribute is not in the registered enum.', 'stonewright' ),
					$block_name,
					[ $key ]
				);
			}
			if ( function_exists( 'rest_validate_value_from_schema' ) ) {
				$valid = rest_validate_value_from_schema( $value, $definition, $block_name . '.' . $key );
				if ( $valid instanceof \WP_Error ) {
					return self::error(
						'invalid_block_attributes',
						__( 'A block attribute does not match the registered block schema.', 'stonewright' ),
						$block_name,
						[ $key ]
					);
				}
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>|null Null when the block is not registered.
	 */
	public static function schema_for( string $block_name ): ?array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return null;
		}
		try {
			$registry = \WP_Block_Type_Registry::get_instance();
		} catch ( \Throwable $_throwable ) {
			return null;
		}
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_registered' ) ) {
			return null;
		}
		try {
			$registered = $registry->get_registered( $block_name );
		} catch ( \Throwable $_throwable ) {
			return null;
		}
		if ( ! is_object( $registered ) ) {
			return null;
		}
		$attributes = isset( $registered->attributes ) && is_array( $registered->attributes ) ? $registered->attributes : [];
		return $attributes;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param array<int, mixed>    $inner_blocks
	 * @return true|\WP_Error
	 */
	public static function validate_tree( string $block_name, array $attrs, array $inner_blocks = [] ): true|\WP_Error {
		$result = self::validate( $block_name, $attrs );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		foreach ( $inner_blocks as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_name  = (string) ( $child['name'] ?? $child['blockName'] ?? '' );
			$child_attrs = [];
			if ( isset( $child['attributes'] ) && is_array( $child['attributes'] ) ) {
				$child_attrs = $child['attributes'];
			} elseif ( isset( $child['attrs'] ) && is_array( $child['attrs'] ) ) {
				$child_attrs = $child['attrs'];
			}
			$nested = isset( $child['innerBlocks'] ) && is_array( $child['innerBlocks'] ) ? $child['innerBlocks'] : [];
			$child_result = self::validate_tree( $child_name, $child_attrs, $nested );
			if ( $child_result instanceof \WP_Error ) {
				return $child_result;
			}
		}
		return true;
	}

	private static function type_matches( mixed $value, mixed $type ): bool {
		if ( is_array( $type ) ) {
			foreach ( $type as $candidate ) {
				if ( self::type_matches( $value, $candidate ) ) {
					return true;
				}
			}
			return false;
		}
		return match ( $type ) {
			'null'    => null === $value,
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'string'  => is_string( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) || is_object( $value ),
			default   => true,
		};
	}

	/**
	 * @param list<string> $offending_keys
	 */
	private static function error( string $code, string $message, string $block_name, array $offending_keys ): \WP_Error {
		return new \WP_Error(
			'stonewright_' . $code,
			$message,
			[
				'status'         => 400,
				'block_name'     => $block_name,
				'offending_keys' => array_values( $offending_keys ),
			]
		);
	}
}

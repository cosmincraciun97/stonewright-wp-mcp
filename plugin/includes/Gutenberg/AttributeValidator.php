<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

/**
 * Validates Gutenberg block attributes against a block.json-style schema.
 *
 * Unknown keys are refused with `offending_keys` — never silently dropped,
 * except on the finalizer path when the registered schema is known-partial.
 */
final class AttributeValidator {

	/** @var list<string> */
	private const PARTIAL_NAMESPACES = [
		'kadence/',
		'generateblocks/',
		'uagb/',
	];

	/**
	 * @param array<string, mixed>      $attrs
	 * @param array<string, mixed>|null $schema  Injected attribute schema. Null reads the live registry.
	 * @param 'finalizer'|'server'      $context Finalizer may warn on unknown keys for partial schemas.
	 * @return true|array{warnings: list<array<string, mixed>>}|\WP_Error
	 */
	public static function validate( string $block_name, array $attrs, ?array $schema = null, string $context = 'server' ): bool|array|\WP_Error {
		$block_name = sanitize_text_field( $block_name );
		$context    = 'finalizer' === $context ? 'finalizer' : 'server';
		if ( null === $schema ) {
			$schema = self::schema_for( $block_name );
		}
		if ( null === $schema ) {
			return self::error(
				'block_not_registered',
				sprintf(
					/* translators: %s: block type name */
					__( 'Block "%s" is not registered.', 'stonewright' ),
					$block_name
				),
				$block_name,
				[]
			);
		}

		$unknown = [];
		foreach ( $attrs as $key => $_value ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $schema ) || ! is_array( $schema[ $key ] ) ) {
				$unknown[] = (string) $key;
			}
		}

		$warnings = [];
		if ( [] !== $unknown ) {
			if ( 'finalizer' === $context && self::is_schema_likely_partial( $block_name ) ) {
				$warnings[] = [
					'code'           => 'likely_partial_schema',
					'block_name'     => $block_name,
					'offending_keys' => array_values( $unknown ),
				];
			} else {
				return self::error(
					'unknown_block_attributes',
					__( 'Block attributes are not declared by the registered block schema.', 'stonewright' ),
					$block_name,
					$unknown
				);
			}
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
			if ( ! is_string( $key ) || ! array_key_exists( $key, $schema ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
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

		return [] === $warnings ? true : [ 'warnings' => $warnings ];
	}

	/**
	 * True when the server-side block.json is too thin to own unknown-key rejection.
	 */
	public static function is_schema_likely_partial( string $name ): bool {
		$name = sanitize_text_field( $name );
		foreach ( self::PARTIAL_NAMESPACES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		$registered = self::registered_type( $name );
		if ( ! is_object( $registered ) ) {
			return false;
		}

		$attributes = isset( $registered->attributes ) && is_array( $registered->attributes )
			? $registered->attributes
			: [];
		if ( count( $attributes ) >= 3 ) {
			return false;
		}

		return self::has_editor_script( $registered );
	}

	/**
	 * @return array<string, mixed>|null Null when the block is not registered.
	 */
	public static function schema_for( string $block_name ): ?array {
		$registered = self::registered_type( $block_name );
		if ( ! is_object( $registered ) ) {
			return null;
		}
		$attributes = isset( $registered->attributes ) && is_array( $registered->attributes ) ? $registered->attributes : [];
		return $attributes;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param array<int, mixed>    $inner_blocks
	 * @param 'finalizer'|'server' $context
	 * @return true|array{warnings: list<array<string, mixed>>}|\WP_Error
	 */
	public static function validate_tree( string $block_name, array $attrs, array $inner_blocks = [], string $context = 'server' ): bool|array|\WP_Error {
		$result = self::validate( $block_name, $attrs, null, $context );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$warnings = is_array( $result ) ? self::warning_list( $result ) : [];
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
			$nested       = isset( $child['innerBlocks'] ) && is_array( $child['innerBlocks'] ) ? $child['innerBlocks'] : [];
			$child_result = self::validate_tree( $child_name, $child_attrs, $nested, $context );
			if ( $child_result instanceof \WP_Error ) {
				return $child_result;
			}
			if ( is_array( $child_result ) ) {
				$warnings = array_merge( $warnings, self::warning_list( $child_result ) );
			}
		}

		return [] === $warnings ? true : [ 'warnings' => array_values( $warnings ) ];
	}

	private static function registered_type( string $block_name ): ?object {
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

		return is_object( $registered ) ? $registered : null;
	}

	private static function has_editor_script( object $registered ): bool {
		$handle = $registered->editor_script ?? '';
		if ( is_string( $handle ) && '' !== $handle ) {
			return true;
		}
		$handles = $registered->editor_script_handles ?? [];
		if ( ! is_array( $handles ) ) {
			return false;
		}
		foreach ( $handles as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return list<array<string, mixed>>
	 */
	private static function warning_list( array $result ): array {
		$warnings = $result['warnings'] ?? [];
		if ( ! is_array( $warnings ) ) {
			return [];
		}
		$out = [];
		foreach ( $warnings as $warning ) {
			if ( is_array( $warning ) ) {
				$out[] = $warning;
			}
		}
		return $out;
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

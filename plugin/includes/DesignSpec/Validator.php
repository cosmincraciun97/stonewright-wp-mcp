<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

use Stonewright\WpMcp\Design\Semantics\ActionValidator;

/**
 * Validates Stonewright Design Specs against the bundled JSON Schema.
 *
 * Uses opis/json-schema when available (composer dependency); otherwise falls
 * back to a hand-rolled structural check sufficient to keep bad payloads from
 * reaching renderers without requiring the dependency at runtime.
 */
final class Validator {

	public const SCHEMA_ID = 'https://stonewright.dev/schemas/design-spec/1.0.0.json';

	/**
	 * Validates and normalizes a design spec.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error Normalized spec on success; WP_Error with code
	 *         stonewright_spec_invalid on failure.
	 */
	public static function validate( array $spec ) {
		$errors     = [];
		$normalized = self::normalize( $spec );

		if ( class_exists( '\\Opis\\JsonSchema\\Validator' ) ) {
			try {
				$validator = new \Opis\JsonSchema\Validator();
				$resolver  = $validator->resolver();
				$schema    = self::load_schema_object();
				if ( null !== $schema && null !== $resolver ) {
					$resolver->registerRaw( $schema, self::SCHEMA_ID );
				}
				$result = $validator->validate( json_decode( wp_json_encode( $normalized ) ), self::SCHEMA_ID );
				if ( ! $result->isValid() ) {
					foreach ( $result->error()->subErrors() ?? [ $result->error() ] as $error ) {
						if ( ! $error ) {
							continue;
						}
						$errors[] = [
							'keyword' => $error->keyword(),
							'message' => $error->message(),
							'path'    => $error->data()->path(),
						];
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = [ 'keyword' => 'exception', 'message' => $e->getMessage(), 'path' => [] ];
			}
		} else {
			$errors = self::structural_check( $normalized );
		}

		$errors = array_merge( self::repair_checks( $normalized ), $errors );
		foreach ( ActionValidator::validate_design_spec( $normalized ) as $diagnostic ) {
			$errors[] = [
				'keyword' => (string) ( $diagnostic['code'] ?? 'semantic' ),
				'message' => (string) ( $diagnostic['repair'] ?? 'Resolve the semantic design error.' ),
				'path'    => self::parse_path( (string) ( $diagnostic['path'] ?? '' ) ),
			];
		}
		$errors = self::enrich_errors( $errors, $normalized );

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'stonewright_spec_invalid',
				'Design spec failed validation.',
				[ 'errors' => $errors ]
			);
		}

		$style_errors = StyleFidelityGuard::validate( $normalized );
		if ( ! empty( $style_errors ) ) {
			return new \WP_Error(
				'stonewright_spec_invalid',
				'Design spec failed validation.',
				[ 'errors' => $style_errors ]
			);
		}

		return $normalized;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function load_schema(): ?array {
		$path = STONEWRIGHT_DIR . 'schemas/stonewright.schema.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Returns the schema as a stdClass tree so Opis can register it correctly.
	 */
	private static function load_schema_object(): ?\stdClass {
		$path = STONEWRIGHT_DIR . 'schemas/stonewright.schema.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw );
		return $decoded instanceof \stdClass ? $decoded : null;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	public static function normalize( array $spec ): array {
		$spec['version']  = isset( $spec['version'] ) ? (string) $spec['version'] : '1.0.0';
		$spec['page']     = isset( $spec['page'] ) && is_array( $spec['page'] ) ? $spec['page'] : [];
		$spec['sections'] = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? array_values( $spec['sections'] ) : [];

		if ( isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) && ! empty( $spec['tokens'] ) ) {
			// keep provided tokens
		} else {
			unset( $spec['tokens'] );
		}

		foreach ( $spec['sections'] as $i => $section ) {
			$section          = is_array( $section ) ? $section : [];
			$section['id']    = isset( $section['id'] ) ? (string) $section['id'] : 'section_' . $i;
			$section['blocks'] = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? array_values( $section['blocks'] ) : [];
			$spec['sections'][ $i ] = $section;
		}

		return $spec;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function structural_check( array $spec ): array {
		$errors = [];

		if ( empty( $spec['page']['title'] ) ) {
			$errors[] = [ 'keyword' => 'required', 'message' => 'page.title is required', 'path' => [ 'page', 'title' ] ];
		}
		if ( ! is_array( $spec['sections'] ) || empty( $spec['sections'] ) ) {
			$errors[] = [ 'keyword' => 'required', 'message' => 'sections must contain at least one entry', 'path' => [ 'sections' ] ];
		} else {
			foreach ( $spec['sections'] as $i => $section ) {
				if ( ! is_array( $section ) || empty( $section['blocks'] ) ) {
					$errors[] = [ 'keyword' => 'required', 'message' => 'section[' . $i . '] must contain blocks', 'path' => [ 'sections', $i ] ];
				}
			}
		}
		return $errors;
	}

	/**
	 * Adds precise repair checks for schema branches that Opis can report at a
	 * broad parent path such as `sections`.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function repair_checks( array $spec ): array {
		$errors = [];
		foreach ( (array) ( $spec['sections'] ?? [] ) as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( array_key_exists( 'layout', $section ) && ( ! is_string( $section['layout'] ) || ! in_array( $section['layout'], [ 'stack', 'row', 'grid' ], true ) ) ) {
				$errors[] = [
					'keyword' => 'enum',
					'message' => 'section layout must be one of stack, row, or grid',
					'path'    => [ 'sections', $index, 'layout' ],
				];
			}
		}
		return $errors;
	}

	/**
	 * @param array<int, array<string, mixed>> $errors
	 * @param array<string, mixed>            $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function enrich_errors( array $errors, array $spec ): array {
		$out = [];
		$seen = [];
		foreach ( $errors as $error ) {
			$path = isset( $error['path'] ) && is_array( $error['path'] ) ? array_values( $error['path'] ) : [];
			$key  = (string) ( $error['keyword'] ?? '' ) . ':' . self::path_string( $path );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$value = self::value_at_path( $spec, $path );
			$error['path']                  = $path;
			$error['path_string']           = self::path_string( $path );
			$error['received_type']         = self::received_type( $value );
			$error['allowed_shapes']        = self::allowed_shapes( $path );
			$error['nearest_valid_example'] = self::nearest_valid_example( $path );
			$error['repair_hint']           = self::repair_hint( $path, (string) ( $error['keyword'] ?? '' ) );
			$out[] = $error;
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $path
	 */
	private static function path_string( array $path ): string {
		$out = '';
		foreach ( $path as $part ) {
			if ( is_int( $part ) || ctype_digit( (string) $part ) ) {
				$out .= '[' . (string) $part . ']';
				continue;
			}
			$out .= '' === $out ? (string) $part : '.' . (string) $part;
		}
		return $out;
	}

	/** @return array<int, int|string> */
	private static function parse_path( string $path ): array {
		if ( '' === $path ) {
			return [];
		}
		$parts = preg_split( '/\.|\[|\]/', $path, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		return array_map(
			static fn( string $part ): int|string => ctype_digit( $part ) ? (int) $part : $part,
			$parts
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<int, mixed>    $path
	 */
	private static function value_at_path( array $spec, array $path ): mixed {
		$value = $spec;
		foreach ( $path as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
				continue;
			}
			return null;
		}
		return $value;
	}

	private static function received_type( mixed $value ): string {
		if ( null === $value ) {
			return 'missing';
		}
		if ( is_array( $value ) ) {
			return self::array_is_list( $value ) ? 'array' : 'object';
		}
		return get_debug_type( $value );
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function array_is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, mixed> $path
	 * @return array<int, mixed>
	 */
	private static function allowed_shapes( array $path ): array {
		$last = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return [ 'stack', 'row', 'grid' ];
		}
		if ( 'sections' === $last ) {
			return [ 'non-empty array of section objects' ];
		}
		if ( 'blocks' === $last ) {
			return [ 'array of block objects' ];
		}
		if ( 'type' === $last ) {
			return [ 'supported block type string' ];
		}
		return [];
	}

	/**
	 * @param array<int, mixed> $path
	 * @return array<string, mixed>
	 */
	private static function nearest_valid_example( array $path ): array {
		$last = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return [ 'layout' => 'row' ];
		}
		if ( 'sections' === $last ) {
			return [
				'sections' => [
					[
						'id'     => 'hero',
						'blocks' => [
							[ 'type' => 'heading', 'text' => 'Hello' ],
						],
					],
				],
			];
		}
		if ( 'blocks' === $last ) {
			return [ 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'Text' ] ] ];
		}
		return [];
	}

	/**
	 * @param array<int, mixed> $path
	 */
	private static function repair_hint( array $path, string $keyword ): string {
		$path_string = self::path_string( $path );
		$last        = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return 'Set ' . $path_string . ' to "stack", "row", or "grid"; do not pass an object for section layout.';
		}
		if ( 'sections' === $last ) {
			return 'Set sections to a non-empty array. Each section needs id and blocks.';
		}
		if ( 'blocks' === $last ) {
			return 'Set ' . $path_string . ' to an array of block objects. Each block needs a supported type.';
		}
		return 'Repair ' . ( '' !== $path_string ? $path_string : 'spec' ) . ' to satisfy schema keyword ' . $keyword . '.';
	}
}

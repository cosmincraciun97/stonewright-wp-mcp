<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

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
}

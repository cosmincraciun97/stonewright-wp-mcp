<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * PHP mirror of companion/src/contracts/design-spec.schema.json.
 *
 * Provides the minimal shape constants needed by CompanionContract::validate()
 * to do structural validation of DesignSpec payloads in PHP without requiring
 * a full JSON Schema evaluator on the ingest path.
 *
 * These constants describe the TOP-LEVEL DesignSpec object only.
 * Nested validation (sections, blocks, tokens) is delegated to
 * Stonewright\WpMcp\DesignSpec\Validator::validate().
 *
 * Design decision: keep this flat and minimal — the companion is the authoritative
 * schema source; the Validator is the authoritative PHP runtime gate.
 */
final class DesignSpec {

	/**
	 * Required top-level fields in a DesignSpec payload.
	 *
	 * @var array<int, string>
	 */
	public const SPEC_REQUIRED = [ 'version', 'page', 'sections' ];

	/**
	 * Top-level property type hints for CompanionContract::validate().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const SPEC_PROPERTIES = [
		'version'     => [ 'type' => 'string' ],
		'page'        => [ 'type' => 'object' ],
		'sections'    => [ 'type' => 'array' ],
		'tokens'      => [ 'type' => 'object' ],
		'assets'      => [ 'type' => 'array' ],
		'breakpoints' => [ 'type' => 'array' ],
		'meta'        => [ 'type' => 'object' ],
	];

	/**
	 * Required fields inside a Section object.
	 *
	 * @var array<int, string>
	 */
	public const SECTION_REQUIRED = [ 'blocks' ];

	/**
	 * Required fields inside the page object.
	 *
	 * @var array<int, string>
	 */
	public const PAGE_REQUIRED = [ 'title' ];

	/**
	 * Required fields inside an AssetReference object.
	 *
	 * @var array<int, string>
	 */
	public const ASSET_REQUIRED = [ 'id', 'url' ];

	/**
	 * Minimal structural validation of a DesignSpec array.
	 *
	 * Returns an array of error strings, or empty array on success.
	 * This is intentionally lightweight — deep validation goes through
	 * Stonewright\WpMcp\DesignSpec\Validator::validate().
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, string>
	 */
	public static function check_shape( array $spec ): array {
		$errors = [];

		foreach ( self::SPEC_REQUIRED as $field ) {
			if ( ! array_key_exists( $field, $spec ) ) {
				$errors[] = "Missing required DesignSpec field: {$field}";
			}
		}

		if ( isset( $spec['page'] ) && is_array( $spec['page'] ) ) {
			foreach ( self::PAGE_REQUIRED as $field ) {
				if ( ! array_key_exists( $field, $spec['page'] ) ) {
					$errors[] = "Missing required DesignSpec.page field: {$field}";
				}
			}
		}

		if ( isset( $spec['sections'] ) ) {
			if ( ! is_array( $spec['sections'] ) || count( $spec['sections'] ) === 0 ) {
				$errors[] = 'DesignSpec.sections must be a non-empty array';
			} else {
				foreach ( $spec['sections'] as $i => $section ) {
					if ( ! is_array( $section ) || ! isset( $section['blocks'] ) || ! is_array( $section['blocks'] ) ) {
						$errors[] = "DesignSpec.sections[{$i}] is missing required field: blocks";
					}
				}
			}
		}

		return $errors;
	}
}

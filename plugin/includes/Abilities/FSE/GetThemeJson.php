<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetThemeJson extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-get-theme-json';
	}

	public function label(): string {
		return __( 'Get theme.json', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns theme.json presence and keys by default, or merged theme.json plus user styles when responseMode=full.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'compact', 'full' ],
					'default'     => 'summary',
					'description' => 'summary returns keys and type presence; compact adds settings/styles key lists; full restores the previous merged JSON dump.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
			return $this->error( 'theme_json_unavailable', __( 'theme.json resolver is not available on this site.', 'stonewright' ) );
		}

		$mode   = strtolower( trim( (string) ( $args['responseMode'] ?? 'summary' ) ) );
		if ( ! in_array( $mode, [ 'summary', 'compact', 'full' ], true ) ) {
			$mode = 'summary';
		}

		$merged = \WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
		$theme  = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
		$user   = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		$base   = [
			'response_mode'  => $mode,
			'supports_v3'    => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ),
			'theme_slug'     => get_stylesheet(),
			'full_mode_hint' => 'Call with responseMode=full only when the merged theme.json document is required for the next write.',
		];

		if ( 'full' === $mode ) {
			return $base + [
				'merged' => $merged,
				'theme'  => $theme,
				'user'   => $user,
			];
		}

		$settings = isset( $merged['settings'] ) && is_array( $merged['settings'] ) ? $merged['settings'] : [];
		$styles   = isset( $merged['styles'] ) && is_array( $merged['styles'] ) ? $merged['styles'] : [];
		$summary  = $base + [
			'merged_keys'        => self::keys( $merged ),
			'settings_keys'      => self::keys( $settings ),
			'styles_keys'        => self::keys( $styles ),
			'settings_types'     => self::types( $settings ),
			'has_user_overrides' => [] !== $user,
		];

		if ( 'compact' === $mode ) {
			$summary['theme_keys'] = self::keys( $theme );
			$summary['user_keys']  = self::keys( $user );
		}

		return $summary;
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function keys( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_map( 'strval', array_keys( $value ) ) );
	}

	/**
	 * @param mixed $value
	 * @return array<string, string>
	 */
	private static function types( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $child ) {
			$out[ (string) $key ] = gettype( $child );
		}
		return $out;
	}
}

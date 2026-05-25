<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ExtractTokens extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-extract-tokens';
	}

	public function label(): string {
		return __( 'Extract design tokens', 'stonewright' );
	}

	public function description(): string {
		return __( 'Extracts color, typography, and spacing tokens from a theme.json blob or CSS custom properties.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'source' => [
					'type' => 'string',
					'enum' => [ 'theme_json', 'css_vars' ],
				],
				'data'   => [ 'type' => 'object' ],
			],
			'required'             => [ 'source', 'data' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'tokens' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$source = (string) $args['source'];
		$data   = (array) $args['data'];

		switch ( $source ) {
			case 'theme_json':
				return [ 'tokens' => $this->from_theme_json( $data ) ];
			case 'css_vars':
				return [ 'tokens' => $this->from_css_vars( $data ) ];
			default:
				return $this->error( 'unknown_source', __( 'Unsupported token source.', 'stonewright' ) );
		}
	}

	private function from_theme_json( array $data ): array {
		$tokens   = [ 'colors' => [], 'typography' => [], 'spacing' => [] ];
		$palette  = $data['settings']['color']['palette'] ?? [];
		$type     = $data['settings']['typography']['fontFamilies'] ?? [];
		$spacings = $data['settings']['spacing']['spacingSizes'] ?? [];

		foreach ( (array) $palette as $entry ) {
			$slug = sanitize_key( (string) ( $entry['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$tokens['colors'][ $slug ] = (string) ( $entry['color'] ?? '' );
			}
		}
		foreach ( (array) $type as $entry ) {
			$slug = sanitize_key( (string) ( $entry['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$tokens['typography'][ $slug ] = [ 'font_family' => (string) ( $entry['fontFamily'] ?? '' ) ];
			}
		}
		foreach ( (array) $spacings as $entry ) {
			$slug = sanitize_key( (string) ( $entry['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$tokens['spacing'][ $slug ] = (string) ( $entry['size'] ?? '' );
			}
		}
		return $tokens;
	}

	private function from_css_vars( array $data ): array {
		$tokens = [ 'colors' => [], 'spacing' => [] ];
		foreach ( $data as $key => $value ) {
			$slug = sanitize_key( str_replace( '--', '', (string) $key ) );
			$val  = (string) $value;
			if ( 0 === strpos( $val, '#' ) || str_starts_with( $val, 'rgb' ) || str_starts_with( $val, 'hsl' ) ) {
				$tokens['colors'][ $slug ] = $val;
			} elseif ( preg_match( '/^\d+(\.\d+)?(px|rem|em|%)?$/', $val ) ) {
				$tokens['spacing'][ $slug ] = $val;
			}
		}
		return $tokens;
	}
}

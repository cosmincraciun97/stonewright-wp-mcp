<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/fse.read_global_styles
 *
 * Reads the user-level global styles (theme.json override) for the active theme.
 * Permission: can_view_design().
 *
 * @stonewright-status stable
 */
final class ReadGlobalStyles extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-read-global-styles';
	}

	public function label(): string {
		return __( 'Read global styles', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the user-level theme.json global styles post content as a decoded object.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'theme_json' => [ 'type' => 'object' ],
				'post_id'    => [ 'type' => 'integer' ],
			],
			'required' => [ 'theme_json', 'post_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
			return $this->error( 'theme_json_unavailable', __( 'theme.json resolver is not available.', 'stonewright' ) );
		}

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $post_id ) {
			return $this->error( 'no_user_global_styles', __( 'User global styles post is missing.', 'stonewright' ) );
		}

		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return $this->error( 'post_not_found', __( 'Global styles post could not be loaded.', 'stonewright' ) );
		}

		$raw = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $raw ) ) {
			$raw = [ 'version' => 3 ];
		}

		return [
			'theme_json' => $raw,
			'post_id'    => (int) $post_id,
		];
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\FSE;

use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\ThemeJson\CssGrantGate;
use Stonewright\WpMcp\ThemeJson\Validator;

/**
 * Shared strong envelope for user global-styles writes.
 *
 * Used by both stonewright/fse-write-global-styles and the compatibility
 * wrapper stonewright/fse-update-global-styles so there is one validator +
 * backup + persist path.
 */
final class GlobalStylesWriter {

	/**
	 * Validate, snapshot, and persist a theme.json payload to wp_global_styles.
	 *
	 * @param array<string, mixed> $theme_json
	 * @return array{post_id:int,snapshot_id:string}|\WP_Error
	 */
	public static function write( array $theme_json, string $custom_code_grant = '' ) {
		$canonical = Validator::validate( $theme_json );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$css_gate = CssGrantGate::assert( $canonical, $custom_code_grant );
		if ( $css_gate instanceof \WP_Error ) {
			return $css_gate;
		}

		if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
			return new \WP_Error(
				'stonewright_theme_json_unavailable',
				__( 'theme.json resolver is not available.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $post_id ) {
			return new \WP_Error(
				'stonewright_no_user_global_styles',
				__( 'User global styles post is missing.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$snapshot_id = Backup::snapshot_post( (int) $post_id );
		$result      = wp_update_post(
			[
				'ID'           => (int) $post_id,
				'post_content' => (string) wp_json_encode( $canonical ),
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'post_id'     => (int) $post_id,
			'snapshot_id' => $snapshot_id,
		];
	}

	/**
	 * Compose a full theme.json object from the legacy update-* merge/replace args.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compose_from_update_args( array $args ) {
		if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
			return new \WP_Error(
				'stonewright_theme_json_unavailable',
				__( 'theme.json resolver is not available.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}

		$user_cpt_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $user_cpt_id ) {
			return new \WP_Error(
				'stonewright_no_user_global_styles',
				__( 'User global styles post is missing.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$current = get_post( $user_cpt_id );
		$raw     = $current ? json_decode( (string) $current->post_content, true ) : [];
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
		$next = $raw;
		if ( 'replace' === $mode ) {
			$next = [
				'version'  => 3,
				'settings' => isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : ( $raw['settings'] ?? [] ),
				'styles'   => isset( $args['styles'] ) && is_array( $args['styles'] ) ? $args['styles'] : ( $raw['styles'] ?? [] ),
			];
		} else {
			$next['version'] = isset( $next['version'] ) ? (int) $next['version'] : 3;
			if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
				$next['settings'] = self::merge( (array) ( $raw['settings'] ?? [] ), $args['settings'] );
			}
			if ( isset( $args['styles'] ) && is_array( $args['styles'] ) ) {
				$next['styles'] = self::merge( (array) ( $raw['styles'] ?? [] ), $args['styles'] );
			}
		}

		if ( ! isset( $next['version'] ) ) {
			$next['version'] = 3;
		}

		return $next;
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $overlay
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $overlay ): array {
		foreach ( $overlay as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}

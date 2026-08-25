<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * Routes executable-code post types away from generic content abilities.
 */
final class ContentSurfacePolicy {

	public const ERROR_CODE    = 'stonewright_custom_code_provider_required';
	public const CONFLICT_CODE = 'stonewright_custom_code_post_type_ownership_conflict';
	public const NEXT_ABILITY  = 'stonewright-custom-code-provider-ops';

	/**
	 * @return true|\WP_Error
	 */
	public static function assert_generic_write_allowed( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return true;
		}

		$ownership = ProviderRegistry::post_type_ownership();
		if ( isset( $ownership['conflicts'][ $post_type ] ) ) {
			return new \WP_Error(
				self::CONFLICT_CODE,
				__( 'Multiple custom-code providers claim this post type. Generic content writes are blocked.', 'stonewright' ),
				[
					'status'       => 409,
					'post_type'    => $post_type,
					'providers'    => $ownership['conflicts'][ $post_type ],
					'next_ability' => self::NEXT_ABILITY,
					'retryable'    => false,
				]
			);
		}

		if ( isset( $ownership['owners'][ $post_type ] ) ) {
			return new \WP_Error(
				self::ERROR_CODE,
				__( 'This post type is owned by a custom-code provider. Use the provider pipeline instead of generic content abilities.', 'stonewright' ),
				[
					'status'       => 409,
					'post_type'    => $post_type,
					'provider'     => $ownership['owners'][ $post_type ],
					'next_ability' => self::NEXT_ABILITY,
					'retryable'    => false,
				]
			);
		}

		return true;
	}
}

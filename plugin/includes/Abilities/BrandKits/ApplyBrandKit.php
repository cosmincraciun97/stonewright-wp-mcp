<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\BrandKits;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Applies a brand kit conservatively to options, theme mods, and Elementor kit globals.
 *
 * Scope: option stonewright_active_brand_kit; theme mods for colors/fonts; Elementor
 * custom_colors + custom_typography on the active kit post when present (with backup).
 * Full Elementor system_colors / class manager replacement is out of scope.
 *
 * @stonewright-status stable
 */
final class ApplyBrandKit extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/brand-kit-apply';
	}

	public function label(): string {
		return __( 'Apply brand kit', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies a bundled brand kit to site options and theme mods, and merges custom colors/typography into the Elementor active kit when available (snapshot first). Does not fully replace Elementor system colors or global classes.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'brand_kit_id'       => [ 'type' => 'string' ],
				'id'                 => [ 'type' => 'string', 'description' => 'Alias for brand_kit_id.' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'brand_kit_id' => [ 'type' => 'string' ],
				'name'         => [ 'type' => 'string' ],
				'theme_mods'   => [ 'type' => 'array' ],
				'elementor'    => [ 'type' => 'object' ],
				'scope'        => [ 'type' => 'object' ],
			],
			'additionalProperties' => true,
			'required'   => [ 'ok', 'brand_kit_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify_args = array_filter(
					$args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$id = (string) ( $args['brand_kit_id'] ?? $args['id'] ?? '' );
				return BrandKit::apply( $id );
			}
		);
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Themes;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\ThemeChrome;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Reads Blocksy, Kadence, or GeneratePress global chrome when the theme API exists.
 *
 * @stonewright-status stable
 */
final class ThemeChromeGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-chrome-get';
	}

	public function label(): string {
		return __( 'Get theme chrome', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads live global colors, typography, header, and footer settings for Blocksy, Kadence Theme, or GeneratePress. Does not invent undocumented keys.', 'stonewright' );
	}

	public function category(): string {
		return 'themes';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'theme' ],
			'properties'           => [
				'theme' => [
					'type' => 'string',
					'enum' => ThemeChrome::THEMES,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'theme'      => [ 'type' => 'string' ],
				'active'     => [ 'type' => 'boolean' ],
				'version'    => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'colors'     => [ 'type' => 'object' ],
				'typography' => [ 'type' => 'object' ],
				'header'     => [ 'type' => 'object' ],
				'footer'     => [ 'type' => 'object' ],
				'writable'   => [ 'type' => 'array' ],
			],
			'required'   => [ 'theme', 'active', 'version', 'status', 'colors', 'typography', 'header', 'footer', 'writable' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts() || Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$theme = (string) ( $args['theme'] ?? '' );
		if ( ! in_array( $theme, ThemeChrome::THEMES, true ) ) {
			return $this->error( 'invalid_theme', __( 'Unknown theme chrome adapter.', 'stonewright' ) );
		}

		return ThemeChrome::read( $theme );
	}
}

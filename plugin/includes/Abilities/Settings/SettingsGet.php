<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Settings;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Reads allowlisted site settings. Never returns siteurl/home secrets.
 *
 * @stonewright-status stable
 */
final class SettingsGet extends AbilityKernel {

	public const ALLOWLIST = [
		'blogname',
		'blogdescription',
		'site_icon',
		'timezone_string',
		'date_format',
		'time_format',
		'start_of_week',
		'posts_per_page',
		'default_comment_status',
		'default_ping_status',
		'users_can_register',
		'default_role',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
	];

	public function name(): string {
		return 'stonewright/settings-get';
	}

	public function label(): string {
		return __( 'Settings: Get', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads allowlisted site settings. Never returns siteurl/home secrets.', 'stonewright' );
	}

	public function category(): string {
		return 'settings';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'keys' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'settings' => [ 'type' => 'object' ],
			],
			'required'             => [ 'settings' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$keys = isset( $args['keys'] ) && is_array( $args['keys'] )
					? array_map( 'strval', $args['keys'] )
					: self::ALLOWLIST;
				$out  = [];
				foreach ( $keys as $key ) {
					if ( ! in_array( $key, self::ALLOWLIST, true ) ) {
						continue;
					}
					$out[ $key ] = get_option( $key );
				}
				return [ 'settings' => $out ];
			}
		);
	}
}

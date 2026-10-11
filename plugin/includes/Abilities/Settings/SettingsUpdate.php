<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Settings;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\UserGuard;
/** @stonewright-status stable */
final class SettingsUpdate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/settings-update';
	}
	public function label(): string {
		return __( 'Settings: Update', 'stonewright' );
	}
	public function description(): string {
		return __( 'Updates allowlisted site settings. Every key is checked before anything is written, so a call never writes only part of its settings. Blocks siteurl/home. default_role refuses administrator and any role with administrative capabilities. The result lists the updated, unchanged and skipped keys.', 'stonewright' );
	}
	public function category(): string {
		return 'settings';
	}
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'settings'=>[ 'type'=>'object', 'additionalProperties'=>true ],
				'confirmation_token'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'settings' ],
		];
	}
	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'                 => 'object',
			'properties'           => [
				'updated'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Keys whose value changed.' ],
				'unchanged' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Keys that already had the requested value.' ],
				'skipped'   => [
					'type'        => 'array',
					'description' => 'Keys that were not written, each with the reason.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'key'    => [ 'type' => 'string' ],
							'reason' => [ 'type' => 'string' ],
						],
					],
				],
			],
			'required'             => [ 'updated' ],
		];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	/**
	 * Every key is checked here, before the token is spent and before anything is written. Returns the first problem.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function write_precondition_error( array $args ): ?\WP_Error {
		$settings = is_array( $args['settings'] ?? null ) ? $args['settings'] : [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ 'siteurl', 'home' ], true ) ) {
				return new \WP_Error( 'stonewright_settings_blocked_key', 'Updating siteurl/home is blocked.', [ 'key' => $key ] );
			}
			if ( ! in_array( $key, SettingsGet::ALLOWLIST, true ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) && null !== $value ) {
				return new \WP_Error( 'stonewright_settings_invalid_value', 'Setting values must be strings, numbers or booleans.', [ 'key' => $key ] );
			}
			if ( 'default_role' === $key ) {
				$denied = self::default_role_error( (string) $value );
				if ( null !== $denied ) {
					return $denied;
				}
			}
		}
		return null;
	}

	/**
	 * The default role is what every new account receives, so it cannot be a role that can administer the site.
	 */
	private static function default_role_error( string $role ): ?\WP_Error {
		$roles = wp_roles()->roles;
		if ( 'administrator' === $role || ! isset( $roles[ $role ] ) ) {
			return new \WP_Error(
				'stonewright_settings_invalid_value',
				'default_role must be an existing role without administrative capabilities.',
				[ 'key' => 'default_role' ]
			);
		}
		$caps = is_array( $roles[ $role ]['capabilities'] ?? null ) ? $roles[ $role ]['capabilities'] : [];
		foreach ( UserGuard::ELEVATED_CAPS as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				return new \WP_Error(
					'stonewright_settings_invalid_value',
					'default_role must be an existing role without administrative capabilities.',
					[ 'key' => 'default_role' ]
				);
			}
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$settings  = is_array( $args['settings'] ?? null ) ? $args['settings'] : [];
				$updated   = [];
				$unchanged = [];
				$skipped   = [];
				$previous  = [];
				foreach ( $settings as $key => $value ) {
					$key = (string) $key;
					if ( ! in_array( $key, SettingsGet::ALLOWLIST, true ) ) {
						$skipped[] = [ 'key' => $key, 'reason' => 'not_allowlisted' ];
						continue;
					}
					$missing = new \stdClass();
					$old     = get_option( $key, $missing );
					if ( $missing !== $old && self::same( $old, $value ) ) {
						$unchanged[] = $key;
						continue;
					}
					update_option( $key, $value );
					if ( ! self::same( get_option( $key, $missing ), $value ) ) {
						foreach ( array_reverse( $previous, true ) as $written => $was ) {
							if ( $missing === $was ) {
								delete_option( (string) $written );
							} else {
								update_option( (string) $written, $was );
							}
						}
						return new \WP_Error(
							'stonewright_settings_write_failed',
							'A setting could not be stored; the settings written before it were restored.',
							[ 'key' => $key, 'restored' => array_keys( $previous ) ]
						);
					}
					$previous[ $key ] = $old;
					$updated[]        = $key;
				}
				return [
					'updated'   => $updated,
					'unchanged' => $unchanged,
					'skipped'   => $skipped,
				];
			}
		);
	}

	/**
	 * Whether a stored option value is the value that was asked for. Options come back as strings.
	 */
	private static function same( mixed $stored, mixed $wanted ): bool {
		if ( is_scalar( $stored ) || null === $stored ) {
			return is_scalar( $wanted ) || null === $wanted
				? (string) $stored === (string) $wanted
				: false;
		}
		return $stored == $wanted; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Structural comparison of array options.
	}
}

<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\UserGuard;
/** @stonewright-status stable */
final class UserUpdate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/user-update';
	}
	public function label(): string {
		return __( 'User: Update', 'stonewright' );
	}
	public function description(): string {
		return __( 'Updates a WordPress user (only provided fields). Needs permission to edit that user, and to grant the role when role is set. Refuses a role change that would leave the site without an administrator.', 'stonewright' );
	}
	public function category(): string {
		return 'users';
	}
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'confirmation_token' => [ 'type' => 'string' ],
				'id'=>[ 'type'=>'integer', 'minimum'=>1 ],
				'user_email'=>[ 'type'=>'string' ],
				'display_name'=>[ 'type'=>'string' ],
				'role'=>[ 'type'=>'string' ],
				'user_pass'=>[ 'type'=>'string', 'minLength'=>12 ],
			],
			'required'=>[ 'id' ],
		];
	}
	public function output_schema(): array {
		return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_users() ? ( self::authorization_error( $args ) ?? true ) : false;
	}

	/**
	 * The per-account checks: the current user may edit this account and, when a role is set, grant that role.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function authorization_error( array $args ): ?\WP_Error {
		$id     = (int) ( $args['id'] ?? 0 );
		$denied = UserGuard::target_error( $id, 'edit_user' );
		if ( null !== $denied ) {
			return $denied;
		}
		if ( isset( $args['role'] ) ) {
			return UserGuard::role_error( (string) $args['role'], $id ) ?? UserGuard::demotion_error( $id, (string) $args['role'] );
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( ! Permissions::edit_users() ) {
					return new \WP_Error( 'stonewright_user_target_forbidden', __( 'You are not allowed to edit users.', 'stonewright' ), [ 'status' => 403 ] );
				}
				$denied = self::authorization_error( $args );
				if ( null !== $denied ) {
					return $denied;
				}
				$token_error = $this->confirmation_token_error( $args, $args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$data = [ 'ID' => (int) $args['id'] ];
				foreach ( [ 'user_email', 'display_name', 'role', 'user_pass' ] as $k ) {
					if ( isset( $args[ $k ] ) ) {
						$data[ $k ] = (string) $args[ $k ];
					}
				}
				$r = wp_update_user( $data );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return [ 'id' => (int) $r ];
			}
		);
	}
}

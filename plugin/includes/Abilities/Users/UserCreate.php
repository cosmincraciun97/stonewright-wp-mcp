<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\UserGuard;
/** @stonewright-status stable */
final class UserCreate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/user-create';
	}
	public function label(): string {
		return __( 'User: Create', 'stonewright' );
	}
	public function description(): string {
		return __( 'Creates a WordPress user. Never returns the password. Setting a role needs permission to grant that role.', 'stonewright' );
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
				'user_login'=>[ 'type'=>'string' ],
				'user_email'=>[ 'type'=>'string' ],
				'user_pass'=>[ 'type'=>'string', 'minLength'=>12 ],
				'role'=>[ 'type'=>'string' ],
				'display_name'=>[ 'type'=>'string' ],
			],
			'required'=>[ 'user_login', 'user_email', 'user_pass' ],
		];
	}
	public function output_schema(): array {
		return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'id'=>[ 'type'=>'integer' ] ], 'required'=>[ 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::create_users() ? ( self::authorization_error( $args ) ?? true ) : false;
	}

	/**
	 * Setting a role needs permission to grant that role.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function authorization_error( array $args ): ?\WP_Error {
		return isset( $args['role'] ) ? UserGuard::role_error( (string) $args['role'] ) : null;
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( ! Permissions::create_users() ) {
					return new \WP_Error( 'stonewright_user_target_forbidden', __( 'You are not allowed to create users.', 'stonewright' ), [ 'status' => 403 ] );
				}
				$denied = self::authorization_error( $args );
				if ( null !== $denied ) {
					return $denied;
				}
				$token_error = $this->confirmation_token_error( $args, $args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$data = [
					'user_login' => (string) $args['user_login'],
					'user_email' => (string) $args['user_email'],
					'user_pass'  => (string) $args['user_pass'],
				];
				if ( isset( $args['role'] ) ) {
					$data['role'] = (string) $args['role'];
				}
				if ( isset( $args['display_name'] ) ) {
					$data['display_name'] = (string) $args['display_name'];
				}
				$id = wp_insert_user( $data );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return [ 'id' => (int) $id ];
			}
		);
	}
}

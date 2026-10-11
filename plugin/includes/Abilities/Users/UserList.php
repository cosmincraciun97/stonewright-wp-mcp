<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Users;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class UserList extends AbilityKernel {
	public function name(): string {
		return 'stonewright/user-list';
	}
	public function label(): string {
		return __( 'User: List', 'stonewright' );
	}
	public function description(): string {
		return __( 'Lists WordPress users, one page at a time. total counts every match; use offset or page to continue.', 'stonewright' );
	}
	public function category(): string {
		return 'users';
	}
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'search'=>[ 'type'=>'string' ],
				'role'=>[ 'type'=>'string' ],
				'number'=>[ 'type'=>'integer', 'minimum'=>1, 'maximum'=>100, 'default'=>20 ],
				'offset'=>[ 'type'=>'integer', 'minimum'=>0, 'description'=>'Users to skip. Takes precedence over page.' ],
				'page'=>[ 'type'=>'integer', 'minimum'=>1, 'description'=>'1-based page of number users.' ],
			],
		];
	}
	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'                 => 'object',
			'properties'           => [
				'items'    => [ 'type' => 'array' ],
				'total'    => [ 'type' => 'integer', 'description' => 'Every user that matches, not only this page.' ],
				'offset'   => [ 'type' => 'integer' ],
				'number'   => [ 'type' => 'integer' ],
				'has_more' => [ 'type' => 'boolean' ],
			],
			'required'             => [ 'items', 'total' ],
		];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::list_users();
	}
	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			static function ( array $args ) {
				$number = max( 1, min( (int) ( $args['number'] ?? 20 ), 100 ) );
				$offset = isset( $args['offset'] )
					? max( 0, (int) $args['offset'] )
					: max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $number );
				$q      = [
					'number'      => $number,
					'offset'      => $offset,
					'count_total' => true,
				];
				if ( isset( $args['search'] ) ) {
					$q['search'] = (string) $args['search'];
				}
				if ( isset( $args['role'] ) ) {
					$q['role'] = (string) $args['role'];
				}
				$query = new \WP_User_Query( $q );
				$items = [];
				foreach ( (array) $query->get_results() as $u ) {
					$items[] = [
						'id'           => (int) $u->ID,
						'login'        => (string) $u->user_login,
						'display_name' => (string) $u->display_name,
						'roles'        => array_values( (array) $u->roles ),
						'email'        => (string) $u->user_email,
					];
				}
				$total = (int) $query->get_total();
				return [
					'items'    => $items,
					'total'    => $total,
					'offset'   => $offset,
					'number'   => $number,
					'has_more' => $offset + count( $items ) < $total,
				];
			}
		);
	}
}

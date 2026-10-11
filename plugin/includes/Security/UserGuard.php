<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Per-account permission checks for the user abilities.
 *
 * The generic capabilities (`edit_users`, `delete_users`, `promote_users`) say that a user may manage
 * accounts in general. They do not say which account, nor which role may be granted. These checks add the
 * rules wp-admin applies per account: the meta capabilities for the target, the roles the current user may
 * grant, and a ceiling so that nobody changes, deletes or promotes an account to hold capabilities they do
 * not hold themselves.
 */
final class UserGuard {

	/**
	 * Capabilities that let a role administer the site, its users, its plugins. A role that holds one of them is
	 * not safe as the default role of new accounts.
	 *
	 * @var list<string>
	 */
	public const ELEVATED_CAPS = [
		'manage_options',
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'edit_plugins',
		'install_plugins',
	];

	/**
	 * Capabilities that put an account, or a role, above a user who manages users. An account or a role that holds
	 * one of them can only be changed, deleted or granted by a user who holds it too. Plugin and file capabilities
	 * are left out: hosts withdraw them for everybody (`DISALLOW_FILE_MODS`) and multisite withdraws them from site
	 * administrators, so they cannot tell who outranks whom.
	 *
	 * @var list<string>
	 */
	public const CEILING_CAPS = [
		'manage_options',
		'promote_users',
		'edit_users',
	];

	/**
	 * The roles the current user may grant, as wp-admin offers them. Empty when the list is unavailable, so a
	 * missing list refuses every role.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function editable_roles(): array {
		if ( ! function_exists( 'get_editable_roles' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( ! function_exists( 'get_editable_roles' ) ) {
			return [];
		}
		return (array) get_editable_roles();
	}

	/**
	 * Whether the current user may grant $role: they can promote users, the role is one they may assign, and
	 * the role carries no capability of the ceiling they lack. An empty role (clear the roles) needs only the
	 * promote capability.
	 *
	 * @param int $user_id The account that receives the role, or 0 when the account does not exist yet.
	 */
	public static function role_error( string $role, int $user_id = 0 ): ?\WP_Error {
		if ( ! current_user_can( 'promote_users' ) || ( $user_id > 0 && ! current_user_can( 'promote_user', $user_id ) ) ) {
			return self::forbidden( 'stonewright_user_role_forbidden', __( 'You are not allowed to change user roles.', 'stonewright' ) );
		}
		if ( '' === $role ) {
			return null;
		}
		$editable = self::editable_roles();
		if ( ! isset( $editable[ $role ] ) ) {
			return self::forbidden( 'stonewright_user_role_forbidden', __( 'You are not allowed to give users that role.', 'stonewright' ) );
		}
		$role_caps = is_array( $editable[ $role ]['capabilities'] ?? null ) ? $editable[ $role ]['capabilities'] : [];
		foreach ( self::CEILING_CAPS as $cap ) {
			if ( ! empty( $role_caps[ $cap ] ) && ! current_user_can( $cap ) ) {
				return self::forbidden( 'stonewright_user_role_forbidden', __( 'You are not allowed to give users a role with capabilities you do not have.', 'stonewright' ) );
			}
		}
		return null;
	}

	/**
	 * Whether the current user may act on the account $user_id with the meta capability $capability
	 * (`edit_user` or `delete_user`).
	 */
	public static function target_error( int $user_id, string $capability ): ?\WP_Error {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( false === $user ) {
			return new \WP_Error( 'stonewright_user_not_found', __( 'User not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && is_super_admin( $user_id ) && ! is_super_admin() ) {
			return self::forbidden( 'stonewright_user_target_forbidden', __( 'Only a super admin may manage a super admin account.', 'stonewright' ) );
		}
		if ( ! current_user_can( $capability, $user_id ) ) {
			return self::forbidden( 'stonewright_user_target_forbidden', __( 'You are not allowed to manage this user.', 'stonewright' ) );
		}
		foreach ( self::CEILING_CAPS as $cap ) {
			if ( user_can( $user, $cap ) && ! current_user_can( $cap ) ) {
				return self::forbidden( 'stonewright_user_target_forbidden', __( 'This user holds capabilities you do not have, so you cannot manage the account.', 'stonewright' ) );
			}
		}
		return null;
	}

	/**
	 * The refusal for deleting the only administrator that is left.
	 */
	public static function last_administrator_error( int $user_id ): ?\WP_Error {
		$user = get_user_by( 'id', $user_id );
		if ( false === $user || ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return null;
		}
		$others = get_users(
			[
				'role'    => 'administrator',
				'exclude' => [ $user_id ],
				'number'  => 1,
				'fields'  => 'ID',
			]
		);
		if ( [] !== (array) $others ) {
			return null;
		}
		return new \WP_Error(
			'stonewright_user_last_administrator',
			__( 'The last administrator cannot be deleted.', 'stonewright' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * The refusal for a role change that would leave the site without an administrator. The role counts per site
	 * on multisite. A super admin target, or a super admin acting, is outside this check: the network keeps a
	 * user who can administer the site.
	 */
	public static function demotion_error( int $user_id, string $new_role ): ?\WP_Error {
		if ( 'administrator' === $new_role ) {
			return null;
		}
		if ( is_multisite() && ( is_super_admin( $user_id ) || is_super_admin() ) ) {
			return null;
		}
		return self::last_administrator_error( $user_id );
	}

	/**
	 * Whether $reassign is a user that can take over the content of $user_id.
	 */
	public static function reassign_error( int $user_id, int $reassign ): ?\WP_Error {
		if ( $reassign < 1 || $reassign === $user_id || false === get_user_by( 'id', $reassign ) ) {
			return new \WP_Error(
				'stonewright_user_reassign_invalid',
				__( 'reassign must be an existing user other than the one being deleted.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		return null;
	}

	private static function forbidden( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 403 ] );
	}
}

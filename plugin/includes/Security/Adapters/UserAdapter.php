<?php
/**
 * The user family in the change ledger: field-level images of users, and never a credential.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\Permissions;

/**
 * The image of a user holds the account fields (login, nicename, email, url, display name, registration
 * date), a short list of plain profile fields (first and last name, nickname, bio, locale), the roles, and
 * the capabilities the user has on top of its roles. Nothing else is read: not the password hash, not the
 * activation key, not the session tokens, not the application passwords, not any other user meta. A profile
 * field whose name ChangeImage treats as a credential is left out as well.
 *
 * A change of the password is recorded as an event on the resource type user_password, and an application
 * password that was created or revoked as an event on application_password. Both are credentials to
 * ChangeImage, so the row has no image and no hash, says secret_resource, and is not restorable. The
 * password itself is never read from the call: only that the call carried one.
 *
 * A restore writes the fields, the roles and the capabilities back with wp_update_user() and the role
 * functions, never the password, and sends no email about the change. A user that was deleted is created
 * again with wp_insert_user(): it has a new id and a new random password nobody knows, and its sessions and
 * application passwords are gone, so the row of a delete says it is partly restorable. The undo of a user
 * that a change created deletes it, unless it is the user who asks or has written content.
 */
final class UserAdapter extends FamilyAdapter {

	public const FAMILY = 'user';

	public const RECIPE = 'user';

	protected const ABILITIES = [
		'stonewright/user-create',
		'stonewright/user-update',
		'stonewright/user-delete',
		'stonewright/user-app-passwords',
	];

	private const FIELDS = [ 'user_login', 'user_nicename', 'user_email', 'user_url', 'display_name', 'user_registered' ];

	private const PROFILE = [ 'first_name', 'last_name', 'nickname', 'description', 'locale' ];

	public static function types(): array {
		return [ 'user', 'user_password', 'application_password' ];
	}

	public static function ledger_family( string $type ): string {
		return self::FAMILY;
	}

	public static function image( string $type, string $id ): ?array {
		if ( 'user' !== $type || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
			return null;
		}
		$user = get_user_by( 'id', (int) $id );
		if ( ! is_object( $user ) ) {
			return null;
		}

		$fields = [];
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = (string) ( $user->{$field} ?? '' );
		}
		$held  = get_object_vars( $user );
		$roles = array_values( array_map( 'strval', array_filter( (array) ( $held['roles'] ?? [] ), 'is_string' ) ) );
		$caps  = [];
		foreach ( (array) ( $held['caps'] ?? [] ) as $name => $granted ) {
			if ( is_string( $name ) && ! in_array( $name, $roles, true ) ) {
				$caps[ $name ] = (bool) $granted;
			}
		}
		$profile = [];
		foreach ( self::PROFILE as $key ) {
			$value = get_user_meta( (int) $id, $key, true );
			if ( is_string( $value ) && '' !== $value && ! ChangeImage::is_secret_field( $key ) ) {
				$profile[ $key ] = $value;
			}
		}
		ksort( $fields, SORT_STRING );
		ksort( $caps, SORT_STRING );
		ksort( $profile, SORT_STRING );

		$image = [
			'v'       => self::IMAGE_VERSION,
			'fields'  => $fields,
			'profile' => $profile,
			'roles'   => $roles,
			'caps'    => $caps,
		];
		ksort( $image, SORT_STRING );
		return $image;
	}

	public static function watch( string $ability, array $args ): array {
		if ( ! in_array( $ability, [ 'stonewright/user-update', 'stonewright/user-delete' ], true ) ) {
			return [];
		}
		$id = isset( $args['id'] ) && is_numeric( $args['id'] ) ? (int) $args['id'] : 0;
		return $id > 0 ? [ [ 'type' => 'user', 'id' => (string) $id, 'before' => self::image( 'user', (string) $id ) ] ] : [];
	}

	public static function context( string $ability, array $args ): array {
		if ( 'stonewright/user-update' === $ability ) {
			return [
				'user'     => isset( $args['id'] ) && is_numeric( $args['id'] ) ? (int) $args['id'] : 0,
				'password' => isset( $args['user_pass'] ) && is_string( $args['user_pass'] ) && '' !== $args['user_pass'],
			];
		}
		if ( 'stonewright/user-app-passwords' === $ability ) {
			$action = isset( $args['action'] ) && is_string( $args['action'] ) ? $args['action'] : '';
			return [
				'action'  => in_array( $action, [ 'create', 'revoke' ], true ) ? $action : '',
				'user'    => isset( $args['user_id'] ) && is_numeric( $args['user_id'] ) ? (int) $args['user_id'] : 0,
				'name'    => isset( $args['name'] ) && is_string( $args['name'] ) ? FamilyLedger::clip( $args['name'], 60 ) : '',
				'uuid'    => isset( $args['uuid'] ) && is_string( $args['uuid'] ) ? self::uuid( $args['uuid'] ) : '',
			];
		}
		return [];
	}

	public static function discover( string $ability, array $context, mixed $result ): array {
		if ( 'stonewright/user-create' !== $ability || ! is_array( $result ) ) {
			return [];
		}
		$id = isset( $result['id'] ) && is_numeric( $result['id'] ) ? (int) $result['id'] : 0;
		return $id > 0 ? [ [ 'type' => 'user', 'id' => (string) $id ] ] : [];
	}

	public static function events( string $ability, array $context, mixed $result ): array {
		if ( ! FamilyLedger::succeeded( $result ) ) {
			return [];
		}
		if ( 'stonewright/user-update' === $ability && true === ( $context['password'] ?? false ) && (int) ( $context['user'] ?? 0 ) > 0 ) {
			$user = (int) $context['user'];
			return [
				[
					'type'    => 'user_password',
					'id'      => (string) $user,
					'summary' => 'Password changed for user ' . $user . '; the password is not recorded and cannot be restored',
					'reason'  => '',
					'failed'  => false,
				],
			];
		}
		if ( 'stonewright/user-app-passwords' === $ability && in_array( $context['action'] ?? '', [ 'create', 'revoke' ], true ) && is_array( $result ) ) {
			$user = (int) ( $context['user'] ?? 0 );
			if ( $user < 1 ) {
				return [];
			}
			// Only the identifier of the password is read from the result: never the password.
			$uuid = isset( $result['uuid'] ) && is_string( $result['uuid'] ) ? self::uuid( $result['uuid'] ) : (string) ( $context['uuid'] ?? '' );
			$name = 'create' === $context['action'] ? (string) ( $context['name'] ?? '' ) : '';
			$text = 'create' === $context['action'] ? 'Application password created' : 'Application password revoked';
			return [
				[
					'type'    => 'application_password',
					'id'      => $user . ( '' === $uuid ? '' : '/' . $uuid ),
					'summary' => $text . ' for user ' . $user . ( '' === $name ? '' : ': ' . $name ),
					'reason'  => '',
					'failed'  => false,
				],
			];
		}
		return [];
	}

	public static function limits( string $ability, string $type, ?array $before, ?array $after ): array {
		if ( 'user' === $type && null !== $before && null === $after ) {
			return [ 'no password', 'no sessions or application passwords', 'new id' ];
		}
		return [];
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		$login = is_array( $image['fields'] ?? null ) ? (string) ( $image['fields']['user_login'] ?? '' ) : '';
		return 'user ' . ( '' === $login ? '#' . $id : FamilyLedger::clip( $login, 40 ) );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return match ( $operation ) {
			'create' => Permissions::create_users(),
			'delete' => Permissions::delete_users(),
			default  => Permissions::edit_users(),
		};
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		if ( 'user' !== $type ) {
			return self::refused( 'not_restorable' );
		}
		if ( null === $target ) {
			return self::remove( (int) $id );
		}
		// Giving a role back is promoting a user, which takes its own capability.
		$wanted = array_values( array_map( 'strval', (array) ( $target['roles'] ?? [] ) ) );
		if ( ( null === $live ? [] !== $wanted : $wanted !== array_values( array_map( 'strval', (array) ( $live['roles'] ?? [] ) ) ) ) && ! Permissions::promote_users() ) {
			return self::refused( 'permission_denied' );
		}

		// A restore says nothing to the user by email.
		$quiet = static fn (): bool => false;
		add_filter( 'send_email_change_email', $quiet );
		add_filter( 'send_password_change_email', $quiet );
		try {
			return null === $live ? self::recreate( $target ) : self::update( (int) $id, $target );
		} finally {
			remove_filter( 'send_email_change_email', $quiet );
			remove_filter( 'send_password_change_email', $quiet );
		}
	}

	/**
	 * @param array<string, mixed> $target
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function update( int $id, array $target ): array {
		$data = [ 'ID' => $id ];
		foreach ( self::FIELDS as $field ) {
			// The login of an account cannot be changed.
			if ( 'user_login' !== $field && isset( $target['fields'][ $field ] ) ) {
				$data[ $field ] = (string) $target['fields'][ $field ];
			}
		}
		$roles = self::known_roles( (array) ( $target['roles'] ?? [] ) );
		$data['role'] = $roles[0] ?? '';
		$written      = wp_update_user( wp_slash( $data ) );
		if ( $written instanceof \WP_Error ) {
			return self::refused( self::short_code( $written ) );
		}
		self::write_extras( $id, $target, $roles );

		$differences = self::differences( $target, self::image( 'user', (string) $id ), [ 'fields.user_login' ] );
		return self::applied( [] === $differences, [] === $differences ? 'restored' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ), (string) $id );
	}

	/**
	 * @param array<string, mixed> $target
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function recreate( array $target ): array {
		$data = [];
		foreach ( self::FIELDS as $field ) {
			if ( isset( $target['fields'][ $field ] ) && '' !== (string) $target['fields'][ $field ] ) {
				$data[ $field ] = (string) $target['fields'][ $field ];
			}
		}
		$roles = self::known_roles( (array) ( $target['roles'] ?? [] ) );
		if ( [] !== $roles ) {
			$data['role'] = $roles[0];
		}
		// A password nobody knows: the person resets it. The old one is not recorded.
		$data['user_pass'] = wp_generate_password( 32, true, true );
		$created           = wp_insert_user( wp_slash( $data ) );
		if ( $created instanceof \WP_Error ) {
			return self::refused( self::short_code( $created ) );
		}
		$new = (int) $created;
		self::write_extras( $new, $target, $roles );

		$differences = self::differences( $target, self::image( 'user', (string) $new ) );
		return self::applied(
			[] === $differences,
			[] === $differences ? 'recreated' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ),
			(string) $new
		);
	}

	/**
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function remove( int $id ): array {
		if ( $id === (int) get_current_user_id() ) {
			return self::refused( 'current_user' );
		}
		if ( self::has_content( $id ) ) {
			return self::refused( 'user_has_content' );
		}
		if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) ) {
			require_once constant( 'ABSPATH' ) . 'wp-admin/includes/user.php';
		}
		$deleted = wp_delete_user( $id );
		return self::applied( false !== $deleted && null === self::image( 'user', (string) $id ), 'removed' );
	}

	/**
	 * Whether the user is the author of anything, drafts and the trash included: deleting the user without a
	 * new owner would delete it.
	 */
	private static function has_content( int $id ): bool {
		$types = function_exists( 'get_post_types' ) ? array_values( (array) get_post_types() ) : [];
		$types = [] === $types ? [ 'post', 'page' ] : $types;
		if ( function_exists( 'get_posts' ) ) {
			$found = get_posts(
				[
					'author'           => $id,
					'post_type'        => $types,
					'post_status'      => [ 'publish', 'pending', 'draft', 'future', 'private', 'trash', 'inherit' ],
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				]
			);
			if ( is_array( $found ) && [] !== $found ) {
				return true;
			}
		}
		return (int) count_user_posts( $id, $types ) > 0;
	}

	/**
	 * The roles that still exist; a role that was deleted since cannot be given again.
	 *
	 * @param array<mixed> $roles
	 * @return list<string>
	 */
	private static function known_roles( array $roles ): array {
		$out = [];
		foreach ( $roles as $role ) {
			if ( is_string( $role ) && '' !== $role && ( ! function_exists( 'get_role' ) || null !== get_role( $role ) ) ) {
				$out[] = $role;
			}
		}
		return $out;
	}

	/**
	 * The roles after the first, the capabilities of the user on top of its roles, and the profile fields.
	 *
	 * @param array<string, mixed> $target
	 * @param list<string>         $roles
	 */
	private static function write_extras( int $id, array $target, array $roles ): void {
		$user = get_user_by( 'id', $id );
		if ( is_object( $user ) ) {
			foreach ( array_slice( $roles, 1 ) as $role ) {
				if ( method_exists( $user, 'add_role' ) ) {
					$user->add_role( $role );
				}
			}
			$wanted = is_array( $target['caps'] ?? null ) ? $target['caps'] : [];
			$held = get_object_vars( $user );
			foreach ( array_keys( (array) ( $held['caps'] ?? [] ) ) as $name ) {
				if ( is_string( $name ) && ! in_array( $name, (array) ( $held['roles'] ?? [] ), true ) && ! array_key_exists( $name, $wanted ) && method_exists( $user, 'remove_cap' ) ) {
					$user->remove_cap( $name );
				}
			}
			foreach ( $wanted as $name => $granted ) {
				if ( is_string( $name ) && method_exists( $user, 'add_cap' ) ) {
					$user->add_cap( $name, (bool) $granted );
				}
			}
		}
		$profile = is_array( $target['profile'] ?? null ) ? $target['profile'] : [];
		foreach ( self::PROFILE as $key ) {
			update_user_meta( $id, $key, isset( $profile[ $key ] ) && is_string( $profile[ $key ] ) ? $profile[ $key ] : '' );
		}
	}

	private static function uuid( string $text ): string {
		return 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/D', $text ) ? $text : '';
	}
}

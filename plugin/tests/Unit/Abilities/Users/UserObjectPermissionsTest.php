<?php
/**
 * Per-target permission checks of the user abilities: who may edit, promote, delete or list which user.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Users;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Users\UserCreate;
use Stonewright\WpMcp\Abilities\Users\UserDelete;
use Stonewright\WpMcp\Abilities\Users\UserList;
use Stonewright\WpMcp\Abilities\Users\UserUpdate;

/**
 * @covers \Stonewright\WpMcp\Abilities\Users\UserUpdate
 * @covers \Stonewright\WpMcp\Abilities\Users\UserCreate
 * @covers \Stonewright\WpMcp\Abilities\Users\UserDelete
 * @covers \Stonewright\WpMcp\Abilities\Users\UserList
 * @covers \Stonewright\WpMcp\Security\UserGuard
 */
final class UserObjectPermissionsTest extends TestCase {

	/** The acting user. */
	private const ACTOR = 5;

	/** An administrator account. */
	private const ADMIN = 1;

	/** An ordinary editor account. */
	private const EDITOR = 20;

	/** Stored password values that the tests check stay as they are. */
	private const ADMIN_STORED  = 'stored-value-admin';
	private const EDITOR_STORED = 'stored-value-editor';

	/** A new value for the password field of a call. */
	private const NEW_SECRET = 'a-brand-new-passphrase';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = self::ACTOR;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [
			self::ADMIN  => [ 'manage_options' => true, 'promote_users' => true, 'edit_users' => true, 'delete_users' => true, 'create_users' => true ],
			self::EDITOR => [ 'edit_posts' => true, 'read' => true ],
		];
		$GLOBALS['stonewright_test_users']           = [
			self::ADMIN  => [ 'ID' => self::ADMIN, 'user_login' => 'admin', 'user_email' => 'admin@example.test', 'display_name' => 'Admin', 'roles' => [ 'administrator' ], 'user_pass' => self::ADMIN_STORED ],
			self::EDITOR => [ 'ID' => self::EDITOR, 'user_login' => 'ed', 'user_email' => 'ed@example.test', 'display_name' => 'Ed', 'roles' => [ 'editor' ], 'user_pass' => self::EDITOR_STORED ],
			self::ACTOR  => [ 'ID' => self::ACTOR, 'user_login' => 'actor', 'user_email' => 'actor@example.test', 'display_name' => 'Actor', 'roles' => [ 'editor' ] ],
		];
		$GLOBALS['stonewright_test_missing_user_ids'] = [];
		$GLOBALS['stonewright_test_super_admins']     = [];
		$GLOBALS['stonewright_test_is_multisite']     = false;
		$GLOBALS['stonewright_test_next_user_id']     = 100;
		unset( $GLOBALS['stonewright_test_editable_roles'], $GLOBALS['stonewright_test_user_can_callback'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_editable_roles'] );
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_user_caps_by_id']  = [];
		$GLOBALS['stonewright_test_users']            = [];
		$GLOBALS['stonewright_test_super_admins']     = [];
		$GLOBALS['stonewright_test_is_multisite']     = false;
		$GLOBALS['stonewright_test_current_user_id']  = 0;
		$GLOBALS['stonewright_test_options']          = [];
	}

	/**
	 * Gives the acting user capabilities. Meta capabilities (edit_user, delete_user, promote_user) are granted
	 * as a plain capability, and $denied lists the "cap:id" pairs that WordPress would refuse for one target.
	 *
	 * @param list<string> $caps
	 * @param list<string> $denied
	 */
	private function actor_can( array $caps, array $denied = [] ): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( $caps, $denied ): bool {
			if ( isset( $args[0] ) && in_array( $cap . ':' . (int) $args[0], $denied, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	private function assert_refused( mixed $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( $result->get_error_code(), [ 'stonewright_user_target_forbidden', 'stonewright_user_role_forbidden' ], (string) $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	// -----------------------------------------------------------------------
	// user-update
	// -----------------------------------------------------------------------

	public function test_update_refuses_promoting_someone_to_administrator_without_the_promote_capability(): void {
		$this->actor_can( [ 'edit_users', 'edit_user' ] );
		$args = [ 'id' => self::EDITOR, 'role' => 'administrator' ];

		$this->assert_refused( ( new UserUpdate() )->permission_callback( $args ) );
		$this->assert_refused( ( new UserUpdate() )->execute( $args ) );
		$this->assertSame( [ 'editor' ], $GLOBALS['stonewright_test_users'][ self::EDITOR ]['roles'], 'The role is untouched.' );
	}

	public function test_update_refuses_a_role_that_is_not_in_the_editable_roles_of_the_current_user(): void {
		$this->actor_can( [ 'edit_users', 'edit_user', 'promote_users', 'promote_user' ] );
		$GLOBALS['stonewright_test_editable_roles'] = [
			'author'     => [ 'name' => 'Author', 'capabilities' => [ 'edit_posts' => true ] ],
			'subscriber' => [ 'name' => 'Subscriber', 'capabilities' => [ 'read' => true ] ],
		];

		$this->assert_refused( ( new UserUpdate() )->execute( [ 'id' => self::EDITOR, 'role' => 'administrator' ] ) );
		$this->assert_refused( ( new UserUpdate() )->execute( [ 'id' => self::EDITOR, 'role' => 'no_such_role' ] ) );
		$this->assertSame( [ 'editor' ], $GLOBALS['stonewright_test_users'][ self::EDITOR ]['roles'] );
	}

	public function test_update_grants_an_editable_role_to_an_editable_user(): void {
		$this->actor_can( [ 'edit_users', 'edit_user', 'promote_users', 'promote_user' ] );
		$GLOBALS['stonewright_test_editable_roles'] = [
			'author' => [ 'name' => 'Author', 'capabilities' => [ 'edit_posts' => true ] ],
		];
		$args = [ 'id' => self::EDITOR, 'role' => 'author', 'display_name' => 'Ed B' ];

		$this->assertTrue( ( new UserUpdate() )->permission_callback( $args ) );
		$result = ( new UserUpdate() )->execute( $args );

		$this->assertSame( [ 'id' => self::EDITOR ], $result );
		$this->assertSame( [ 'author' ], $GLOBALS['stonewright_test_users'][ self::EDITOR ]['roles'] );
	}

	public function test_update_refuses_resetting_the_password_of_a_user_the_current_user_cannot_edit(): void {
		$this->actor_can( [ 'edit_users', 'edit_user' ], [ 'edit_user:' . self::ADMIN ] );
		$args = [ 'id' => self::ADMIN, 'user_pass' => self::NEW_SECRET ];

		$this->assert_refused( ( new UserUpdate() )->permission_callback( $args ) );
		$this->assert_refused( ( new UserUpdate() )->execute( $args ) );
		$this->assertSame( self::ADMIN_STORED, $GLOBALS['stonewright_test_users'][ self::ADMIN ]['user_pass'] );
	}

	public function test_update_refuses_a_user_who_holds_elevated_capabilities_the_current_user_lacks(): void {
		// The generic edit_user check passes, but the account is an administrator and the actor cannot manage options.
		$this->actor_can( [ 'edit_users', 'edit_user' ] );
		$args = [ 'id' => self::ADMIN, 'user_email' => 'changed@example.test' ];

		$this->assert_refused( ( new UserUpdate() )->execute( $args ) );
		$this->assertSame( 'admin@example.test', $GLOBALS['stonewright_test_users'][ self::ADMIN ]['user_email'] );
	}

	public function test_update_lets_an_administrator_edit_an_administrator(): void {
		$this->actor_can( [ 'edit_users', 'edit_user', 'manage_options', 'promote_users', 'promote_user', 'delete_users', 'create_users', 'edit_plugins', 'install_plugins' ] );

		$result = ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'user_email' => 'changed@example.test' ] );

		$this->assertSame( [ 'id' => self::ADMIN ], $result );
	}

	public function test_update_does_not_compare_capabilities_that_a_host_or_multisite_withdraws(): void {
		// A site administrator on multisite has no create_users, delete_users or plugin capabilities, and may still manage another administrator.
		$this->actor_can( [ 'edit_users', 'edit_user', 'manage_options', 'promote_users', 'promote_user' ] );

		$result = ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'administrator' ] );

		$this->assertSame( [ 'id' => self::ADMIN ], $result );
	}

	public function test_update_refuses_a_super_admin_target_for_a_site_administrator_on_multisite(): void {
		$GLOBALS['stonewright_test_is_multisite']   = true;
		$GLOBALS['stonewright_test_super_admins']   = [ self::ADMIN ];
		$this->actor_can( [ 'edit_users', 'edit_user', 'manage_options', 'promote_users', 'promote_user', 'delete_users', 'create_users', 'edit_plugins', 'install_plugins' ] );

		$this->assert_refused( ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'user_email' => 'changed@example.test' ] ) );

		$GLOBALS['stonewright_test_super_admins'] = [ self::ADMIN, self::ACTOR ];
		$this->assertSame( [ 'id' => self::ADMIN ], ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'user_email' => 'changed@example.test' ] ) );
	}

	/** @return list<string> */
	private function admin_caps(): array {
		return [ 'edit_users', 'edit_user', 'manage_options', 'promote_users', 'promote_user', 'delete_users', 'create_users', 'edit_plugins', 'install_plugins' ];
	}

	public function test_update_refuses_the_last_administrator_demoting_themselves(): void {
		$GLOBALS['stonewright_test_current_user_id'] = self::ADMIN;
		$this->actor_can( $this->admin_caps() );

		foreach ( [ 'editor', '' ] as $role ) {
			$result = ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => $role ] );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'stonewright_user_last_administrator', $result->get_error_code() );
		}
		$this->assertSame( [ 'administrator' ], $GLOBALS['stonewright_test_users'][ self::ADMIN ]['roles'] );
		$this->assertSame( 'stonewright_user_last_administrator', ( new UserUpdate() )->permission_callback( [ 'id' => self::ADMIN, 'role' => 'editor' ] )->get_error_code() );
	}

	public function test_update_refuses_demoting_the_last_administrator_by_somebody_else(): void {
		$this->actor_can( $this->admin_caps() );

		$result = ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'subscriber' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_user_last_administrator', $result->get_error_code() );
		$this->assertSame( [ 'administrator' ], $GLOBALS['stonewright_test_users'][ self::ADMIN ]['roles'] );
	}

	public function test_update_allows_keeping_the_administrator_role_or_demoting_when_another_remains(): void {
		$this->actor_can( $this->admin_caps() );
		$this->assertSame( [ 'id' => self::ADMIN ], ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'administrator' ] ) );

		$GLOBALS['stonewright_test_users'][ self::ACTOR ]['roles'] = [ 'administrator' ];
		$this->assertSame( [ 'id' => self::ADMIN ], ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'editor' ] ) );
	}

	public function test_update_applies_the_last_administrator_rule_on_multisite_but_not_to_super_admins(): void {
		$GLOBALS['stonewright_test_is_multisite'] = true;
		$this->actor_can( $this->admin_caps() );

		$site_admin = ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'editor' ] );
		$this->assertInstanceOf( \WP_Error::class, $site_admin, 'The site keeps its administrator.' );
		$this->assertSame( 'stonewright_user_last_administrator', $site_admin->get_error_code() );

		// A super admin target, or a super admin acting, is outside the site rule.
		$GLOBALS['stonewright_test_super_admins'] = [ self::ADMIN, self::ACTOR ];
		$this->assertSame( [ 'id' => self::ADMIN ], ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'editor' ] ) );
		$GLOBALS['stonewright_test_users'][ self::ADMIN ]['roles'] = [ 'administrator' ];
		$GLOBALS['stonewright_test_super_admins'] = [ self::ACTOR ];
		$this->assertSame( [ 'id' => self::ADMIN ], ( new UserUpdate() )->execute( [ 'id' => self::ADMIN, 'role' => 'editor' ] ) );
	}

	public function test_update_reports_an_unknown_user(): void {
		$this->actor_can( [ 'edit_users', 'edit_user' ] );
		$GLOBALS['stonewright_test_missing_user_ids'][] = 777;

		$result = ( new UserUpdate() )->execute( [ 'id' => 777, 'display_name' => 'Nobody' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_user_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// user-create
	// -----------------------------------------------------------------------

	public function test_create_refuses_the_administrator_role_for_a_user_who_cannot_grant_it(): void {
		$this->actor_can( [ 'create_users', 'promote_users' ] );
		$GLOBALS['stonewright_test_editable_roles'] = [
			'editor' => [ 'name' => 'Editor', 'capabilities' => [ 'edit_posts' => true ] ],
		];
		$args = [ 'user_login' => 'newbie', 'user_email' => 'newbie@example.test', 'user_pass' => self::NEW_SECRET, 'role' => 'administrator' ];

		$this->assert_refused( ( new UserCreate() )->permission_callback( $args ) );
		$this->assert_refused( ( new UserCreate() )->execute( $args ) );
				$this->assertCount( 3, $GLOBALS['stonewright_test_users'], 'No user was created.' );
	}

	public function test_create_refuses_any_role_without_the_promote_capability(): void {
		$this->actor_can( [ 'create_users' ] );
		$args = [ 'user_login' => 'newbie', 'user_email' => 'newbie@example.test', 'user_pass' => self::NEW_SECRET, 'role' => 'editor' ];

		$this->assert_refused( ( new UserCreate() )->execute( $args ) );
		$this->assertCount( 3, $GLOBALS['stonewright_test_users'] );
	}

	public function test_create_refuses_an_editable_role_with_elevated_capabilities_the_current_user_lacks(): void {
		// A custom role that is editable but carries the capability to manage options.
		$this->actor_can( [ 'create_users', 'promote_users' ] );
		$GLOBALS['stonewright_test_editable_roles'] = [
			'shop_boss' => [ 'name' => 'Shop boss', 'capabilities' => [ 'manage_options' => true, 'edit_posts' => true ] ],
		];
		$args = [ 'user_login' => 'newbie', 'user_email' => 'newbie@example.test', 'user_pass' => self::NEW_SECRET, 'role' => 'shop_boss' ];

		$this->assert_refused( ( new UserCreate() )->execute( $args ) );
	}

	public function test_create_makes_a_user_with_an_editable_role(): void {
		$this->actor_can( [ 'create_users', 'promote_users' ] );
		$args = [ 'user_login' => 'newbie', 'user_email' => 'newbie@example.test', 'user_pass' => self::NEW_SECRET, 'role' => 'editor' ];

		$this->assertTrue( ( new UserCreate() )->permission_callback( $args ) );
		$result = ( new UserCreate() )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertCount( 4, $GLOBALS['stonewright_test_users'] );
	}

	public function test_create_without_a_role_needs_only_the_create_capability(): void {
		$this->actor_can( [ 'create_users' ] );

		$result = ( new UserCreate() )->execute( [ 'user_login' => 'newbie', 'user_email' => 'newbie@example.test', 'user_pass' => self::NEW_SECRET ] );

		$this->assertIsArray( $result );
	}

	// -----------------------------------------------------------------------
	// user-delete
	// -----------------------------------------------------------------------

	public function test_delete_refuses_deleting_an_administrator_when_the_current_user_cannot_delete_that_user(): void {
		$this->actor_can( [ 'delete_users', 'delete_user' ], [ 'delete_user:' . self::ADMIN ] );
		$args = [ 'id' => self::ADMIN, 'reassign' => self::ACTOR ];

		$this->assert_refused( ( new UserDelete() )->permission_callback( $args ) );
		$this->assert_refused( ( new UserDelete() )->execute( $args ) );
		$this->assertArrayHasKey( self::ADMIN, $GLOBALS['stonewright_test_users'] );
	}

	public function test_delete_refuses_a_target_with_capabilities_the_current_user_lacks(): void {
		$this->actor_can( [ 'delete_users', 'delete_user' ] );

		$this->assert_refused( ( new UserDelete() )->execute( [ 'id' => self::ADMIN, 'reassign' => self::ACTOR ] ) );
		$this->assertArrayHasKey( self::ADMIN, $GLOBALS['stonewright_test_users'] );
	}

	public function test_delete_removes_an_ordinary_user_the_current_user_may_delete(): void {
		$this->actor_can( [ 'delete_users', 'delete_user' ] );
		$args = [ 'id' => self::EDITOR, 'reassign' => self::ACTOR ];

		$this->assertTrue( ( new UserDelete() )->permission_callback( $args ) );
		$result = ( new UserDelete() )->execute( $args );

		$this->assertSame( [ 'deleted' => true, 'id' => self::EDITOR ], $result );
		$this->assertArrayNotHasKey( self::EDITOR, $GLOBALS['stonewright_test_users'] );
	}

	public function test_delete_refuses_a_reassign_target_that_does_not_exist_or_is_the_deleted_user(): void {
		$this->actor_can( [ 'delete_users', 'delete_user' ] );
		$GLOBALS['stonewright_test_missing_user_ids'][] = 888;

		$missing = ( new UserDelete() )->execute( [ 'id' => self::EDITOR, 'reassign' => 888 ] );
		$same    = ( new UserDelete() )->execute( [ 'id' => self::EDITOR, 'reassign' => self::EDITOR ] );

		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'stonewright_user_reassign_invalid', $missing->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $same );
		$this->assertSame( 'stonewright_user_reassign_invalid', $same->get_error_code() );
		$this->assertArrayHasKey( self::EDITOR, $GLOBALS['stonewright_test_users'], 'Nothing was deleted.' );
	}

	public function test_delete_refuses_removing_the_last_administrator(): void {
		$this->actor_can( [ 'delete_users', 'delete_user', 'manage_options', 'promote_users', 'edit_users', 'create_users', 'edit_plugins', 'install_plugins' ] );

		$result = ( new UserDelete() )->execute( [ 'id' => self::ADMIN, 'reassign' => self::EDITOR ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_user_last_administrator', $result->get_error_code() );
		$this->assertArrayHasKey( self::ADMIN, $GLOBALS['stonewright_test_users'] );
	}

	public function test_delete_allows_removing_an_administrator_when_another_one_remains(): void {
		$this->actor_can( [ 'delete_users', 'delete_user', 'manage_options', 'promote_users', 'edit_users', 'create_users', 'edit_plugins', 'install_plugins' ] );
		$GLOBALS['stonewright_test_users'][ self::ACTOR ]['roles'] = [ 'administrator' ];

		$result = ( new UserDelete() )->execute( [ 'id' => self::ADMIN, 'reassign' => self::ACTOR ] );

		$this->assertSame( [ 'deleted' => true, 'id' => self::ADMIN ], $result );
	}

	// -----------------------------------------------------------------------
	// user-list
	// -----------------------------------------------------------------------

	public function test_list_reports_the_real_total_and_pages_with_offset(): void {
		$this->actor_can( [ 'list_users' ] );
		for ( $i = 30; $i < 37; ++$i ) {
			$GLOBALS['stonewright_test_users'][ $i ] = [ 'ID' => $i, 'user_login' => 'u' . $i, 'user_email' => 'u' . $i . '@example.test', 'display_name' => 'U' . $i, 'roles' => [ 'subscriber' ] ];
		}

		$first = ( new UserList() )->execute( [ 'number' => 4 ] );
		$this->assertCount( 4, $first['items'] );
		$this->assertSame( 10, $first['total'], 'The total counts every match, not the page.' );
		$this->assertSame( 0, $first['offset'] );
		$this->assertTrue( $first['has_more'] );

		$second = ( new UserList() )->execute( [ 'number' => 4, 'offset' => 4 ] );
		$this->assertCount( 4, $second['items'] );
		$this->assertSame( 4, $second['offset'] );
		$this->assertNotSame( $first['items'][0]['id'], $second['items'][0]['id'] );

		$last = ( new UserList() )->execute( [ 'number' => 4, 'page' => 3 ] );
		$this->assertCount( 2, $last['items'] );
		$this->assertSame( 8, $last['offset'] );
		$this->assertFalse( $last['has_more'] );
	}

	public function test_list_schema_offers_offset_and_page(): void {
		$props = ( new UserList() )->input_schema()['properties'];

		$this->assertArrayHasKey( 'offset', $props );
		$this->assertArrayHasKey( 'page', $props );
	}
}

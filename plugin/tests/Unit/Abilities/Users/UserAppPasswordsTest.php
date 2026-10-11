<?php
/**
 * user-app-passwords: availability for the target user, the application-password capabilities, and the audit nature of a list.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Users;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Users\UserAppPasswords;

/**
 * @covers \Stonewright\WpMcp\Abilities\Users\UserAppPasswords
 */
final class UserAppPasswordsTest extends TestCase {

	private const TARGET = 2;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 1;
		$GLOBALS['stonewright_test_options']          = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
		$GLOBALS['stonewright_test_app_passwords']    = [ self::TARGET => [ [ 'uuid' => 'uuid-1', 'name' => 'Laptop', 'created' => 1710000000 ] ] ];
		$GLOBALS['stonewright_test_app_passwords_available']          = true;
		$GLOBALS['stonewright_test_app_passwords_available_for_user'] = true;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_user_can_callback'],
			$GLOBALS['stonewright_test_app_passwords_available'],
			$GLOBALS['stonewright_test_app_passwords_available_for_user'],
			$GLOBALS['stonewright_test_app_passwords']
		);
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
	}

	/**
	 * @param list<string> $caps
	 * @param list<string> $denied "cap:user_id" pairs refused although the capability is held.
	 */
	private function user_can( array $caps, array $denied = [] ): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( $caps, $denied ): bool {
			if ( isset( $args[0] ) && in_array( $cap . ':' . (int) $args[0], $denied, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	/** @return list<string> */
	private function all_caps(): array {
		return [ 'manage_options', 'edit_user', 'list_app_passwords', 'create_app_password', 'delete_app_password' ];
	}

	/** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
	public static function actions(): array {
		return [
			'list'   => [ 'list', 'list_app_passwords', [] ],
			'create' => [ 'create', 'create_app_password', [ 'name' => 'Phone' ] ],
			'revoke' => [ 'revoke', 'delete_app_password', [ 'uuid' => 'uuid-1' ] ],
		];
	}

	/**
	 * @param array<string, mixed> $extra
	 * @dataProvider actions
	 */
	public function test_each_action_needs_its_own_application_password_capability( string $action, string $capability, array $extra ): void {
		$args = array_merge( [ 'action' => $action, 'user_id' => self::TARGET ], $extra );

		$this->user_can( $this->all_caps() );
		$this->assertTrue( ( new UserAppPasswords() )->permission_callback( $args ) );

		$this->user_can( array_values( array_diff( $this->all_caps(), [ $capability ] ) ) );
		$this->assertNotTrue( ( new UserAppPasswords() )->permission_callback( $args ), $capability . ' is required.' );

		$this->user_can( $this->all_caps(), [ $capability . ':' . self::TARGET ] );
		$this->assertNotTrue( ( new UserAppPasswords() )->permission_callback( $args ), $capability . ' is checked for the target user.' );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @dataProvider actions
	 */
	public function test_execute_refuses_an_action_without_the_capability( string $action, string $capability, array $extra ): void {
		$this->user_can( array_values( array_diff( $this->all_caps(), [ $capability ] ) ) );

		$result = ( new UserAppPasswords() )->execute( array_merge( [ 'action' => $action, 'user_id' => self::TARGET ], $extra ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_app_passwords_forbidden', $result->get_error_code() );
		$this->assertCount( 1, $GLOBALS['stonewright_test_app_passwords'][ self::TARGET ], 'No application password was created or revoked.' );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @dataProvider actions
	 */
	public function test_application_passwords_must_be_available_for_the_target_user( string $action, string $capability, array $extra ): void {
		unset( $capability );
		$this->user_can( $this->all_caps() );
		$GLOBALS['stonewright_test_app_passwords_available_for_user'] = false;
		$args = array_merge( [ 'action' => $action, 'user_id' => self::TARGET ], $extra );

		$permission = ( new UserAppPasswords() )->permission_callback( $args );
		$this->assertInstanceOf( \WP_Error::class, $permission );
		$this->assertSame( 'stonewright_app_passwords_unavailable', $permission->get_error_code() );

		$result = ( new UserAppPasswords() )->execute( $args );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_app_passwords_unavailable', $result->get_error_code() );
		$this->assertCount( 1, $GLOBALS['stonewright_test_app_passwords'][ self::TARGET ] );
	}

	public function test_a_list_is_audited_as_a_read_and_a_create_as_a_write(): void {
		$this->user_can( $this->all_caps() );

		( new UserAppPasswords() )->execute( [ 'action' => 'list', 'user_id' => self::TARGET ] );
		$list_meta = $this->recorded_meta( 0 );

		( new UserAppPasswords() )->execute( [ 'action' => 'create', 'user_id' => self::TARGET, 'name' => 'Phone' ] );
		$create_meta = $this->recorded_meta( 1 );

		$this->assertSame( 'read', $list_meta['operation_kind'] ?? null );
		$this->assertNotSame( 'read', $create_meta['operation_kind'] ?? null );
	}

	public function test_a_list_returns_the_items_for_a_permitted_user(): void {
		$this->user_can( $this->all_caps() );

		$result = ( new UserAppPasswords() )->execute( [ 'action' => 'list', 'user_id' => self::TARGET ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'uuid-1', $result['items'][0]['uuid'] );
	}

	/** @return array<string, mixed> */
	private function recorded_meta( int $index ): array {
		$decoded = json_decode( (string) ( $GLOBALS['stonewright_test_wpdb_inserts'][ $index ]['data']['sanitized_args'] ?? '' ), true );
		return is_array( $decoded ) && is_array( $decoded['_meta'] ?? null ) ? $decoded['_meta'] : [];
	}
}

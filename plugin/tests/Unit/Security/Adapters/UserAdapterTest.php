<?php
/**
 * The user family in the change ledger: field-level images, roles, and never a credential.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Users\UserCreate;
use Stonewright\WpMcp\Abilities\Users\UserDelete;
use Stonewright\WpMcp\Abilities\Users\UserUpdate;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\Adapters\UserAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\UserAdapter
 * @covers \Stonewright\WpMcp\Security\Adapters\OtherFamilies
 * @covers \Stonewright\WpMcp\Security\Adapters\FamilyAdapter
 */
final class UserAdapterTest extends OtherFamilyLedgerTestCase {

	private const SECRETS = [
		'sentinel-password-hash-21',
		'activationsecret21',
		'sentinel-session-token',
		'sentinel-app-password-hash',
		'sentinel-api-token',
	];

	private function user_with_secrets( int $id = 21 ): void {
		$this->make_user( $id );
		$GLOBALS['stonewright_test_user_meta'][ $id ] = [
			'first_name'            => 'Alice',
			'nickname'              => 'ally',
			'description'           => 'Writes the news.',
			'session_tokens'        => [ 'sentinel-session-token' => [ 'expiration' => 1999999999 ] ],
			'_application_passwords' => [ [ 'uuid' => 'u-1', 'password' => 'sentinel-app-password-hash' ] ],
			'api_token'             => 'sentinel-api-token',
			'wp_capabilities'       => [ 'editor' => true ],
		];
	}

	public function test_an_update_records_the_fields_and_roles_before_and_after(): void {
		$this->user_with_secrets();

		$result = ( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'display_name' => 'Alice B' ] );

		self::assertSame( [ 'id' => 21 ], $result, 'The ability result is untouched.' );
		$row = $this->row_of( 'user' );
		self::assertSame( 'user', $row['resource_type'] );
		self::assertSame( '21', $row['resource_id'] );
		self::assertSame( 'stonewright/user-update', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		self::assertStringStartsWith( 'Updated user', $row['summary'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertSame( [ 'editor' ], $before['roles'] );
		self::assertSame( 'Alice 21', $before['fields']['display_name'] );
		self::assertSame( 'alice21@example.test', $before['fields']['user_email'] );
		self::assertSame( 'Alice', $before['profile']['first_name'] );
		self::assertSame( [ 'author' ], $after['roles'] );
		self::assertSame( 'Alice B', $after['fields']['display_name'] );
	}

	public function test_no_password_session_or_secret_user_meta_is_in_any_image_or_row(): void {
		$this->user_with_secrets();

		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'user_pass' => 'sentinel-new-pass-phrase', 'display_name' => 'Alice B' ] );

		$stored = $this->stored_text();
		self::assertStringContainsString( 'alice21@example.test', $stored, 'The image was stored.' );
		foreach ( array_merge( self::SECRETS, [ 'sentinel-new-pass-phrase' ] ) as $secret ) {
			self::assertStringNotContainsString( $secret, $stored, 'The ledger and the audit log hold no ' . $secret );
		}
		foreach ( [ '"user_pass"', 'user_activation_key', 'session_tokens', '_application_passwords' ] as $name ) {
			self::assertStringNotContainsString( $name, $this->ledger_text(), 'No image names ' . $name );
		}
	}

	public function test_the_image_of_a_user_has_only_the_named_parts(): void {
		$this->user_with_secrets();

		$image = UserAdapter::image( 'user', '21' );

		self::assertSame( [ 'caps', 'fields', 'profile', 'roles', 'v' ], array_keys( $image ) );
		self::assertSame( [ 'display_name', 'user_email', 'user_login', 'user_nicename', 'user_registered', 'user_url' ], array_keys( $image['fields'] ) );
		self::assertSame( [ 'description', 'first_name', 'nickname' ], array_keys( $image['profile'] ) );
	}

	public function test_a_password_change_is_an_event_with_no_image_and_no_hash(): void {
		$this->user_with_secrets();

		( new UserUpdate() )->execute( [ 'id' => 21, 'user_pass' => 'sentinel-new-pass-phrase' ] );

		$row = $this->row_of( 'user' );
		self::assertSame( 'user_password', $row['resource_type'] );
		self::assertSame( '21', $row['resource_id'] );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'secret_resource', $row['restorable_reason'] );
		self::assertSame( [ '', '', '', '' ], [ $row['before_ref'], $row['after_ref'], $row['before_sha256'], $row['after_sha256'] ] );
		self::assertStringContainsString( 'Password changed', $row['summary'] );
		self::assertStringNotContainsString( 'sentinel-new-pass-phrase', $this->stored_text() );
		$restore = OtherFamilies::restore( $row['change_id'] );
		self::assertSame( 'failed', $restore['status'] );
		self::assertSame( 'not_restorable', $restore['detail'] );
	}

	public function test_a_password_and_a_field_changed_together_make_a_field_row_and_a_password_event(): void {
		$this->user_with_secrets();

		( new UserUpdate() )->execute( [ 'id' => 21, 'user_pass' => 'sentinel-new-pass-phrase', 'display_name' => 'Alice B' ] );

		$types = array_column( $this->ledger_rows(), 'resource_type' );
		sort( $types );
		self::assertSame( [ 'user', 'user_password' ], $types );
	}

	public function test_a_profile_field_with_a_credential_in_it_is_masked_and_not_restorable(): void {
		$this->user_with_secrets();
		$GLOBALS['stonewright_test_user_meta'][21]['description'] = "Bio\npassword: hunter2hunter2";

		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );

		$row = $this->row_of( 'user' );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'hunter2hunter2', $this->stored_text() );
	}

	public function test_an_update_that_changes_nothing_and_a_failed_update_leave_no_row(): void {
		$this->user_with_secrets();

		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice 21' ] );
		$this->call( 'stonewright/user-update', [ 'id' => 21, 'display_name' => 'Nobody' ], static function (): void {}, new \WP_Error( 'stonewright_user_update_failed', 'No.' ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_restore_writes_the_roles_and_fields_back_and_records_a_rollback_row(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'display_name' => 'Alice B' ] );
		$change = $this->row_of( 'user' );

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( [ 'editor' ], $this->user( 21 )['roles'] );
		self::assertSame( 'Alice 21', $this->user( 21 )['display_name'] );
		$rollbacks = $this->rows_of_kind( 'rollback' );
		self::assertCount( 1, $rollbacks );
		self::assertSame( $change['change_id'], $rollbacks[0]['parent_id'] );
		self::assertSame( $rollbacks[0]['change_id'], $restore['rollback_change_id'] );
		self::assertSame( 'verified', $rollbacks[0]['status'] );
		self::assertTrue( $rollbacks[0]['restorable'], 'A rollback can itself be undone.' );
		self::assertSame( 'noop', OtherFamilies::restore( $change['change_id'] )['status'], 'A second restore finds nothing to do.' );
	}

	public function test_restore_never_writes_the_password(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'user_pass' => 'sentinel-new-pass-phrase' ] );
		$change = array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => 'user' === $row['resource_type'] ) )[0];

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'sentinel-new-pass-phrase', $this->user( 21 )['user_pass'], 'The password the change set is not touched by the restore.' );
		self::assertSame( [ 'editor' ], $this->user( 21 )['roles'] );
	}

	public function test_restore_refuses_when_the_caller_may_not_edit_users(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author' ] );
		$change                                         = $this->row_of( 'user' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'edit_users' !== $cap;

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( [ 'author' ], $this->user( 21 )['roles'] );
		self::assertSame( [], $this->rows_of_kind( 'rollback' ) );
	}

	public function test_a_restore_that_changes_roles_also_needs_the_promote_capability(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'display_name' => 'Alice B' ] );
		$change                                         = $this->row_of( 'user' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'promote_users' !== $cap;

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( [ [ 'author' ], 'Alice B' ], [ $this->user( 21 )['roles'], $this->user( 21 )['display_name'] ], 'Nothing was written.' );
		self::assertSame( [], $this->rows_of_kind( 'rollback' ) );
	}

	public function test_a_restore_that_changes_no_role_does_not_need_the_promote_capability(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$change                                         = $this->row_of( 'user' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'promote_users' !== $cap;

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'Alice 21', $this->user( 21 )['display_name'] );
	}

	public function test_restore_with_the_expected_hash_stops_when_the_user_changed_since(): void {
		$this->user_with_secrets();
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author' ] );
		$change = $this->row_of( 'user' );
		self::assertSame( $change['after_sha256'], OtherFamilies::live_sha256( $change['change_id'] ) );
		$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Edited by a person';

		$restore = OtherFamilies::restore( $change['change_id'], [ 'expected_current_sha256' => $change['after_sha256'] ] );

		self::assertSame( [ 'failed', 'current_changed' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( [ 'author' ], $this->user( 21 )['roles'] );
	}

	public function test_a_delete_records_the_full_image_and_says_it_is_partly_restorable(): void {
		$this->user_with_secrets();
		$GLOBALS['stonewright_test_missing_user_ids'][] = 21;

		$result = ( new UserDelete() )->execute( [ 'id' => 21, 'reassign' => 7 ] );

		self::assertSame( [ 'deleted' => true, 'id' => 21 ], $result );
		$row = $this->row_of( 'user' );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['after_ref'] );
		self::assertStringStartsWith( 'Deleted user', $row['summary'] );
		self::assertStringContainsString( 'partly restorable', $row['summary'] );
		self::assertStringContainsString( 'no password', $row['summary'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( 'alice21', $before['fields']['user_login'] );
		self::assertSame( [ 'editor' ], $before['roles'] );
		foreach ( self::SECRETS as $secret ) {
			self::assertStringNotContainsString( $secret, $this->stored_text() );
		}
	}

	public function test_restore_recreates_a_deleted_user_with_a_new_id_and_a_password_nobody_knows(): void {
		$this->user_with_secrets();
		$GLOBALS['stonewright_test_missing_user_ids'][] = 21;
		( new UserDelete() )->execute( [ 'id' => 21, 'reassign' => 7 ] );
		$change = $this->row_of( 'user' );

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertNotEmpty( $restore['limits'] );
		self::assertStringContainsString( 'password', implode( ' ', $restore['limits'] ) );
		self::assertFalse( $this->user_exists( 21 ) );
		$new = $this->user( 50 );
		self::assertSame( 'alice21', $new['user_login'] );
		self::assertSame( 'alice21@example.test', $new['user_email'] );
		self::assertSame( [ 'editor' ], $new['roles'] );
		self::assertNotSame( '', $new['user_pass'], 'The recreated user has a password nobody knows, not an empty one.' );
		self::assertStringNotContainsString( 'sentinel-password-hash-21', (string) $new['user_pass'] );
		$rollback = $this->rows_of_kind( 'rollback' )[0];
		self::assertSame( '50', $rollback['resource_id'], 'The rollback row names the new user.' );
		self::assertSame( $change['change_id'], $rollback['parent_id'] );
		self::assertStringStartsWith( 'Created user', $rollback['summary'] );
	}

	public function test_a_created_user_is_recorded_without_the_password_and_its_undo_deletes_it(): void {
		$GLOBALS['stonewright_test_missing_user_ids'][] = 50;

		$result = ( new UserCreate() )->execute( [ 'user_login' => 'new1', 'user_email' => 'new1@example.test', 'user_pass' => 'sentinel-pass-phrase-1', 'role' => 'author' ] );

		self::assertSame( [ 'id' => 50 ], $result );
		$row = $this->row_of( 'user' );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertStringStartsWith( 'Created user', $row['summary'] );
		self::assertSame( [ 'author' ], ChangeLedger::read_image( $row['change_id'], 'after' )['roles'] );
		self::assertStringNotContainsString( 'sentinel-pass-phrase-1', $this->stored_text() );

		$undo = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertFalse( $this->user_exists( 50 ) );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_the_undo_of_a_created_user_never_deletes_a_user_with_content(): void {
		$GLOBALS['stonewright_test_missing_user_ids'][] = 50;
		( new UserCreate() )->execute( [ 'user_login' => 'new1', 'user_email' => 'new1@example.test', 'user_pass' => 'sentinel-pass-phrase-1' ] );
		$GLOBALS['stonewright_test_user_post_counts'][50] = 3;

		$undo = OtherFamilies::restore( $this->row_of( 'user' )['change_id'] );

		self::assertSame( [ 'failed', 'user_has_content' ], [ $undo['status'], $undo['detail'] ] );
		self::assertTrue( $this->user_exists( 50 ) );
	}

	public function test_the_undo_of_a_created_user_never_deletes_a_user_who_only_has_drafts(): void {
		$GLOBALS['stonewright_test_missing_user_ids'][] = 50;
		( new UserCreate() )->execute( [ 'user_login' => 'new1', 'user_email' => 'new1@example.test', 'user_pass' => 'sentinel-pass-phrase-1' ] );
		$this->make_post( 31, [ 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => 50 ] );

		$undo = OtherFamilies::restore( $this->row_of( 'user' )['change_id'] );

		self::assertSame( [ 'failed', 'user_has_content' ], [ $undo['status'], $undo['detail'] ] );
		self::assertTrue( $this->user_exists( 50 ) );
	}

	public function test_the_undo_of_a_created_user_never_deletes_the_user_who_asks(): void {
		$GLOBALS['stonewright_test_missing_user_ids'][] = 50;
		( new UserCreate() )->execute( [ 'user_login' => 'new1', 'user_email' => 'new1@example.test', 'user_pass' => 'sentinel-pass-phrase-1' ] );
		$GLOBALS['stonewright_test_current_user_id'] = 50;

		$undo = OtherFamilies::restore( $this->row_of( 'user' )['change_id'] );

		self::assertSame( [ 'failed', 'current_user' ], [ $undo['status'], $undo['detail'] ] );
		self::assertTrue( $this->user_exists( 50 ) );
	}

	public function test_an_application_password_created_is_an_event_and_the_password_is_nowhere(): void {
		$this->make_user( 21 );

		$result = $this->call(
			'stonewright/user-app-passwords',
			[ 'action' => 'create', 'user_id' => 21, 'name' => 'CI deploy', 'confirmation_token' => 'sentinel-token-for-the-call-9f8e' ],
			static function (): void {},
			[ 'uuid' => '8a1f2c3d-0000-4000-8000-000000000001', 'name' => 'CI deploy', 'password' => 'sentinel-created-app-password-value', 'note' => 'Store this now; it cannot be retrieved again.' ]
		);

		self::assertSame( 'sentinel-created-app-password-value', $result['password'], 'The caller still gets its result.' );
		$row = $this->row_of( 'user' );
		self::assertSame( 'application_password', $row['resource_type'] );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'secret_resource', $row['restorable_reason'] );
		self::assertSame( [ '', '' ], [ $row['before_ref'], $row['after_ref'] ] );
		self::assertSame( 'verified', $row['status'] );
		self::assertStringContainsString( 'Application password created', $row['summary'] );
		self::assertStringContainsString( 'CI deploy', $row['summary'] );
		$stored = $this->stored_text();
		self::assertStringNotContainsString( 'abcd EFGH', $stored );
		self::assertStringNotContainsString( 'sentinel-token-for-the-call-9f8e', $stored );
		self::assertSame( 'failed', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_an_application_password_revoked_is_an_event_and_a_list_records_nothing(): void {
		$this->make_user( 21 );

		$this->call( 'stonewright/user-app-passwords', [ 'action' => 'list', 'user_id' => 21 ], static function (): void {}, [ 'items' => [], 'total' => 0 ] );
		self::assertSame( [], $this->ledger_rows() );

		$this->call( 'stonewright/user-app-passwords', [ 'action' => 'revoke', 'user_id' => 21, 'uuid' => '8a1f2c3d-0000-4000-8000-000000000001' ], static function (): void {}, [ 'deleted' => true, 'uuid' => '8a1f2c3d-0000-4000-8000-000000000001' ] );

		$row = $this->row_of( 'user' );
		self::assertSame( 'application_password', $row['resource_type'] );
		self::assertFalse( $row['restorable'] );
		self::assertStringContainsString( 'Application password revoked', $row['summary'] );
	}

	public function test_a_failed_application_password_call_records_nothing(): void {
		$this->make_user( 21 );

		$this->call( 'stonewright/user-app-passwords', [ 'action' => 'create', 'user_id' => 21, 'name' => 'CI' ], static function (): void {}, new \WP_Error( 'stonewright_app_passwords_unavailable', 'No.' ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_the_pending_call_keeps_no_argument_value(): void {
		$this->user_with_secrets();

		$pending = OtherFamilies::begin( 'stonewright/user-update', [ 'id' => 21, 'user_pass' => 'sentinel-new-pass-phrase', 'display_name' => 'Alice B' ] );

		self::assertIsArray( $pending );
		self::assertStringNotContainsString( 'sentinel-new-pass-phrase', (string) json_encode( $pending ) );
		self::assertStringNotContainsString( 'sentinel-password-hash-21', (string) json_encode( $pending ) );
	}
}

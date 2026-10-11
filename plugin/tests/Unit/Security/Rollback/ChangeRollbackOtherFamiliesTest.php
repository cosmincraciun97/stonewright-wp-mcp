<?php
/**
 * The rollback engine against the user, comment, media, WooCommerce, theme and memory families: an undo and a redo
 * through the engine, drift from the live image, and the refusal of rows that cannot be restored.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Abilities\Comments\CommentDelete;
use Stonewright\WpMcp\Abilities\Comments\CommentUpdate;
use Stonewright\WpMcp\Abilities\Media\SetAlt;
use Stonewright\WpMcp\Abilities\Memory\MemoryDelete;
use Stonewright\WpMcp\Abilities\Settings\SettingsUpdate;
use Stonewright\WpMcp\Abilities\Themes\ThemeActivate;
use Stonewright\WpMcp\Abilities\Users\UserUpdate;
use Stonewright\WpMcp\Abilities\WooCommerce\WcProductSave;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\FakeWooProduct;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\RollbackFamilies
 * @covers \Stonewright\WpMcp\Security\Rollback\CallableRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Rollback\OptionsRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Adapters\OtherFamilies
 * @covers \Stonewright\WpMcp\Security\Adapters\FamilyAdapter
 */
final class ChangeRollbackOtherFamiliesTest extends OtherRollbackTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWooProduct::reset();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		WooRuntime::set_test_overrides(
			[
				'available'                => static fn (): bool => true,
				'get_product'              => static fn ( int $id ): mixed => isset( FakeWooProduct::$store[ $id ] ) ? clone FakeWooProduct::$store[ $id ] : null,
				'new_product'              => static fn ( string $type ): object => new FakeWooProduct( 0, $type ),
				'new_variation'            => static fn (): object => new FakeWooProduct( 0, 'variation' ),
				'get_attribute_taxonomies' => static fn (): array => array_values( $GLOBALS['stonewright_test_wc_attributes'] ),
			]
		);
	}

	protected function tearDown(): void {
		WooRuntime::reset_test_overrides();
		FakeWooProduct::reset();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	private function ok( array|\WP_Error $result ): array {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		return $result;
	}

	/**
	 * Undo a change and redo the undo through the engine, checking the rows both leave.
	 *
	 * @return array{undo: array<string, mixed>, redo: array<string, mixed>}
	 */
	private function undo_then_redo( string $id, callable $after_undo, callable $after_redo ): array {
		$plan = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		self::assertSame( 'ledger', $plan['path'] );
		self::assertTrue( $plan['drift_known'], 'The live state of the family can be read.' );
		self::assertFalse( $plan['drift'], 'Nothing changed after the recorded change.' );
		self::assertTrue( $plan['diff']['changed'], 'The plan shows what the undo changes.' );

		$undo = $this->ok( ChangeRollback::run( $id ) );
		self::assertSame( 'rollback', $undo['kind'] );
		$after_undo();
		$rollback = $this->row( $undo['rollback_change_id'] );
		self::assertSame( [ 'rollback', $id ], [ $rollback['kind'], $rollback['parent_id'] ] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );

		$redo = $this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertSame( 'redo', $redo['kind'] );
		$after_redo();
		$again = $this->row( $redo['rollback_change_id'] );
		self::assertSame( [ 'redo', $undo['rollback_change_id'] ], [ $again['kind'], $again['parent_id'] ], 'The row of a redo is a redo row.' );
		self::assertSame( 'verified', $this->row( $id )['status'], 'The change is back in effect.' );
		self::assertSame( 'rolled_back_by', $this->row( $undo['rollback_change_id'] )['status'] );

		return [ 'undo' => $undo, 'redo' => $redo ];
	}

	/** @return array<string, mixed> */
	private function comment( string $approved ): array {
		return [
			'comment_ID'           => 5,
			'comment_post_ID'      => 31,
			'comment_author'       => 'Reader One',
			'comment_author_email' => 'reader@example.test',
			'comment_author_url'   => '',
			'comment_author_IP'    => '203.0.113.9',
			'comment_date'         => '2026-01-02 03:04:05',
			'comment_date_gmt'     => '2026-01-02 03:04:05',
			'comment_content'      => 'First!',
			'comment_karma'        => 0,
			'comment_approved'     => $approved,
			'comment_agent'        => 'Synthetic agent',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		];
	}

	// ---- the registry --------------------------------------------------------------------------------------

	public function test_the_families_of_the_other_adapters_are_registered_with_a_live_image(): void {
		foreach ( [ 'user', 'media', 'comment', 'woocommerce', 'plugin', 'skill', 'design_direction', 'memory', 'option' ] as $family ) {
			self::assertNotNull( RollbackFamilies::handler_for( $family ), $family );
		}
		self::assertNull( RollbackFamilies::handler_for( 'other' ), 'Rows of the other family are events with nothing to restore.' );

		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$live = RollbackFamilies::handler_for( 'user' )->live_image( $this->row_of( 'user' ) );
		self::assertIsArray( $live, 'The handler reads the live user.' );
		self::assertSame( 'Alice B', $live['fields']['display_name'] );
	}

	public function test_a_theme_switch_needs_no_person_and_plugin_changes_would(): void {
		( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );
		$theme = $this->row_of( 'option' );

		self::assertFalse( RollbackFamilies::handler_for( 'option' )->requires_human( $theme ) );
		self::assertTrue( RollbackFamilies::handler_for( 'plugin' )->requires_human( [ 'family' => 'plugin' ] ) );
		$plan = $this->ok( ChangeRollback::run( $theme['change_id'], [ 'dry_run' => true ] ) );
		self::assertFalse( $plan['approval_required'] );
		self::assertStringContainsString( 'theme', strtolower( $plan['would_apply'] ) );
	}

	public function test_option_rows_that_are_not_a_theme_switch_still_go_to_the_options_handler(): void {
		update_option( 'blogname', 'Before name' );
		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After name' ] ] );
		$row = $this->only_row_of( 'option' );
		$this->forget_journal();

		$this->ok( ChangeRollback::run( $row['change_id'] ) );

		self::assertSame( 'Before name', get_option( 'blogname' ) );
	}

	// ---- undo and redo per family ----------------------------------------------------------------------------

	public function test_a_user_change_is_undone_and_redone_with_its_roles(): void {
		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author', 'display_name' => 'Alice B' ] );
		$id = $this->row_of( 'user' )['change_id'];

		$this->undo_then_redo(
			$id,
			function (): void {
				self::assertSame( [ 'editor' ], $this->user( 21 )['roles'] );
				self::assertSame( 'Alice 21', $this->user( 21 )['display_name'] );
			},
			function (): void {
				self::assertSame( [ 'author' ], $this->user( 21 )['roles'] );
				self::assertSame( 'Alice B', $this->user( 21 )['display_name'] );
			}
		);
		self::assertStringNotContainsString( 'sentinel-password-hash-21', $this->stored_text(), 'No password hash reaches the ledger or the audit rows.' );
	}

	public function test_a_comment_change_is_undone_and_redone(): void {
		$GLOBALS['stonewright_test_comments'][5] = $this->comment( '0' );
		( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'approve' ] );
		$id = $this->row_of( 'comment' )['change_id'];

		$this->undo_then_redo(
			$id,
			static function (): void {
				self::assertSame( '0', $GLOBALS['stonewright_test_comments'][5]['comment_approved'] );
			},
			static function (): void {
				self::assertSame( 'approve', $GLOBALS['stonewright_test_comments'][5]['comment_approved'] );
			}
		);
	}

	public function test_a_deleted_comment_comes_back_under_a_new_id_and_the_answer_names_it(): void {
		$GLOBALS['stonewright_test_comments'][5] = $this->comment( '1' );
		( new CommentDelete() )->execute( [ 'id' => 5, 'force' => true ] );
		$id = $this->row_of( 'comment' )['change_id'];

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( '5001', $undo['resource_id'], 'The answer names the comment that now exists.' );
		self::assertSame( 'First!', $GLOBALS['stonewright_test_comments'][5001]['comment_content'] );
		self::assertStringContainsString( 'new id', implode( ' ', $undo['limits'] ) );

		$redo = $this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );

		self::assertSame( 'trash', $GLOBALS['stonewright_test_comments'][5001]['comment_approved'], 'The redo of a recreated comment trashes it.' );
		self::assertSame( 'redo', $this->row( $redo['rollback_change_id'] )['kind'] );
	}

	public function test_a_media_change_is_undone_and_redone(): void {
		$this->make_post( 70, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Hero image', 'post_name' => 'hero-image', 'post_mime_type' => 'image/jpeg' ] );
		$this->set_meta( 70, '_wp_attachment_image_alt', 'Old alt' );
		$this->set_meta( 70, '_wp_attached_file', '2026/01/hero.jpg' );
		( new SetAlt() )->execute( [ 'id' => 70, 'alt' => 'New alt' ] );
		$id = $this->row_of( 'media' )['change_id'];

		$this->undo_then_redo(
			$id,
			function (): void {
				self::assertSame( 'Old alt', $this->meta( 70, '_wp_attachment_image_alt' ) );
			},
			function (): void {
				self::assertSame( 'New alt', $this->meta( 70, '_wp_attachment_image_alt' ) );
			}
		);
	}

	public function test_a_catalog_change_is_undone_and_redone_through_the_catalog_objects(): void {
		FakeWooProduct::add( 12, 'simple', [ 'name' => 'Old name', 'slug' => 'old-name', 'sku' => 'SKU-12', 'regular_price' => '10', 'category_ids' => [ 4, 5 ], 'description' => 'Stone mug.' ] );
		( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'New name', 'regular_price' => '12', 'dry_run' => false ] );
		$id = $this->row_of( 'woocommerce' )['change_id'];

		$this->undo_then_redo(
			$id,
			static function (): void {
				self::assertSame( [ 'Old name', '10' ], [ FakeWooProduct::$store[12]->get_name(), FakeWooProduct::$store[12]->get_regular_price() ] );
			},
			static function (): void {
				self::assertSame( [ 'New name', '12' ], [ FakeWooProduct::$store[12]->get_name(), FakeWooProduct::$store[12]->get_regular_price() ] );
			}
		);
	}

	public function test_a_theme_switch_is_undone_and_redone(): void {
		( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );
		$id = $this->row_of( 'option' )['change_id'];

		$this->undo_then_redo(
			$id,
			static function (): void {
				self::assertSame( 'site-a-theme', get_stylesheet() );
			},
			static function (): void {
				self::assertSame( 'site-b-theme', get_stylesheet() );
			}
		);
	}

	public function test_a_deleted_memory_entry_is_undone_and_the_redo_removes_it_again(): void {
		$table                        = $this->db->prefix . 'stonewright_memory';
		$this->db->tables[ $table ][] = [
			'id'                  => 9,
			'scope'               => 'site',
			'type'                => 'feedback',
			'name'                => 'Brand voice',
			'memory_key'          => 'brand-voice-9',
			'value_json'          => '{"tone":"plain"}',
			'confidence'          => '0.9000',
			'topic'               => 'copy',
			'version_fingerprint' => '',
			'expires_at'          => null,
			'status'              => 'active',
			'precedence'          => '5',
			'created_by'          => '7',
			'created_at'          => '2026-01-02 03:04:05',
			'updated_at'          => '2026-01-03 03:04:05',
			'last_retrieved_at'   => null,
		];
		$this->db->unique[ $table ]   = [ 'id' ];
		( new MemoryDelete() )->execute( [ 'id' => 9 ] );
		$id = $this->row_of( 'memory' )['change_id'];

		$undo = $this->ok( ChangeRollback::run( $id ) );
		self::assertSame( 'Brand voice', Memory::get_by_id( 9 )['name'] );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertNull( Memory::get_by_id( 9 ), 'The redo deletes the entry again.' );
	}

	// ---- drift ---------------------------------------------------------------------------------------------

	public function test_a_user_edited_after_the_change_is_drift_and_needs_force(): void {
		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$id                                                    = $this->row_of( 'user' )['change_id'];
		$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Edited by hand';

		$plan = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		self::assertTrue( $plan['drift'] );
		self::assertTrue( $plan['requires_force'] );
		self::assertStringContainsString( 'changed since', implode( ' ', $plan['warnings'] ) );

		$error = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );
		self::assertTrue( $error->get_error_data()['drift'] );
		self::assertSame( 'Edited by hand', $this->user( 21 )['display_name'], 'Nothing was written.' );

		$done = $this->ok( ChangeRollback::run( $id, [ 'force_drift' => true ] ) );
		self::assertTrue( $done['drift_forced'] );
		self::assertSame( 'Alice 21', $this->user( 21 )['display_name'] );
	}

	public function test_the_state_a_person_previewed_is_checked_against_the_live_user(): void {
		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$id                                                    = $this->row_of( 'user' )['change_id'];
		$plan                                                  = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Edited by hand';

		$this->assert_refused( ChangeRollback::run( $id, [ 'expected_current_sha256' => $plan['current_sha256'], 'force_drift' => true ] ), 'stonewright_change_changed_since_preview' );

		self::assertSame( 'Edited by hand', $this->user( 21 )['display_name'] );
	}

	// ---- what cannot be restored -------------------------------------------------------------------------------

	public function test_a_plugin_delete_and_a_password_event_are_refused_with_their_reason(): void {
		$this->make_user( 21 );
		$this->call( 'stonewright/plugin-delete', [ 'plugin' => 'example/example.php' ], static function (): void {}, [ 'deleted' => true ] );
		( new UserUpdate() )->execute( [ 'id' => 21, 'user_pass' => 'sentinel-new-pass-phrase' ] );
		$plugin   = $this->row_of( 'plugin' );
		$password = array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => 'user_password' === $row['resource_type'] ) )[0];

		$error = $this->assert_refused( ChangeRollback::run( $plugin['change_id'] ), 'stonewright_change_not_restorable' );
		self::assertSame( 'plugin_files_deleted', $error->get_error_data()['reason'] );
		$error = $this->assert_refused( ChangeRollback::run( $password['change_id'], [ 'dry_run' => true ] ), 'stonewright_change_not_restorable' );
		self::assertSame( 'secret_resource', $error->get_error_data()['reason'] );
	}

	public function test_a_php_execute_row_is_not_restorable(): void {
		$this->call( 'stonewright/php-execute', [ 'code' => 'update_option( "x", 1 );', 'read_only' => false ], static function (): void {}, [ 'ok' => true, 'result' => null ] );
		$row = $this->row_of( 'other' );

		$this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_change_not_restorable' );
	}

	// ---- gates ---------------------------------------------------------------------------------------------

	public function test_in_production_safe_mode_the_token_is_verified_once_and_binds_the_change(): void {
		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$id = $this->row_of( 'user' )['change_id'];
		$this->production_safe();

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_confirmation_required' );
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );
		$done  = ChangeRollback::run( $id, [ 'confirmation_token' => $token ] );

		self::assertIsArray( $done, $done instanceof \WP_Error ? $done->get_error_message() : '' );
		self::assertSame( 'Alice 21', $this->user( 21 )['display_name'] );
	}

	public function test_the_adapter_writes_a_redo_row_when_it_is_asked_for_one(): void {
		$this->make_user( 21 );
		( new UserUpdate() )->execute( [ 'id' => 21, 'display_name' => 'Alice B' ] );
		$id = $this->row_of( 'user' )['change_id'];

		$result = OtherFamilies::restore( $id, [ 'kind' => 'redo' ] );

		self::assertSame( 'succeeded', $result['status'], $result['detail'] );
		self::assertSame( 'redo', ChangeLedger::get( (string) $result['rollback_change_id'] )['kind'] );
	}
}

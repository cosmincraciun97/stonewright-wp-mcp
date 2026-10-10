<?php
/**
 * Theme switches, plugin deletes, php-execute and admin settings writes in the change ledger.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Abilities\Themes\ThemeActivate;
use Stonewright\WpMcp\Admin\AbilityToggles;
use Stonewright\WpMcp\Admin\SetupState;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\Adapters\SiteAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\SiteAdapter
 */
final class SiteAdapterTest extends OtherFamilyLedgerTestCase {

	public function test_a_theme_switch_records_the_previous_stylesheet_and_template_and_restore_switches_back(): void {
		$result = ( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );

		self::assertSame( [ 'stylesheet' => 'site-b-theme', 'active' => true ], $result );
		$row = $this->row_of( 'option' );
		self::assertSame( [ 'theme_switch', 'active_theme', 'stonewright/theme-activate', 'verified', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringStartsWith( 'Switched theme', $row['summary'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( [ 'site-a-theme', 'site-a-theme' ], [ $before['stylesheet'], $before['template'] ] );
		self::assertSame( 'site-b-theme', ChangeLedger::read_image( $row['change_id'], 'after' )['stylesheet'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'site-a-theme', get_stylesheet() );
		$rollback = $this->rows_of_kind( 'rollback' )[0];
		self::assertSame( $row['change_id'], $rollback['parent_id'] );
		self::assertSame( 'site-b-theme', ChangeLedger::read_image( $rollback['change_id'], 'before' )['stylesheet'], 'A redo is possible: the rollback keeps the theme it replaced.' );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_a_theme_switch_to_the_active_theme_records_nothing(): void {
		( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-a-theme' ] );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_a_theme_restore_needs_the_switch_capability(): void {
		( new ThemeActivate() )->execute( [ 'stylesheet' => 'site-b-theme' ] );
		$row                                           = $this->row_of( 'option' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'switch_themes' !== $cap;

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( 'site-b-theme', get_stylesheet() );
	}

	public function test_a_plugin_delete_is_recorded_and_marked_not_restorable_with_its_reason(): void {
		$result = $this->call( 'stonewright/plugin-delete', [ 'plugin' => 'hello-dolly/hello.php', 'confirmation_token' => 'sentinel-token-for-the-call-9f8e' ], static function (): void {}, [ 'deleted' => true, 'plugin' => 'hello-dolly/hello.php' ] );

		self::assertSame( [ 'deleted' => true, 'plugin' => 'hello-dolly/hello.php' ], $result );
		$row = $this->row_of( 'plugin' );
		self::assertSame( [ 'plugin', 'hello-dolly/hello.php', 'stonewright/plugin-delete', 'verified' ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'] ] );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'plugin_files_deleted', $row['restorable_reason'] );
		self::assertSame( [ '', '' ], [ $row['before_ref'], $row['after_ref'] ] );
		self::assertStringContainsString( 'files cannot be restored', $row['summary'] );
		self::assertStringNotContainsString( 'sentinel-token-for-the-call-9f8e', $this->stored_text() );
		$restore = OtherFamilies::restore( $row['change_id'] );
		self::assertSame( [ 'failed', 'not_restorable' ], [ $restore['status'], $restore['detail'] ] );
	}

	public function test_a_plugin_delete_that_failed_records_nothing(): void {
		$this->call( 'stonewright/plugin-delete', [ 'plugin' => 'hello-dolly/hello.php' ], static function (): void {}, new \WP_Error( 'stonewright_plugin_active', 'No.' ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_php_execute_holds_only_the_hash_of_the_code(): void {
		$code = "update_option( 'blogname', 'marker-4f9a2c71' );\nreturn 'result-marker-91ab';";

		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		$row = $this->row_of( 'other' );
		self::assertSame( [ 'php_execute', hash( 'sha256', $code ), 'stonewright/php-execute', 'verified' ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'] ] );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'php_execute_not_undoable', $row['restorable_reason'] );
		self::assertSame( [ '', '' ], [ $row['before_ref'], $row['after_ref'] ] );
		self::assertStringContainsString( (string) strlen( $code ), $row['summary'] );
		$stored = $this->stored_text();
		self::assertStringContainsString( hash( 'sha256', $code ), $stored );
		self::assertStringNotContainsString( 'marker-4f9a2c71', $stored );
		self::assertStringNotContainsString( 'result-marker-91ab', $stored );
		self::assertStringNotContainsString( 'update_option', $stored );
		self::assertSame( [ 'failed', 'not_restorable' ], [ OtherFamilies::restore( $row['change_id'] )['status'], OtherFamilies::restore( $row['change_id'] )['detail'] ] );
	}

	public function test_php_execute_in_read_only_mode_or_blocked_or_failed_to_parse_records_nothing(): void {
		( new PhpExecute() )->execute( [ 'code' => 'return 1;', 'read_only' => true ] );
		( new PhpExecute() )->execute( [ 'code' => 'return (;' ] );
		( new PhpExecute() )->execute( [ 'code' => 'file_put_contents( "x.php", "y" ); return 1;' ] );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_php_execute_that_threw_is_recorded_as_failed(): void {
		( new PhpExecute() )->execute( [ 'code' => 'throw new \\RuntimeException( "boom-secret-text" );' ] );

		$row = $this->row_of( 'other' );
		self::assertSame( 'failed', $row['status'] );
		self::assertStringNotContainsString( 'boom-secret-text', $this->ledger_text() );
	}

	public function test_an_admin_setting_write_is_recorded_as_an_event_with_names_only(): void {
		SiteAdapter::note_admin_write( 'rest', 'stonewright_feature_flags', 'Feature flags changed: new_dashboard' );

		$row = $this->row_of( 'other' );
		self::assertSame( [ 'admin_setting', 'stonewright_feature_flags', 'stonewright/admin-settings-write', 'verified', false, 'admin_write_not_tracked' ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'], $row['restorable_reason'] ] );
		self::assertStringContainsString( 'Feature flags changed', $row['summary'] );
		self::assertSame( [ '', '' ], [ $row['before_ref'], $row['after_ref'] ] );
	}

	public function test_the_ability_switch_and_the_setup_mode_leave_an_event_when_they_change_something(): void {
		AbilityToggles::set_enabled( 'stonewright/media-upload', false );
		SetupState::persist_partial( [ 'wordpress_mode' => 'staging' ] );

		$rows = $this->ledger_rows();
		self::assertSame( [ 'stonewright_disabled_abilities', 'stonewright_mode' ], array_column( $rows, 'resource_id' ) );
		self::assertStringContainsString( 'stonewright/media-upload', $rows[0]['summary'] );
		self::assertStringContainsString( 'staging', $rows[1]['summary'] );
		self::assertSame( [ false, false ], array_column( $rows, 'restorable' ) );
	}

	public function test_a_setup_write_that_changes_nothing_records_nothing(): void {
		SetupState::persist_partial( [ 'wordpress_mode' => 'development' ] );

		self::assertSame( [], $this->rows_of_kind( 'change' ) );
	}
}

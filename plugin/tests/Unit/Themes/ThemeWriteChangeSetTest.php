<?php
/**
 * Theme file writes, theme backup restores, Customizer CSS updates and the
 * theme-file / Customizer CSS custom-code providers return a ChangeSetV1.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\CustomCode\ProviderOps;
use Stonewright\WpMcp\Abilities\Themes\ThemeBackupRestore;
use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeBackupRestore
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss
 * @covers \Stonewright\WpMcp\Abilities\CustomCode\ProviderOps
 */
final class ThemeWriteChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	private const NATIVE_GAP = [
		'reason'        => 'No typed WordPress API owns this stylesheet rule.',
		'methods_tried' => [ 'typed_api', 'admin_form' ],
	];

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		IncidentStore::reset_for_tests();

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-cs-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}\n" );
		file_put_contents( $this->theme_dir . '/notes.txt', "plain notes\n" );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
		ProviderRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'] );
		ProviderRegistry::reset_for_tests();
		IncidentStore::reset_for_tests();
		self::remove_tree( $this->theme_dir );
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				is_dir( $dir . '/' . $item ) ? self::remove_tree( $dir . '/' . $item ) : @unlink( $dir . '/' . $item );
			}
		}
		@rmdir( $dir );
	}

	/** @return array<string, mixed> */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		return end( $rows )['data'];
	}

	/** @return array<string, mixed> */
	private static function patch_args(): array {
		return [ 'path' => 'style.css', 'mode' => 'append', 'content' => "p{margin:0;}\n", 'native_gap' => self::NATIVE_GAP ];
	}

	/** Dry-run the patch and issue the operator grant a human would issue. */
	private function granted_patch( ThemeFilePatch $ability ): array {
		$dry = $ability->execute( self::patch_args() + [ 'dry_run' => true ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::issue( [ 'path' => 'style.css', 'after_sha256' => $dry['after_sha256'], 'language' => 'css', 'changed_bytes' => $dry['changed_bytes'] ] );
		self::assertIsArray( $grant, $grant instanceof \WP_Error ? $grant->get_error_message() : '' );
		return [ 'dry' => $dry, 'grant' => $grant['token'] ];
	}

	public function test_a_dry_run_plans_the_patch_and_binds_the_candidate_hash(): void {
		$result = ( new ThemeFilePatch() )->execute( self::patch_args() + [ 'dry_run' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'kind' => 'file', 'ref' => 'style.css', 'action' => 'append', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'dry_run', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( $result['before_sha256'], $change_set['before_hash'] );
		self::assertSame( $result['after_sha256'], $change_set['after_hash'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
	}

	public function test_an_apply_without_a_grant_is_planned_and_not_applied(): void {
		$result = ( new ThemeFilePatch() )->execute( self::patch_args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_grant_required', $result->get_error_code() );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unverified', $change_set['verification']['status'], 'The approval gate stopped the write; nothing failed to verify.' );
		self::assertSame( 'not_applied', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( [ 'append' ], array_column( $change_set['planned'], 'action' ) );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( '', $change_set['after_hash'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_a_granted_apply_is_verified_with_hashes_backup_and_the_grant_as_its_approval(): void {
		$ability = new ThemeFilePatch();
		$staged  = $this->granted_patch( $ability );

		$result = $ability->execute( self::patch_args() + [ 'custom_code_grant' => $staged['grant'] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $staged['dry']['before_sha256'], $change_set['before_hash'] );
		self::assertSame( $staged['dry']['after_sha256'], $change_set['after_hash'] );
		self::assertSame( hash( 'sha256', (string) file_get_contents( $this->theme_dir . '/style.css' ) ), $change_set['after_hash'], 'The hash is that of the file on disk.' );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( 'theme_backup', $change_set['rollback_recipe_ref']['kind'] );
		self::assertSame( $result['backup_ref'], $change_set['rollback_recipe_ref']['ref'] );
		self::assertSame( 'style.css', $change_set['rollback_recipe_ref']['target'] );
		self::assertSame( 'custom_code_grant', $change_set['approval_reason'] );
		self::assertStringNotContainsString( $staged['grant'], (string) wp_json_encode( $change_set ) );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
		self::assertSame( '', $this->last_row()['incident_id'] );
	}

	public function test_a_repair_passes_repair_of_through_and_resolves_the_failed_changes_incident(): void {
		$ability = new ThemeFilePatch();
		$failed  = $ability->execute( [ 'path' => 'style.css', 'mode' => 'insert_after_marker', 'marker' => '/* not there */', 'content' => 'a{b:c}', 'dry_run' => true ] );
		self::assertInstanceOf( \WP_Error::class, $failed );
		$failed_id = $failed->get_error_data()['change_set']['change_set_id'];
		self::assertSame( $failed_id, $this->last_row()['change_set_id'] );
		self::assertSame( 'failed', $failed->get_error_data()['change_set']['verification']['status'] );
		self::assertNotSame( 'resolved', IncidentStore::recent()[0]['state'] );

		$staged = $this->granted_patch( $ability );
		$result = $ability->execute( self::patch_args() + [ 'custom_code_grant' => $staged['grant'], 'repair_of' => $failed_id ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( $failed_id, $result['change_set']['repair_of'] );
		self::assertSame( $failed_id, $this->last_row()['repair_of'] );
		self::assertSame( 'resolved', IncidentStore::recent()[0]['state'] );
		self::assertSame( $this->last_row()['event_id'], IncidentStore::recent()[0]['resolution_event_id'] );
	}

	public function test_a_restore_reports_the_restored_file_without_a_recipe_to_undo_it(): void {
		$ability = new ThemeFilePatch();
		$staged  = $this->granted_patch( $ability );
		$applied = $ability->execute( self::patch_args() + [ 'custom_code_grant' => $staged['grant'] ] );
		self::assertIsArray( $applied, $applied instanceof \WP_Error ? $applied->get_error_message() : '' );

		$result = ( new ThemeBackupRestore() )->execute( [ 'backup_ref' => $applied['backup_ref'] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'kind' => 'file', 'ref' => 'style.css', 'action' => 'restore', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $staged['dry']['before_sha256'], $change_set['after_hash'], 'The restored file is the original.' );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
	}

	public function test_a_restore_of_an_unknown_backup_misses_the_planned_change(): void {
		$result = ( new ThemeBackupRestore() )->execute( [ 'backup_ref' => 'sw-theme-backup-00000000-0000-4000-8000-000000000000' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'] ?? null;
		self::assertIsArray( $change_set );
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unverified', $change_set['verification']['status'], 'The restore never ran, so nothing failed to verify.' );
		self::assertSame( 'not_applied', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertStringStartsWith( 'backup:', $change_set['planned'][0]['ref'] );
	}

	public function test_the_theme_file_provider_returns_the_change_set_of_the_ability_it_delegates_to(): void {
		$ops     = new ProviderOps();
		$staged  = $this->granted_patch( new ThemeFilePatch() );
		$arguments = [ 'provider' => 'theme-file', 'target_id' => 'style.css', 'mode' => 'append', 'code' => "p{margin:0;}\n", 'language' => 'css' ];

		$dry = $ops->execute( $arguments + [ 'action' => 'dry-run', 'native_gap' => self::NATIVE_GAP ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		self::assertValidChangeSet( $dry['change_set'] );
		self::assertSame( 'dry_run', $dry['change_set']['verification']['evidence']['outcome'] );

		$result = $ops->execute( $arguments + [ 'action' => 'apply', 'custom_code_grant' => $staged['grant'], 'repair_of' => 'cs-earlier-failure' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( 'cs-earlier-failure', $change_set['repair_of'], 'The lineage reaches the ability the provider delegates to.' );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
		self::assertSame( 'cs-earlier-failure', $this->last_row()['repair_of'] );
	}

	public function test_a_customizer_css_update_returns_its_change_set_and_a_post_snapshot_recipe(): void {
		$ability = new ThemeCustomCss();
		$css     = "body{background:#fff;}\n";
		$dry     = $ability->execute( [ 'action' => 'update', 'css' => $css, 'dry_run' => true, 'native_gap' => self::NATIVE_GAP ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		self::assertValidChangeSet( $dry['change_set'] );
		self::assertSame( 'dry_run', $dry['change_set']['verification']['evidence']['outcome'] );
		self::assertSame( [ 'custom_code' ], array_column( $dry['change_set']['planned'], 'kind' ) );
		self::assertSame( $dry['path'], $dry['change_set']['planned'][0]['ref'] );
		self::assertSame( $dry['after_sha256'], $dry['change_set']['after_hash'] );

		$blocked = $ability->execute( [ 'action' => 'update', 'css' => $css ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		$stopped = $blocked->get_error_data()['change_set'];
		self::assertValidChangeSet( $stopped );
		self::assertSame( 'not_applied', $stopped['verification']['evidence']['outcome'] );
		self::assertSame( $stopped['planned'], $stopped['missing'] );
	}

	public function test_a_read_of_customizer_css_returns_no_change_set(): void {
		$result = ( new ThemeCustomCss() )->execute( [ 'action' => 'get' ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'change_set', $result );
	}

	public function test_the_abilities_declare_the_lineage_inputs_and_the_change_set_output(): void {
		foreach ( [ new ThemeFilePatch(), new ThemeBackupRestore(), new ThemeCustomCss(), new ProviderOps() ] as $ability ) {
			foreach ( ChangeSet::input_properties() as $name => $schema ) {
				self::assertSame( $schema, $ability->input_schema()['properties'][ $name ], $ability->name() . ' ' . $name );
			}
			self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'], $ability->name() );
		}
	}
}

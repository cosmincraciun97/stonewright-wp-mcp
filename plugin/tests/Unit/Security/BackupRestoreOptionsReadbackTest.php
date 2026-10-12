<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\RollbackRecipes;

/**
 * A restore of options and theme mods reads what it wrote back, and reports a restore that did not reproduce the restore
 * point as not done, whichever caller asked for it.
 *
 * @covers \Stonewright\WpMcp\Security\Backup
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 */
final class BackupRestoreOptionsReadbackTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']                = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_theme_mods']             = [];
		$GLOBALS['stonewright_test_update_option_failures'] = [];
		$GLOBALS['stonewright_test_transients']             = [];
		$GLOBALS['stonewright_test_filters']                = [];
		$GLOBALS['stonewright_test_user_caps']              = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']        = 7;
		$GLOBALS['stonewright_test_upload_dir']             = [ 'basedir' => sys_get_temp_dir() . '/sw-options-readback', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		ChangeJournal::reset_for_tests();
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		ChangeJournal::reset_for_tests();
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_theme_mods']             = [];
		$GLOBALS['stonewright_test_update_option_failures'] = [];
		$GLOBALS['stonewright_test_user_caps']              = [];
		unset( $GLOBALS['stonewright_test_upload_dir'] );
	}

	private function make_restore_point(): string {
		update_option( 'blogname', 'Older name', false );
		update_option( 'blogdescription', 'Older tagline', false );
		set_theme_mod( 'accent', '#112233' );
		$id = Backup::snapshot_options( [ 'blogname', 'blogdescription', 'fixture_absent_option' ], [ 'accent', 'fixture_absent_mod' ], false );
		update_option( 'blogname', 'Newer name', false );
		update_option( 'blogdescription', 'Newer tagline', false );
		update_option( 'fixture_absent_option', 'created later', false );
		set_theme_mod( 'accent', '#445566' );
		set_theme_mod( 'fixture_absent_mod', 'created later' );
		return $id;
	}

	public function test_a_restore_that_reproduces_the_restore_point_is_true(): void {
		$id = $this->make_restore_point();

		self::assertTrue( Backup::restore_options( $id ) );

		self::assertSame( 'Older name', get_option( 'blogname' ) );
		self::assertSame( 'Older tagline', get_option( 'blogdescription' ) );
		self::assertSame( '#112233', get_theme_mod( 'accent' ) );
		self::assertFalse( get_option( 'fixture_absent_option', false ), 'A key that did not exist is removed.' );
		self::assertSame( 'gone', get_theme_mod( 'fixture_absent_mod', 'gone' ) );
		self::assertFalse( Backup::options_differ_from_snapshot( $id ) );
	}

	public function test_an_option_whose_write_does_not_stick_makes_the_restore_false_and_the_rest_is_still_restored(): void {
		$id                                                           = $this->make_restore_point();
		$GLOBALS['stonewright_test_update_option_failures']['blogname'] = true;

		$restored = Backup::restore_options( $id );

		self::assertFalse( $restored, 'The restore did not reproduce the restore point.' );
		self::assertSame( 'Newer name', get_option( 'blogname' ) );
		self::assertSame( 'Older tagline', get_option( 'blogdescription' ), 'The other options were still written.' );
		self::assertTrue( Backup::options_differ_from_snapshot( $id ) );
	}

	public function test_a_key_that_should_exist_after_the_restore_and_does_not_makes_it_false(): void {
		update_option( 'fixture_kept_option', 'kept', false );
		$id = Backup::snapshot_options( [ 'fixture_kept_option' ], [], false );
		delete_option( 'fixture_kept_option' );
		$GLOBALS['stonewright_test_update_option_failures']['fixture_kept_option'] = true;

		self::assertFalse( Backup::restore_options( $id ) );
		self::assertFalse( get_option( 'fixture_kept_option', false ) );
	}

	public function test_an_unknown_restore_point_is_false(): void {
		self::assertFalse( Backup::restore_options( 'snap_missing' ) );
	}

	public function test_the_brand_kit_restore_reports_the_failure_too(): void {
		$id                                                              = $this->make_restore_point();
		$GLOBALS['stonewright_test_update_option_failures']['blogdescription'] = true;

		self::assertFalse( BrandKit::restore( $id ) );
	}

	public function test_the_recipe_of_an_option_change_is_failed_when_the_restore_does_not_read_back(): void {
		$id                                                           = $this->make_restore_point();
		$GLOBALS['stonewright_test_update_option_failures']['blogname'] = true;

		$outcome = RollbackRecipes::run( [ 'recipe' => [ 'type' => 'option_restore', 'ref' => $id ], 'recipe_detail' => [ 'restore_id' => $id ] ] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'restore_failed', $outcome['detail'] );
	}

	public function test_a_rescue_rollback_of_an_option_change_is_not_a_success_when_the_restore_does_not_read_back(): void {
		HealthProbe::set_transport( static fn (): array => [ 'response' => [ 'code' => 200 ], 'body' => 'ok', 'headers' => [] ] );
		update_option( 'blogname', 'Older name', false );
		$restore = Backup::snapshot_options( [ 'blogname' ], [], false );
		update_option( 'blogname', 'Newer name', false );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/settings-update',
				'resource_type' => 'option',
				'resource_key'  => 'blogname',
				'recipe'        => [ 'type' => 'option_restore', 'ref' => $restore ],
				'recipe_detail' => [ 'restore_id' => $restore ],
				'scope'         => 'light',
			]
		);
		self::assertIsArray( $entry );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );
		$GLOBALS['stonewright_test_update_option_failures']['blogname'] = true;

		$result = RescueRollback::run( $entry['id'], [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_rollback_failed', $result->get_error_code() );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $entry['id'] )['state'], 'The change is not marked rolled back.' );
		self::assertSame( 'Newer name', get_option( 'blogname' ) );
	}
}

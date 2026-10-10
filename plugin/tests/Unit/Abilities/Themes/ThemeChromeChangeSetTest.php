<?php
/**
 * Theme chrome updates (options and theme mods behind an option snapshot) return a ChangeSetV1.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeChromeUpdate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__, 2 ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeChromeUpdate
 */
final class ThemeChromeChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_theme_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'  => 'development',
			'generate_settings' => [ 'background_color' => '#ffffff', 'font_body' => 'Inter' ],
		];
		$GLOBALS['stonewright_test_theme_mods']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_stylesheet']      = 'generatepress';
		$GLOBALS['stonewright_test_template']        = 'generatepress';
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_theme_mods']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
		IncidentStore::reset_for_tests();
	}

	/** @return array<string, mixed> */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		return end( $rows )['data'];
	}

	public function test_a_dry_run_plans_the_requested_keys_without_a_snapshot(): void {
		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => true, 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( [ [ 'kind' => 'theme_option', 'ref' => 'colors.background_color', 'action' => 'set', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'dry_run', $change_set['verification']['evidence']['outcome'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['approval_reason'] );
	}

	public function test_an_omitted_dry_run_flag_is_still_a_dry_run(): void {
		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'dry_run', $result['change_set']['verification']['evidence']['outcome'] );
	}

	public function test_a_verified_apply_hashes_the_option_state_and_offers_the_option_snapshot(): void {
		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => false, 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['effect_verified'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $change_set['before_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $change_set['after_hash'] );
		self::assertNotSame( $change_set['before_hash'], $change_set['after_hash'], 'The option value changed.' );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( [ 'kind' => 'option_snapshot', 'ref' => $result['snapshot_id'] ], $change_set['rollback_recipe_ref'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'] );
		self::assertSame( $change_set['change_set_id'], $this->last_row()['change_set_id'] );
		self::assertSame( '', $this->last_row()['incident_id'] );

		Backup::restore_options( $result['snapshot_id'] );
		self::assertSame( '#ffffff', $GLOBALS['stonewright_test_options']['generate_settings']['background_color'], 'The recipe restores the original value.' );
	}

	public function test_a_request_that_changes_nothing_is_unverified_and_not_applied(): void {
		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => false, 'colors' => [ 'background_color' => '#ffffff' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unchanged', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertFalse( $change_set['rollback_available'] );
	}

	public function test_an_unknown_key_is_refused_and_missed(): void {
		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => true, 'colors' => [ 'invented_accent' => '#ff00aa' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [ 'colors.invented_accent' ], array_column( $change_set['missing'], 'ref' ) );
		self::assertSame( 'stonewright_unknown_chrome_key', $change_set['verification']['evidence']['root_error_code'] );
	}

	public function test_production_safe_without_a_token_stops_the_write_and_names_no_approval(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => false, 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$change_set = $result->get_error_data()['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( 'not_applied', $change_set['verification']['evidence']['outcome'] );
		self::assertSame( $change_set['planned'], $change_set['missing'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertSame( '#ffffff', $GLOBALS['stonewright_test_options']['generate_settings']['background_color'] );
	}

	public function test_a_repair_names_the_change_it_repairs(): void {
		$failed = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => true, 'colors' => [ 'invented_accent' => '#ff00aa' ] ] );
		self::assertInstanceOf( \WP_Error::class, $failed );
		$failed_id = $failed->get_error_data()['change_set']['change_set_id'];

		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => false, 'colors' => [ 'background_color' => '#222222' ], 'repair_of' => $failed_id ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( $failed_id, $result['change_set']['repair_of'] );
		self::assertSame( $failed_id, $this->last_row()['repair_of'] );
	}

	public function test_the_ability_declares_the_lineage_inputs_and_the_change_set_output(): void {
		$ability = new ThemeChromeUpdate();

		foreach ( ChangeSet::input_properties() as $name => $schema ) {
			self::assertSame( $schema, $ability->input_schema()['properties'][ $name ] );
		}
		self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'] );
	}
}

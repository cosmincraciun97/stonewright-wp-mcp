<?php
/**
 * Option, menu and widget writes are recorded in the change ledger where the rescue guard opens and closes an ability call.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupSave;
use Stonewright\WpMcp\Abilities\BrandKits\ApplyBrandKit;
use Stonewright\WpMcp\Abilities\ContentModel\CptRegister;
use Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister;
use Stonewright\WpMcp\Abilities\Settings\SettingsUpdate;
use Stonewright\WpMcp\Abilities\Site\SetFrontPage;
use Stonewright\WpMcp\Abilities\System\InstructionsSet;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Abilities\Themes\ThemeChromeUpdate;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Adapters\OptionsAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * @covers \Stonewright\WpMcp\Security\RescueGuard
 * @covers \Stonewright\WpMcp\Security\Backup::snapshot_options
 * @covers \Stonewright\WpMcp\Security\Adapters\FamilyRecorder
 * @covers \Stonewright\WpMcp\Security\Adapters\OptionsAdapter
 */
final class FamilyLedgerHookTest extends FamilyLedgerTestCase {

	// -----------------------------------------------------------------------
	// A record and settle per ability.
	// -----------------------------------------------------------------------

	public function test_settings_update_is_recorded_and_settled_with_both_images(): void {
		update_option( 'blogname', 'Before' );
		update_option( 'posts_per_page', 10 );

		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After', 'posts_per_page' => 5, 'not_on_the_list' => 'x' ] ] );

		self::assertSame( [ 'updated' => [ 'blogname', 'posts_per_page' ] ], $result );
		$row = $this->only_row();
		self::assertSame( 'option', $row['family'] );
		self::assertSame( 'option', $row['resource_type'] );
		self::assertSame( 'blogname,posts_per_page', $row['resource_id'] );
		self::assertSame( 'stonewright/settings-update', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( 7, $row['actor'] );
		self::assertTrue( $row['restorable'] );
		self::assertNotNull( $row['settled_at'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertSame( 'Before', $before['options']['blogname']['value'] );
		self::assertSame( 'After', $after['options']['blogname']['value'] );
		self::assertSame( 10, $before['options']['posts_per_page']['value'] );
		self::assertSame( 5, $after['options']['posts_per_page']['value'] );
		self::assertNotSame( $row['before_sha256'], $row['after_sha256'] );
	}

	public function test_settings_update_never_images_or_writes_an_option_outside_the_allowlist(): void {
		update_option( 'blogname', 'Before' );
		update_option( 'secret_plugin_option', 'kept' );

		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After', 'secret_plugin_option' => 'overwritten', 'siteurl_backup' => 'x' ] ] );

		self::assertSame( 'kept', get_option( 'secret_plugin_option' ) );
		$stored = $this->everything_stored();
		self::assertStringNotContainsString( 'secret_plugin_option', $stored );
		self::assertStringNotContainsString( 'overwritten', $stored );
	}

	public function test_a_blocked_settings_update_changes_nothing_and_records_nothing(): void {
		update_option( 'blogname', 'Before' );

		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After', 'siteurl' => 'https://other.test' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_settings_blocked_key', $result->get_error_code() );
		self::assertSame( 'After', get_option( 'blogname' ), 'The keys before the blocked one were written, as before.' );
		$row = $this->only_row();
		self::assertSame( 'failed', $row['status'], 'The write that did happen is recorded, as a failed call.' );
		self::assertSame( 'Before', ChangeLedger::read_image( $row['change_id'], 'before' )['options']['blogname']['value'] );
	}

	public function test_a_settings_update_that_writes_nothing_records_nothing(): void {
		update_option( 'blogname', 'Same' );

		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'Same', 'unknown_key' => 'x' ] ] );
		( new SettingsUpdate() )->execute( [ 'settings' => [ 'unknown_key' => 'x' ] ] );

		self::assertSame( 0, $this->ledger_count() );
	}

	public function test_set_front_page_is_recorded_and_its_undo_restores_both_options(): void {
		$this->make_post( 12, [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'show_on_front', 'posts' );

		$result = ( new SetFrontPage() )->execute( [ 'page_id' => 12 ] );

		self::assertIsArray( $result );
		self::assertSame( 12, (int) get_option( 'page_on_front' ) );
		$row = $this->only_row();
		self::assertSame( 'stonewright/site-set-front-page', $row['ability'] );
		self::assertSame( 'page_on_front,show_on_front', $row['resource_id'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertFalse( ChangeLedger::read_image( $row['change_id'], 'before' )['options']['page_on_front']['exists'] );

		$undo = OptionsAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( 'posts', get_option( 'show_on_front' ) );
		self::assertArrayNotHasKey( 'page_on_front', $GLOBALS['stonewright_test_options'] );
	}

	public function test_system_instructions_set_is_recorded_for_both_options(): void {
		update_option( 'stonewright_custom_instructions', 'Old text' );

		( new InstructionsSet() )->execute( [ 'text' => 'New text', 'enabled' => false ] );

		$row = $this->only_row();
		self::assertSame( 'stonewright/system-instructions-set', $row['ability'] );
		self::assertSame( 'stonewright_custom_instructions,stonewright_custom_instructions_enabled', $row['resource_id'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertSame( 'Old text', $before['options']['stonewright_custom_instructions']['value'] );
		self::assertSame( 'New text', $after['options']['stonewright_custom_instructions']['value'] );
		self::assertFalse( $after['options']['stonewright_custom_instructions_enabled']['value'] );
	}

	public function test_cpt_and_taxonomy_registration_record_their_own_entry_only(): void {
		update_option( 'cptui_post_types', [ 'other' => [ 'label' => 'Others' ] ] );
		update_option( 'cptui_taxonomies', [ 'topic' => [ 'label' => 'Topics' ] ] );

		( new CptRegister() )->execute( [ 'slug' => 'book', 'singular' => 'Book', 'plural' => 'Books' ] );
		( new TaxonomyRegister() )->execute( [ 'slug' => 'genre', 'object_types' => [ 'book' ], 'singular' => 'Genre', 'plural' => 'Genres' ] );

		$rows = array_column( $this->ledger_rows(), null, 'ability' );
		self::assertCount( 2, $rows );
		self::assertSame( 'cptui_post_types:book', $rows['stonewright/cpt-register']['resource_id'] );
		self::assertSame( 'cptui_taxonomies:genre', $rows['stonewright/taxonomy-register']['resource_id'] );
		$before = ChangeLedger::read_image( $rows['stonewright/cpt-register']['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $rows['stonewright/cpt-register']['change_id'], 'after' );
		self::assertFalse( $before['entries']['cptui_post_types']['book']['exists'] );
		self::assertSame( 'Books', $after['entries']['cptui_post_types']['book']['value']['label'] );
		self::assertStringNotContainsString( 'Others', $this->everything_stored() );

		$undo = OptionsAdapter::undo( $rows['stonewright/cpt-register']['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( [ 'other' => [ 'label' => 'Others' ] ], get_option( 'cptui_post_types' ) );
		self::assertArrayHasKey( 'genre', get_option( 'cptui_taxonomies' ), 'The taxonomy row is a separate change.' );
	}

	public function test_acf_field_group_save_is_recorded_for_the_group_key(): void {
		update_option( 'stonewright_acf_field_groups', [ 'group_old' => [ 'key' => 'group_old', 'title' => 'Old' ] ] );

		$result = ( new AcfFieldGroupSave() )->execute( [ 'group' => [ 'key' => 'group_books', 'title' => 'Books', 'fields' => [ [ 'key' => 'field_isbn', 'label' => 'ISBN', 'name' => 'isbn', 'type' => 'text' ] ] ] ] );

		self::assertSame( [ 'ok' => true, 'key' => 'group_books' ], $result );
		$row = $this->only_row();
		self::assertSame( 'stonewright/acf-field-group-save', $row['ability'] );
		self::assertSame( 'stonewright_acf_field_groups:group_books', $row['resource_id'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( 'ISBN', ChangeLedger::read_image( $row['change_id'], 'after' )['entries']['stonewright_acf_field_groups']['group_books']['value']['fields'][0]['label'] );
		self::assertStringNotContainsString( 'group_old', $this->everything_stored() );
	}

	public function test_tool_profile_activation_is_recorded_although_the_ability_has_no_audit_frame_of_its_own(): void {
		update_option( 'stonewright_last_tool_profile', 'bootstrap' );
		update_option( 'stonewright_mcp_surface', 'bootstrap' );

		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'elementor-design' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$row = $this->only_row();
		self::assertSame( 'stonewright/tool-profile', $row['ability'] );
		self::assertSame( 'option', $row['family'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertStringContainsString( 'stonewright_last_tool_profile', $row['resource_id'] );
		self::assertSame( 'bootstrap', ChangeLedger::read_image( $row['change_id'], 'before' )['options']['stonewright_last_tool_profile']['value'] );
		self::assertSame( 'elementor-design', ChangeLedger::read_image( $row['change_id'], 'after' )['options']['stonewright_last_tool_profile']['value'] );
	}

	public function test_resolving_a_tool_profile_records_nothing(): void {
		( new ToolProfile() )->execute( [ 'action' => 'resolve', 'profile' => 'elementor-design' ] );

		self::assertSame( 0, $this->ledger_count() );
	}

	public function test_theme_chrome_update_is_recorded_under_the_journal_id_and_undone_from_the_ledger(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		update_option( 'generate_settings', [ 'background_color' => '#ffffff', 'font_body' => 'Inter' ] );

		$result = ( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => false, 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
		$row = $this->only_row();
		self::assertSame( 'stonewright/theme-chrome-update', $row['ability'] );
		self::assertSame( 'generate_settings', $row['resource_id'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( ChangeJournal::recent()[0]['id'], $row['change_id'], 'The journal and the ledger share one id.' );
		self::assertSame( '#ffffff', ChangeLedger::read_image( $row['change_id'], 'before' )['options']['generate_settings']['value']['background_color'] );

		$undo = OptionsAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( [ 'background_color' => '#ffffff', 'font_body' => 'Inter' ], get_option( 'generate_settings' ) );
	}

	public function test_a_dry_run_of_theme_chrome_records_nothing(): void {
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';
		$GLOBALS['stonewright_test_template']   = 'generatepress';
		update_option( 'generate_settings', [ 'background_color' => '#ffffff' ] );

		( new ThemeChromeUpdate() )->execute( [ 'theme' => 'generatepress', 'dry_run' => true, 'colors' => [ 'background_color' => '#111111' ] ] );

		self::assertSame( 0, $this->ledger_count() );
	}

	public function test_brand_kit_apply_is_recorded_with_the_kit_option_and_its_theme_mods_and_a_preview_is_not(): void {
		$id = (string) ( BrandKit::list()[0]['id'] ?? '' );
		self::assertNotSame( '', $id );
		set_theme_mod( 'stonewright_color_primary', '#123456' );

		( new ApplyBrandKit() )->execute( [ 'brand_kit_id' => $id, 'preview' => true ] );
		self::assertSame( 0, $this->ledger_count(), 'A preview takes a restore point and writes nothing.' );

		$result = ( new ApplyBrandKit() )->execute( [ 'brand_kit_id' => $id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$row = $this->only_row();
		self::assertSame( 'stonewright/brand-kit-apply', $row['ability'] );
		self::assertSame( 'option', $row['family'] );
		self::assertStringContainsString( 'stonewright_active_brand_kit', $row['resource_id'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( '#123456', $before['theme_mods']['stonewright_color_primary']['value'] );
		self::assertFalse( $before['options']['stonewright_active_brand_kit']['exists'] );

		$undo = OptionsAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( '#123456', get_theme_mod( 'stonewright_color_primary' ) );
		self::assertArrayNotHasKey( 'stonewright_active_brand_kit', $GLOBALS['stonewright_test_options'] );
	}

	// -----------------------------------------------------------------------
	// Menus and widgets, through the abilities.
	// -----------------------------------------------------------------------

	public function test_menu_and_widget_rows_carry_their_own_family(): void {
		( new \Stonewright\WpMcp\Abilities\Menu\MenuCreate() )->execute( [ 'name' => 'Main' ] );
		( new \Stonewright\WpMcp\Abilities\Widgets\WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2' ] ] );

		$families = array_column( $this->ledger_rows(), 'family', 'ability' );

		self::assertSame( [ 'stonewright/menu-create' => 'menu', 'stonewright/widget-save' => 'widget' ], $families );
	}

	// -----------------------------------------------------------------------
	// Status.
	// -----------------------------------------------------------------------

	public function test_a_failed_call_is_settled_failed_when_it_changed_the_option(): void {
		update_option( 'blogname', 'Before' );
		RescueGuard::enter( 'stonewright/settings-update', [ 'settings' => [ 'blogname' => 'After' ] ] );
		update_option( 'blogname', 'After' );

		$result = RescueGuard::leave( new \WP_Error( 'stonewright_failed', 'No.' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $this->only_row();
		self::assertSame( 'failed', $row['status'] );
		self::assertSame( 'After', ChangeLedger::read_image( $row['change_id'], 'after' )['options']['blogname']['value'] );
	}

	public function test_a_failed_call_that_changed_nothing_records_nothing(): void {
		update_option( 'blogname', 'Before' );
		RescueGuard::enter( 'stonewright/settings-update', [ 'settings' => [ 'blogname' => 'After' ] ] );

		RescueGuard::leave( new \WP_Error( 'stonewright_failed', 'No.' ) );

		self::assertSame( 0, $this->ledger_count() );
	}

	public function test_a_write_the_site_cannot_survive_is_settled_as_rolled_back_with_the_image_it_wrote(): void {
		update_option( 'generate_settings', [ 'background_color' => '#ffffff' ] );
		RescueGuard::enter( 'stonewright/theme-chrome-update', [] );
		Backup::snapshot_options( [ 'generate_settings' ], [] );
		update_option( 'generate_settings', [ 'background_color' => '#000000' ] );
		$this->site = 'broken';

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( [ 'background_color' => '#ffffff' ], get_option( 'generate_settings' ), 'The journal rolled the write back, as before.' );
		$row = $this->only_row();
		self::assertSame( 'rolled_back', $row['status'] );
		self::assertSame( ChangeJournal::recent()[0]['id'], $row['change_id'] );
		self::assertSame( '#000000', ChangeLedger::read_image( $row['change_id'], 'after' )['options']['generate_settings']['value']['background_color'], 'The after image is what the write produced.' );
	}

	public function test_a_write_made_outside_an_ability_call_is_not_recorded(): void {
		update_option( 'blogname', 'Before' );
		Backup::snapshot_options( [ 'blogname' ], [] );
		update_option( 'blogname', 'After' );
		MenuStore::create( 'Outside' );
		MenuStore::assign_location( 'primary', 7001 );

		self::assertSame( 0, $this->ledger_count() );
	}

	public function test_the_ten_entry_limit_of_the_option_restore_points_does_not_limit_the_ledger(): void {
		update_option( 'generate_settings', [ 'n' => 0 ] );
		for ( $i = 1; $i <= 12; $i++ ) {
			RescueGuard::enter( 'stonewright/theme-chrome-update', [] );
			Backup::snapshot_options( [ 'generate_settings' ], [] );
			update_option( 'generate_settings', [ 'n' => $i ] );
			RescueGuard::leave( [ 'ok' => true ] );
		}

		self::assertCount( 10, get_option( Backup::OPTION_SNAPSHOTS ), 'The restore points stay as they were.' );
		self::assertSame( 12, $this->ledger_count() );
		$rows = $this->ledger_rows();
		self::assertSame( 0, ChangeLedger::read_image( $rows[0]['change_id'], 'before' )['options']['generate_settings']['value']['n'], 'The oldest change keeps its image.' );
		self::assertSame( 11, ChangeLedger::read_image( $rows[11]['change_id'], 'before' )['options']['generate_settings']['value']['n'] );
	}

	public function test_a_write_that_throws_inside_a_frame_of_its_own_is_recorded_as_failed_and_the_exception_goes_on(): void {
		update_option( 'stonewright_last_tool_profile', 'bootstrap' );

		try {
			RescueGuard::within(
				'stonewright/tool-profile',
				[],
				static function (): void {
					update_option( 'stonewright_last_tool_profile', 'full' );
					throw new \RuntimeException( 'write failed' );
				}
			);
			self::fail( 'The exception must go on.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( 'write failed', $caught->getMessage() );
		}

		self::assertSame( 'failed', $this->only_row()['status'] );
		RescueGuard::enter( 'stonewright/settings-update', [] );
		RescueGuard::leave( [ 'ok' => true ] );
		self::assertSame( 1, $this->ledger_count(), 'The frame was closed: a later call is not mixed with it.' );
	}

	// -----------------------------------------------------------------------
	// A ledger failure never changes the write.
	// -----------------------------------------------------------------------

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function ledger_failures(): array {
		return [
			'insert refused' => [ 'refuse', 'change_ledger_record_failed' ],
			'insert throws'  => [ 'throw', 'change_ledger_record_failed' ],
			'update refused' => [ 'refuse_update', 'change_ledger_settle_failed' ],
			'update throws'  => [ 'throw_update', 'change_ledger_settle_failed' ],
		];
	}

	/**
	 * @dataProvider ledger_failures
	 */
	public function test_a_ledger_failure_is_logged_and_never_changes_the_write_or_its_result( string $failure, string $event ): void {
		$log   = $this->uploads . '/php-errors.log';
		$saved = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		$db = new class() extends PostLedgerWpdb {
			public string $mode = '';

			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					if ( 'throw' === $this->mode ) {
						throw new \RuntimeException( 'database gone' );
					}
					if ( 'refuse' === $this->mode ) {
						return false;
					}
				}
				return parent::insert( $table, $data, $format );
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					if ( 'throw_update' === $this->mode ) {
						throw new \RuntimeException( 'database gone' );
					}
					if ( 'refuse_update' === $this->mode ) {
						return false;
					}
				}
				return parent::update( $table, $data, $where, $format, $where_format );
			}
		};
		$db->unique      = $this->db->unique;
		$db->mode        = $failure;
		$GLOBALS['wpdb'] = $db;
		update_option( 'blogname', 'Before' );
		$menu = $this->make_menu( 'Main', [ [ 'a', 'One', 'https://example.test/1/', '' ] ] );

		try {
			$settings = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After' ] ] );
			$added    = ( new \Stonewright\WpMcp\Abilities\Menu\MenuAddItem() )->execute( [ 'menu_id' => $menu['menu'], 'title' => 'Two', 'url' => 'https://example.test/2/' ] );
			$widgets  = ( new \Stonewright\WpMcp\Abilities\Widgets\WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2' ] ] );
		} finally {
			ini_set( 'error_log', $saved );
		}

		self::assertStringContainsString( 'stonewright.' . $event, (string) file_get_contents( $log ), 'The failure is logged.' );
		self::assertSame( [ 'updated' => [ 'blogname' ] ], $settings );
		self::assertSame( 'After', get_option( 'blogname' ) );
		self::assertIsArray( $added );
		self::assertSame( [ 'One', 'Two' ], array_column( $this->menu_shape( $menu['menu'] ), 0 ) );
		self::assertSame( [ 'ok' => true, 'sidebar_id' => 'sidebar-1' ], $widgets );
		self::assertSame( [ 'search-2' ], wp_get_sidebars_widgets()['sidebar-1'] );
	}

	public function test_nothing_breaks_while_the_ledger_table_is_missing(): void {
		$GLOBALS['wpdb'] = new class() extends PostLedgerWpdb {
			public function get_col( $query = null, $x = 0 ) {
				if ( is_string( $query ) && str_contains( $query, 'stonewright_changes' ) ) {
					return [];
				}
				return parent::get_col( $query, $x );
			}
		};
		update_option( 'blogname', 'Before' );

		$result = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After' ] ] );
		$menu   = ( new \Stonewright\WpMcp\Abilities\Menu\MenuCreate() )->execute( [ 'name' => 'Main' ] );
		$widget = ( new \Stonewright\WpMcp\Abilities\Widgets\WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [] ] );

		self::assertSame( [ 'updated' => [ 'blogname' ] ], $result );
		self::assertIsArray( $menu );
		self::assertSame( [ 'ok' => true, 'sidebar_id' => 'sidebar-1' ], $widget );
		self::assertSame( 'After', get_option( 'blogname' ) );
		self::assertFalse( ChangeLedger::table_schema_ok() );
	}

	public function test_the_results_are_the_same_with_a_working_and_a_failing_ledger(): void {
		update_option( 'blogname', 'Before' );
		$with = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After' ] ] );
		update_option( 'blogname', 'Before' );
		$GLOBALS['wpdb'] = new class() extends PostLedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'database gone' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$saved = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->uploads . '/php-errors.log' );
		try {
			$without = ( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After' ] ] );
		} finally {
			ini_set( 'error_log', $saved );
		}

		self::assertSame( $with, $without );
	}
}

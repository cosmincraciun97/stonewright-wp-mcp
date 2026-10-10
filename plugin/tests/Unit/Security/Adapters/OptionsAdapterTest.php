<?php
/**
 * The options family in the change ledger: what is imaged, what is never stored, and the restore.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\Adapters\OptionsAdapter;
use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\OptionsAdapter
 * @covers \Stonewright\WpMcp\Security\Adapters\AdapterSupport
 */
final class OptionsAdapterTest extends FamilyLedgerTestCase {

	private const MARKER = 'SYNTHETIC-KEY-5b2c71';

	/**
	 * @param array<string, mixed> $scope
	 * @return array<string, mixed>
	 */
	private function image_of( array $scope ): array {
		return OptionsAdapter::image( $scope );
	}

	/**
	 * Run a write between enter() and leave() so that the guard records it, and return the ledger row.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function recorded( string $ability, array $args, callable $write ): array {
		RescueGuard::enter( $ability, $args );
		$write();
		RescueGuard::leave( [ 'ok' => true ] );
		return $this->only_row();
	}

	// -----------------------------------------------------------------------
	// Allowlist.
	// -----------------------------------------------------------------------

	public function test_settings_update_images_only_the_allowlisted_options_it_names(): void {
		update_option( 'blogname', 'Site A' );
		update_option( 'posts_per_page', 10 );
		update_option( 'siteurl', 'https://example.test' );
		update_option( 'unlisted_option', 'kept out' );

		$scope = OptionsAdapter::scope(
			'stonewright/settings-update',
			[ 'settings' => [ 'blogname' => 'B', 'posts_per_page' => 5, 'siteurl' => 'https://other.test', 'unlisted_option' => 'x', 'home' => 'https://other.test' ] ]
		);

		self::assertIsArray( $scope );
		$image = $this->image_of( $scope );
		self::assertSame( [ 'blogname', 'posts_per_page' ], array_keys( $image['options'] ) );
		self::assertSame( [ 'exists' => true, 'value' => 'Site A' ], $image['options']['blogname'] );
		self::assertStringNotContainsString( 'siteurl', (string) json_encode( $image ) );
		self::assertStringNotContainsString( 'unlisted_option', (string) json_encode( $image ) );
	}

	public function test_an_ability_with_no_options_family_has_no_scope(): void {
		self::assertNull( OptionsAdapter::scope( 'stonewright/content-update-page', [ 'settings' => [ 'blogname' => 'x' ] ] ) );
		self::assertNull( OptionsAdapter::snapshot_scope( 'stonewright/content-update-page', [ 'blogname' ], [] ) );
	}

	public function test_a_snapshot_scope_is_cut_to_what_the_calling_ability_may_write(): void {
		$scope = OptionsAdapter::snapshot_scope( 'stonewright/brand-kit-apply', [ 'stonewright_active_brand_kit', 'blogname', 'siteurl' ], [ 'stonewright_color_primary', 'headerBackground' ] );

		self::assertIsArray( $scope );
		$image = $this->image_of( $scope );
		self::assertSame( [ 'stonewright_active_brand_kit' ], array_keys( $image['options'] ) );
		self::assertSame( [ 'stonewright_color_primary' ], array_keys( $image['theme_mods'] ), 'A theme mod that the ability does not manage is not imaged.' );
	}

	public function test_theme_chrome_may_image_any_theme_mod_of_a_valid_name_and_the_generate_settings_option(): void {
		$scope = OptionsAdapter::snapshot_scope( 'stonewright/theme-chrome-update', [ 'generate_settings', 'blogname' ], [ 'headerBackground', '../escape', 'with space' ] );

		self::assertIsArray( $scope );
		$image = $this->image_of( $scope );
		self::assertSame( [ 'generate_settings' ], array_keys( $image['options'] ) );
		self::assertSame( [ 'headerBackground' ], array_keys( $image['theme_mods'] ) );
	}

	public function test_an_entry_of_a_shared_option_is_imaged_without_its_neighbours(): void {
		update_option( 'cptui_post_types', [ 'other' => [ 'label' => 'Others' ], 'book' => [ 'label' => 'Books' ] ] );

		$scope = OptionsAdapter::scope( 'stonewright/cpt-register', [ 'slug' => 'book' ] );

		self::assertIsArray( $scope );
		$image = $this->image_of( $scope );
		self::assertSame( [], $image['options'] );
		self::assertSame( [ 'exists' => true, 'value' => [ 'label' => 'Books' ] ], $image['entries']['cptui_post_types']['book'] );
		self::assertStringNotContainsString( 'Others', (string) json_encode( $image ) );
	}

	// -----------------------------------------------------------------------
	// Secrets.
	// -----------------------------------------------------------------------

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function secret_names(): array {
		return [
			'api key'            => [ 'mailchimp_api_key' ],
			'token'              => [ 'site_access_token' ],
			'confirmation'       => [ 'stonewright_confirmation_secret' ],
			'password'           => [ 'smtp_password' ],
			'salt'               => [ 'jwt_auth_salt' ],
			'license'            => [ 'plugin_license' ],
			'oauth'              => [ 'oauth_client' ],
			'credential'         => [ 'cloud_credentials' ],
			'camel case key'     => [ 'stripeSecretKey' ],
			'nonce'              => [ 'form_nonce' ],
			'session'            => [ 'remote_sessions' ],
		];
	}

	/**
	 * @dataProvider secret_names
	 */
	public function test_a_secret_name_is_vetoed(string $name ): void {
		self::assertTrue( OptionsAdapter::is_vetoed( $name ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function plain_names(): array {
		return [
			[ 'blogname' ],
			[ 'page_on_front' ],
			[ 'show_on_front' ],
			[ 'stonewright_custom_instructions' ],
			[ 'generate_settings' ],
			[ 'headerBackground' ],
			[ 'stonewright_color_primary' ],
		];
	}

	/**
	 * @dataProvider plain_names
	 */
	public function test_an_ordinary_name_is_not_vetoed( string $name ): void {
		self::assertFalse( OptionsAdapter::is_vetoed( $name ) );
	}

	public function test_a_secret_theme_mod_is_never_stored_not_even_as_a_hash_and_the_row_says_why(): void {
		$marker = self::MARKER;
		set_theme_mod( 'mailchimp_api_key', $marker );
		set_theme_mod( 'headerBackground', '#111111' );
		$GLOBALS['stonewright_test_stylesheet'] = 'generatepress';

		$row = $this->recorded(
			'stonewright/theme-chrome-update',
			[],
			function () use ( $marker ): void {
				\Stonewright\WpMcp\Security\Backup::snapshot_options( [], [ 'headerBackground', 'mailchimp_api_key' ] );
				set_theme_mod( 'headerBackground', '#222222' );
				set_theme_mod( 'mailchimp_api_key', $marker . '_rotated' );
			}
		);

		self::assertFalse( $row['restorable'] );
		self::assertSame( OptionsAdapter::SKIPPED_REASON, $row['restorable_reason'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		foreach ( [ $before, $after ] as $image ) {
			self::assertSame( [ 'headerBackground' ], array_keys( $image['theme_mods'] ) );
			self::assertSame( [ 'mailchimp_api_key' ], $image['vetoed'], 'The name is kept, so a reader can see what was left out.' );
		}
		$stored = $this->everything_stored();
		foreach ( [ $marker, $marker . '_rotated' ] as $value ) {
			self::assertStringNotContainsString( $value, $stored );
			self::assertStringNotContainsString( hash( 'sha256', $value ), $stored );
			self::assertStringNotContainsString( md5( $value ), $stored );
			self::assertStringNotContainsString( sha1( $value ), $stored );
			self::assertStringNotContainsString( base64_encode( $value ), $stored );
		}
	}

	public function test_a_row_of_secret_keys_only_is_recorded_with_no_image_content_and_marked(): void {
		set_theme_mod( 'cloud_api_key', 'synthetic-key-1' );

		$row = $this->recorded(
			'stonewright/theme-chrome-update',
			[],
			function (): void {
				\Stonewright\WpMcp\Security\Backup::snapshot_options( [], [ 'cloud_api_key' ] );
				set_theme_mod( 'cloud_api_key', 'synthetic-key-2' );
			}
		);

		self::assertFalse( $row['restorable'] );
		self::assertSame( 'secret_option', $row['restorable_reason'], 'The ledger refuses a row that is named after a secret.' );
		self::assertSame( 'cloud_api_key', $row['resource_id'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertSame( '', $row['before_sha256'], 'Not even a hash is kept.' );
		self::assertSame( '', $row['after_sha256'] );
		self::assertStringNotContainsString( 'synthetic-key', $this->everything_stored() );
	}

	public function test_a_credential_inside_an_allowlisted_value_masks_the_image_and_blocks_the_restore(): void {
		update_option( 'stonewright_custom_instructions', "Be kind.\npassword: hunter2-synthetic" );

		$row = $this->recorded(
			'stonewright/system-instructions-set',
			[ 'text' => 'x' ],
			static function (): void {
				update_option( 'stonewright_custom_instructions', 'Be kind.' );
			}
		);

		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertFalse( $row['restorable'] );
		self::assertStringNotContainsString( 'hunter2-synthetic', $this->everything_stored() );

		$image = [ 'v' => 1, 'kind' => 'options', 'options' => [ 'stonewright_custom_instructions' => [ 'exists' => true, 'value' => "Be kind.\n[masked line 2]" ] ], 'entries' => [], 'theme_mods' => [], 'vetoed' => [] ];
		$out   = OptionsAdapter::restore( $image, 'stonewright/system-instructions-set' );
		self::assertInstanceOf( \WP_Error::class, $out );
		self::assertSame( 'stonewright_image_masked', $out->get_error_code() );
		self::assertSame( 'Be kind.', get_option( 'stonewright_custom_instructions' ), 'A refused restore writes nothing.' );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	public function test_restore_writes_options_back_and_removes_one_that_did_not_exist(): void {
		update_option( 'show_on_front', 'posts' );
		$scope = OptionsAdapter::scope( 'stonewright/site-set-front-page' );
		self::assertIsArray( $scope );
		$before = $this->image_of( $scope );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', 12 );

		$result = OptionsAdapter::restore( $before, 'stonewright/site-set-front-page' );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [], $result['differences'] );
		self::assertSame( 'posts', get_option( 'show_on_front' ) );
		self::assertFalse( array_key_exists( 'page_on_front', $GLOBALS['stonewright_test_options'] ), 'An option that did not exist is deleted.' );
	}

	public function test_restore_of_an_entry_leaves_its_neighbours_alone(): void {
		update_option( 'cptui_post_types', [ 'other' => [ 'label' => 'Others' ] ] );
		$scope = OptionsAdapter::scope( 'stonewright/cpt-register', [ 'slug' => 'book' ] );
		self::assertIsArray( $scope );
		$before = $this->image_of( $scope );
		update_option( 'cptui_post_types', [ 'other' => [ 'label' => 'Changed elsewhere' ], 'book' => [ 'label' => 'Books' ] ] );

		$result = OptionsAdapter::restore( $before, 'stonewright/cpt-register' );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [ 'other' => [ 'label' => 'Changed elsewhere' ] ], get_option( 'cptui_post_types' ) );
	}

	public function test_restore_refuses_an_option_outside_the_allowlist_of_the_ability_and_reports_it(): void {
		update_option( 'blogname', 'Kept' );
		$image = [
			'v'          => 1,
			'kind'       => 'options',
			'options'    => [
				'blogname'      => [ 'exists' => true, 'value' => 'Planted' ],
				'show_on_front' => [ 'exists' => true, 'value' => 'page' ],
			],
			'entries'    => [],
			'theme_mods' => [],
			'vetoed'     => [],
		];

		$result = OptionsAdapter::restore( $image, 'stonewright/site-set-front-page' );

		self::assertIsArray( $result );
		self::assertSame( 'Kept', get_option( 'blogname' ) );
		self::assertSame( 'page', get_option( 'show_on_front' ) );
		self::assertContains( 'option.blogname', $result['skipped'] );
	}

	public function test_restore_refuses_an_ability_it_does_not_know_and_a_malformed_image(): void {
		$image = [ 'v' => 1, 'kind' => 'options', 'options' => [ 'blogname' => [ 'exists' => true, 'value' => 'X' ] ], 'entries' => [], 'theme_mods' => [], 'vetoed' => [] ];

		$unknown = OptionsAdapter::restore( $image, 'stonewright/content-update-page' );
		$broken  = OptionsAdapter::restore( [ 'v' => 99 ], 'stonewright/settings-update' );

		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'stonewright_option_not_allowed', $unknown->get_error_code() );
		self::assertInstanceOf( \WP_Error::class, $broken );
		self::assertSame( 'stonewright_image_invalid', $broken->get_error_code() );
		self::assertArrayNotHasKey( 'blogname', $GLOBALS['stonewright_test_options'] );
	}

	public function test_restore_reports_what_it_could_not_write_back(): void {
		update_option( 'page_on_front', 5 );
		$image = [ 'v' => 1, 'kind' => 'options', 'options' => [ 'page_on_front' => [ 'exists' => true, 'value' => 9 ] ], 'entries' => [], 'theme_mods' => [], 'vetoed' => [] ];
		$GLOBALS['stonewright_test_update_option_failures']['page_on_front'] = true;

		$result = OptionsAdapter::restore( $image, 'stonewright/site-set-front-page' );

		unset( $GLOBALS['stonewright_test_update_option_failures'] );
		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( [ 'option.page_on_front' ], $result['differences'] );
	}

	public function test_restoring_the_tool_surface_goes_through_the_registry_so_clients_see_the_revision_change(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap' );
		update_option( 'stonewright_essential_tools_mode', true );
		update_option( 'stonewright_last_tool_profile', 'bootstrap' );
		$scope = OptionsAdapter::scope( 'stonewright/tool-profile', [] );
		self::assertIsArray( $scope );
		$before = $this->image_of( $scope );
		\Stonewright\WpMcp\Core\AbilityRegistry::set_mcp_surface( 'full' );
		update_option( 'stonewright_last_tool_profile', 'full' );
		$revision = \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision();

		$result = OptionsAdapter::restore( $before, 'stonewright/tool-profile' );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], implode( ',', $result['differences'] ) );
		self::assertSame( 'bootstrap', get_option( 'stonewright_mcp_surface' ) );
		self::assertSame( 'bootstrap', get_option( 'stonewright_last_tool_profile' ) );
		self::assertGreaterThan( $revision, \Stonewright\WpMcp\Core\AbilityRegistry::surface_revision(), 'The revision only goes up.' );
		self::assertArrayNotHasKey( 'stonewright_surface_revision', $before['options'], 'The counter is not part of the image.' );
	}

	public function test_undo_reads_the_before_image_of_a_row_and_restores_it(): void {
		update_option( 'blogname', 'Before' );
		$row = $this->recorded(
			'stonewright/settings-update',
			[ 'settings' => [ 'blogname' => 'After' ] ],
			static function (): void {
				update_option( 'blogname', 'After' );
			}
		);
		self::assertTrue( $row['restorable'] );

		$result = OptionsAdapter::undo( $row['change_id'] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'Before', get_option( 'blogname' ) );
	}

	public function test_undo_refuses_a_row_that_is_not_restorable_and_a_row_of_another_resource(): void {
		set_theme_mod( 'cloud_api_key', 'synthetic-key-1' );
		$row = $this->recorded(
			'stonewright/theme-chrome-update',
			[],
			function (): void {
				\Stonewright\WpMcp\Security\Backup::snapshot_options( [], [ 'cloud_api_key' ] );
				set_theme_mod( 'cloud_api_key', 'synthetic-key-2' );
			}
		);

		$refused = OptionsAdapter::undo( $row['change_id'] );
		$missing = OptionsAdapter::undo( 'cs-' . str_repeat( 'a', 24 ) );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_change_not_restorable', $refused->get_error_code() );
		self::assertSame( 'secret_option', $refused->get_error_data()['reason'] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_change_not_found', $missing->get_error_code() );
		self::assertSame( 'synthetic-key-2', get_theme_mod( 'cloud_api_key' ), 'Nothing was written.' );
	}
}

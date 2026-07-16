<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileResolveTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_last_tool_profile'         => '',
			'stonewright_mcp_surface'               => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_resolve_returns_ordered_mcp_tools_without_side_effects(): void {
		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'resolve',
				'profile' => 'elementor-design',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['ordered'] );
		self::assertSame( 'plugin', $result['source'] );
		self::assertSame( 'elementor-design', $result['profile'] );
		self::assertSame( 'essential', $result['mcp_surface'] );
		self::assertIsArray( $result['tools'] );
		self::assertNotEmpty( $result['tools'] );
		foreach ( $result['tools'] as $name ) {
			self::assertIsString( $name );
			self::assertStringStartsWith( 'stonewright-', $name );
		}
		self::assertContains( 'stonewright-blueprint-apply', $result['tools'] );
		self::assertContains( 'stonewright-brand-kit-apply', $result['tools'] );
		// resolve must not flip last profile option.
		self::assertSame( '', (string) ( $GLOBALS['stonewright_test_options']['stonewright_last_tool_profile'] ?? '' ) );
	}

	public function test_resolve_is_read_only_but_activate_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true, 'manage_options' => false ];
		$ability = new ToolProfile();

		self::assertTrue( $ability->permission_callback( [ 'action' => 'resolve' ] ) );
		self::assertFalse( $ability->permission_callback( [ 'action' => 'activate' ] ) );
	}

	public function test_profile_tools_priority_puts_startup_and_blueprints_first(): void {
		$full = ToolProfile::profile_tools( 'full' );
		// full is not a named list — profile_tools('full') falls into default/essential path.
		// Use full list via essential + elementor-design.
		$full_list = ToolProfile::profile_tools( 'elementor-design' );
		$head      = array_slice( $full_list, 0, 15 );

		foreach (
			[
				'stonewright/context-bootstrap',
				'stonewright/task-start',
				'stonewright/tool-profile',
				'stonewright/blueprint-list',
				'stonewright/blueprint-get',
				'stonewright/blueprint-apply',
				'stonewright/brand-kit-list',
				'stonewright/brand-kit-apply',
			] as $required
		) {
			self::assertContains( $required, $head, $required . ' should be in first 15 of elementor-design' );
		}

		$gutenberg = ToolProfile::profile_tools( 'gutenberg' );
		$g_head    = array_slice( $gutenberg, 0, 12 );
		self::assertContains( 'stonewright/blueprint-apply', $g_head );
		self::assertContains( 'stonewright/brand-kit-apply', $g_head );

		$essential = ToolProfile::profile_tools( 'essential' );
		$e_head    = array_slice( $essential, 0, 12 );
		self::assertContains( 'stonewright/blueprint-apply', $e_head );
	}

	public function test_profile_tools_essential_includes_registry_essentials(): void {
		$from_profile  = ToolProfile::profile_tools( 'essential' );
		$from_registry = AbilityRegistry::essential_ability_names_for_test();
		foreach ( $from_registry as $name ) {
			self::assertContains( $name, $from_profile, $name . ' missing from essential profile_tools' );
		}
	}

	public function test_bootstrap_profile_is_registered_and_capped(): void {
		self::assertContains( 'bootstrap', ToolProfile::profile_names() );
		$names = ToolProfile::profile_tools( 'bootstrap' );
		self::assertLessThanOrEqual( 8, count( $names ) );
		self::assertContains( 'stonewright/task-start', $names );
		self::assertContains( 'stonewright/tool-profile', $names );

		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'resolve',
				'profile' => 'bootstrap',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'bootstrap', $result['profile'] );
		self::assertLessThanOrEqual( 8, count( $result['tools'] ) );
	}

	public function test_activate_non_bootstrap_profile_expands_mcp_surface(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );
		self::assertSame( 'bootstrap', AbilityRegistry::mcp_surface() );

		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'activate',
				'profile' => 'elementor-design',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'elementor-design', $result['profile'] );
		// Leaving bootstrap must expand public tools/list surface.
		self::assertSame( 'essential', AbilityRegistry::mcp_surface() );
	}

	public function test_activate_full_profile_expands_to_full_surface(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );
		$ability = new ToolProfile();
		$result  = $ability->execute(
			[
				'action'  => 'activate',
				'profile' => 'full',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'full', AbilityRegistry::mcp_surface() );
	}

	public function test_suggest_profile_routes_admin_ops_terms(): void {
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'moderate spam comments on the blog' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'create a new editor user account' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'add an application password for the api user' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'update footer widgets in sidebar' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'restore the previous revision of the about page' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'run site health tests' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'switch the active theme' ) );
		self::assertSame( 'site-admin', ToolProfile::suggest_profile( 'edit theme custom css' ) );
	}

	public function test_suggest_profile_routes_wc_reads_to_content_model(): void {
		self::assertSame( 'content-model', ToolProfile::suggest_profile( 'show woocommerce sales report for last month' ) );
		self::assertSame( 'content-model', ToolProfile::suggest_profile( 'list woocommerce orders' ) );
	}

	public function test_site_admin_profile_contains_wave3_admin_ops(): void {
		$names = ToolProfile::profile_tools( 'site-admin' );
		foreach (
			[
				'stonewright/comment-list',
				'stonewright/comment-update',
				'stonewright/comment-delete',
				'stonewright/user-list',
				'stonewright/user-create',
				'stonewright/user-delete',
				'stonewright/user-app-passwords',
				'stonewright/widget-list',
				'stonewright/widget-save',
				'stonewright/widget-delete',
				'stonewright/settings-get',
				'stonewright/settings-update',
				'stonewright/theme-list',
				'stonewright/theme-activate',
				'stonewright/theme-custom-css',
				'stonewright/plugin-activate',
				'stonewright/plugin-deactivate',
				'stonewright/plugin-delete',
				'stonewright/post-revision-list',
				'stonewright/post-revision-get',
				'stonewright/post-revision-restore',
				'stonewright/site-health-test',
				'stonewright/search-query',
				'stonewright/oembed-resolve',
			] as $name
		) {
			self::assertContains( $name, $names, $name );
		}
	}

	public function test_content_model_profile_contains_wc_reads(): void {
		$names = ToolProfile::profile_tools( 'content-model' );
		foreach ( [ 'stonewright/wc-product-list', 'stonewright/wc-order-list', 'stonewright/wc-sales-report' ] as $name ) {
			self::assertContains( $name, $names, $name );
		}
	}
}

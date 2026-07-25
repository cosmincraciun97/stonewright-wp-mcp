<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Pages\DesignStudioPage;

/**
 * Menu, capability, and markup contract for the Design Studio admin page.
 *
 * The page itself owns no design logic: it renders the shell, the view tabs,
 * and the mount point the Design Studio script boots into. These tests pin the
 * parts other code depends on — the slug, the capability, the navigation
 * position, and the boot payload keys.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\DesignStudioPage
 */
final class DesignStudioPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']  = 7;
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_user_meta']        = [];
		$GLOBALS['stonewright_test_submenu_pages']    = [];
		unset( $_GET['view'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		unset( $_GET['view'] );
	}

	public function test_slug_and_capability_are_stable(): void {
		self::assertSame( 'stonewright-design-studio', DesignStudioPage::SLUG );
		self::assertSame( 'manage_options', DesignStudioPage::CAPABILITY );
	}

	public function test_submenu_registers_under_stonewright_with_manage_options(): void {
		DesignStudioPage::add_submenu();

		$registered = $GLOBALS['stonewright_test_submenu_pages'][ DesignStudioPage::SLUG ] ?? null;

		self::assertIsArray( $registered );
		self::assertSame( 'stonewright', $registered['parent'] );
		self::assertSame( 'manage_options', $registered['capability'] );
	}

	public function test_design_studio_appears_before_blueprints_in_the_shell(): void {
		$slugs = array_keys( AdminShell::pages() );

		self::assertContains( DesignStudioPage::SLUG, $slugs );
		self::assertContains( 'stonewright-blueprints', $slugs );
		self::assertLessThan(
			array_search( 'stonewright-blueprints', $slugs, true ),
			array_search( DesignStudioPage::SLUG, $slugs, true )
		);
	}

	public function test_design_studio_lives_in_the_design_library_group(): void {
		$group = null;
		foreach ( AdminShell::menu_groups() as $candidate ) {
			if ( 'design-library' === $candidate['id'] ) {
				$group = $candidate;
			}
		}

		self::assertIsArray( $group );
		self::assertArrayHasKey( DesignStudioPage::SLUG, $group['pages'] );
	}

	public function test_render_refuses_users_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		DesignStudioPage::render();
	}

	public function test_render_outputs_the_mount_point_and_view_tabs(): void {
		ob_start();
		DesignStudioPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-sw-design-studio', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );

		foreach ( DesignStudioPage::VIEWS as $view ) {
			self::assertStringContainsString( 'data-sw-view="' . $view . '"', $html );
		}
	}

	public function test_render_marks_the_requested_view_current(): void {
		$_GET['view'] = 'quality';

		ob_start();
		DesignStudioPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-sw-current-view="quality"', $html );
	}

	public function test_render_falls_back_to_overview_for_an_unknown_view(): void {
		$_GET['view'] = 'not-a-view';

		ob_start();
		DesignStudioPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-sw-current-view="overview"', $html );
	}

	public function test_boot_payload_carries_rest_root_nonce_view_and_capabilities(): void {
		$payload = DesignStudioPage::boot_payload();

		foreach ( [ 'restRoot', 'nonce', 'view', 'views', 'activeDirection', 'can' ] as $key ) {
			self::assertArrayHasKey( $key, $payload );
		}

		self::assertStringContainsString( 'stonewright/v1/design-studio', (string) $payload['restRoot'] );
		self::assertNotSame( '', (string) $payload['nonce'] );
		self::assertSame( 'overview', $payload['view'] );
		self::assertSame( DesignStudioPage::VIEWS, $payload['views'] );
		self::assertArrayHasKey( 'manageDesign', $payload['can'] );
		self::assertArrayHasKey( 'manageOptions', $payload['can'] );
	}

	public function test_boot_payload_reports_no_active_direction_when_none_is_set(): void {
		$payload = DesignStudioPage::boot_payload();

		self::assertNull( $payload['activeDirection'] );
	}
}

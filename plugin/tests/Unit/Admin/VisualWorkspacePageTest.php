<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Pages\VisualWorkspacePage;

/**
 * Contract for the admin host of the Stonewright Visual workspace.
 *
 * The page owns no editor logic. It resolves which post the workspace targets,
 * proves the current user may edit that post, renders the three regions the
 * browser bundle mounts into, and hands the bundle a boot payload that carries
 * the active direction's hash rather than its contract.
 *
 * The enqueue path itself calls `wp_localize_script()`, which the unit harness
 * does not stub, so the page-scoping guarantees are asserted by reading the
 * shipped `AdminBootstrap` source — the same approach `DesignStudioAssetsTest`
 * takes.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\VisualWorkspacePage
 */
final class VisualWorkspacePageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts' => true,
			'edit_post'  => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$GLOBALS['stonewright_test_posts']           = [];
		unset( $_GET['post_id'], $_GET['editor'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$GLOBALS['stonewright_test_posts']           = [];
		unset( $_GET['post_id'], $_GET['editor'] );
	}

	private static function plugin_dir(): string {
		return dirname( __DIR__, 3 );
	}

	private static function css(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/visual-workspace.css' );
	}

	private static function bootstrap(): string {
		return (string) file_get_contents( self::plugin_dir() . '/includes/Admin/AdminBootstrap.php' );
	}

	/**
	 * Registers a fake post the capability and editor-context helpers can read.
	 *
	 * @param array<string, mixed> $meta Post meta to expose.
	 */
	private function fake_post( int $id, array $meta = [] ): void {
		$post                                       = new \stdClass();
		$post->ID                                   = $id;
		$post->post_title                           = 'Landing page';
		$post->post_type                            = 'page';
		$post->post_status                          = 'publish';
		$post->meta                                 = $meta;
		$GLOBALS['stonewright_test_posts'][ $id ]   = $post;
	}

	// -----------------------------------------------------------------
	// Identity and navigation
	// -----------------------------------------------------------------

	public function test_slug_and_capability_are_stable(): void {
		self::assertSame( 'stonewright-visual-workspace', VisualWorkspacePage::SLUG );
		self::assertSame( 'edit_posts', VisualWorkspacePage::CAPABILITY );
	}

	public function test_submenu_registers_under_stonewright_with_the_edit_capability(): void {
		VisualWorkspacePage::add_submenu();

		$registered = $GLOBALS['stonewright_test_submenu_pages'][ VisualWorkspacePage::SLUG ] ?? null;

		self::assertIsArray( $registered );
		self::assertSame( 'stonewright', $registered['parent'] );
		self::assertSame( 'edit_posts', $registered['capability'] );
	}

	public function test_the_workspace_sits_next_to_the_design_studio_in_the_shell(): void {
		$slugs = array_keys( AdminShell::pages() );

		self::assertContains( VisualWorkspacePage::SLUG, $slugs );
		self::assertSame(
			array_search( 'stonewright-design-studio', $slugs, true ) + 1,
			array_search( VisualWorkspacePage::SLUG, $slugs, true ),
			'The workspace is the second entry of the design group, right after the Design Studio.'
		);
	}

	// -----------------------------------------------------------------
	// Request parsing
	// -----------------------------------------------------------------

	public function test_a_missing_post_id_resolves_to_zero(): void {
		self::assertSame( 0, VisualWorkspacePage::current_post_id() );
	}

	/**
	 * @dataProvider provide_rejected_post_ids
	 */
	public function test_only_positive_integers_are_accepted_as_post_ids( string $raw ): void {
		$_GET['post_id'] = $raw;

		self::assertSame( 0, VisualWorkspacePage::current_post_id() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_rejected_post_ids(): array {
		return [
			'zero'      => [ '0' ],
			'negative'  => [ '-12' ],
			'text'      => [ 'latest' ],
			'empty'     => [ '' ],
			'float'     => [ '4.5' ],
			'injection' => [ '9 OR 1=1' ],
		];
	}

	public function test_a_positive_post_id_survives_parsing(): void {
		$_GET['post_id'] = '412';

		self::assertSame( 412, VisualWorkspacePage::current_post_id() );
	}

	public function test_the_editor_parameter_is_an_allowlist_defaulting_to_auto(): void {
		self::assertSame( 'auto', VisualWorkspacePage::requested_editor() );

		$_GET['editor'] = 'gutenberg';
		self::assertSame( 'gutenberg', VisualWorkspacePage::requested_editor() );

		$_GET['editor'] = 'wordpad';
		self::assertSame( 'auto', VisualWorkspacePage::requested_editor(), 'Unknown editors fall back to runtime detection.' );
	}

	public function test_the_url_helper_carries_the_post_and_the_editor_context(): void {
		$url = VisualWorkspacePage::url( 412, 'elementor-v3' );

		self::assertStringContainsString( 'page=stonewright-visual-workspace', $url );
		self::assertStringContainsString( 'post_id=412', $url );
		self::assertStringContainsString( 'editor=elementor-v3', $url );
	}

	public function test_the_url_helper_omits_an_unusable_post_id(): void {
		$url = VisualWorkspacePage::url( 0, 'auto' );

		self::assertStringContainsString( 'page=stonewright-visual-workspace', $url );
		self::assertStringNotContainsString( 'post_id=', $url );
	}

	// -----------------------------------------------------------------
	// Capability
	// -----------------------------------------------------------------

	public function test_render_refuses_users_without_the_edit_capability(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		VisualWorkspacePage::render();
	}

	public function test_render_refuses_a_post_the_user_may_not_edit(): void {
		$this->fake_post( 412 );
		$_GET['post_id'] = '412';

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap ): bool {
			return 'edit_posts' === $cap;
		};

		try {
			$this->expectException( \RuntimeException::class );
			VisualWorkspacePage::render();
		} finally {
			unset( $GLOBALS['stonewright_test_user_can_callback'] );
		}
	}

	public function test_render_asks_for_a_post_instead_of_dying_when_none_was_given(): void {
		ob_start();
		VisualWorkspacePage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-sw-visual-picker', $html );
		self::assertStringNotContainsString( 'data-sw-visual-workspace', $html, 'Nothing mounts until a post is chosen.' );
	}

	// -----------------------------------------------------------------
	// Boot payload
	// -----------------------------------------------------------------

	public function test_boot_payload_carries_everything_the_bundle_needs(): void {
		$this->fake_post( 412 );
		$payload = VisualWorkspacePage::boot_payload( 412, 'auto', null );

		foreach ( [ 'restBase', 'nonce', 'postId', 'editorKind', 'direction', 'can' ] as $key ) {
			self::assertArrayHasKey( $key, $payload );
		}

		self::assertStringContainsString( 'stonewright/v1', (string) $payload['restBase'] );
		self::assertStringNotContainsString( 'design-studio', (string) $payload['restBase'], 'The bundle appends its own route segment.' );
		self::assertNotSame( '', (string) $payload['nonce'] );
		self::assertSame( 412, $payload['postId'] );
		self::assertSame( 'auto', $payload['editorKind'] );
		self::assertArrayHasKey( 'editPost', $payload['can'] );
		self::assertArrayHasKey( 'manageDesign', $payload['can'] );
	}

	public function test_boot_payload_reports_no_direction_when_none_is_active(): void {
		$payload = VisualWorkspacePage::boot_payload( 412, 'auto', null );

		self::assertNull( $payload['direction'] );
	}

	public function test_the_direction_summary_passes_the_hash_and_never_the_contract(): void {
		$summary = VisualWorkspacePage::direction_summary(
			[
				'id'            => 9,
				'slug'          => 'quarry',
				'status'        => 'active',
				'revision'      => 4,
				'contract_hash' => 'sha256:9f2c',
				'source_type'   => 'authored',
				'updated_at'    => '2026-07-24 10:00:00',
				'contract'      => [
					'identity' => [ 'name' => 'Quarry' ],
					'tokens'   => [ 'color' => [ 'ink' => '#101010' ] ],
				],
			]
		);

		self::assertIsArray( $summary );
		self::assertSame( 'sha256:9f2c', $summary['contract_hash'] );
		self::assertSame( 'Quarry', $summary['name'] );
		self::assertArrayNotHasKey( 'contract', $summary, 'The workspace receives the hash, not the source contract.' );
		self::assertArrayNotHasKey( 'tokens', $summary );
	}

	public function test_the_direction_summary_is_null_without_an_active_record(): void {
		self::assertNull( VisualWorkspacePage::direction_summary( null ) );
	}

	// -----------------------------------------------------------------
	// Editor context
	// -----------------------------------------------------------------

	public function test_editor_context_reports_elementor_for_a_builder_post(): void {
		$this->fake_post( 412, [ '_elementor_edit_mode' => 'builder' ] );

		$context = VisualWorkspacePage::editor_context( 412 );

		self::assertSame( 'elementor', $context['builder'] );
		self::assertNotSame( '', $context['label'] );
	}

	public function test_editor_context_reports_the_block_editor_for_a_plain_post(): void {
		$this->fake_post( 413 );

		self::assertSame( 'block-editor', VisualWorkspacePage::editor_context( 413 )['builder'] );
	}

	public function test_editor_context_stays_unknown_for_a_missing_post(): void {
		self::assertSame( 'unknown', VisualWorkspacePage::editor_context( 999 )['builder'] );
	}

	// -----------------------------------------------------------------
	// Rendered regions
	// -----------------------------------------------------------------

	private function render_for_post( int $post_id ): string {
		$this->fake_post( $post_id, [ '_elementor_edit_mode' => 'builder' ] );
		$_GET['post_id'] = (string) $post_id;

		ob_start();
		VisualWorkspacePage::render();

		return (string) ob_get_clean();
	}

	public function test_render_emits_the_three_workspace_regions(): void {
		$html = $this->render_for_post( 412 );

		self::assertStringContainsString( 'data-sw-visual-workspace', $html );
		self::assertStringContainsString( 'data-sw-visual-header', $html );
		self::assertStringContainsString( 'data-sw-visual-canvas', $html );
		self::assertStringContainsString( 'data-sw-visual-inspector', $html );
	}

	public function test_render_states_the_post_and_editor_context_in_the_header(): void {
		$html = $this->render_for_post( 412 );

		self::assertStringContainsString( 'Landing page', $html );
		self::assertStringContainsString( 'data-sw-visual-editor="elementor"', $html );
	}

	public function test_the_inspector_is_a_labelled_drawer_with_a_toggle(): void {
		$html = $this->render_for_post( 412 );

		self::assertStringContainsString( 'data-sw-visual-inspector-toggle', $html );
		self::assertStringContainsString( 'aria-expanded=', $html );
		self::assertStringContainsString( 'aria-controls="sw-visual-inspector"', $html );
		self::assertStringContainsString( 'id="sw-visual-inspector"', $html );
	}

	public function test_render_announces_workspace_state_through_a_live_region(): void {
		$html = $this->render_for_post( 412 );

		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'role="status"', $html );
	}

	public function test_render_carries_no_emoji_or_dingbats(): void {
		self::assertDoesNotMatchRegularExpression(
			'/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u',
			$this->render_for_post( 412 )
		);
	}

	public function test_render_reports_a_missing_bundle_instead_of_pretending_to_work(): void {
		$html = $this->render_for_post( 412 );

		if ( VisualWorkspacePage::bundle_ready( VisualWorkspacePage::bundle_path() ) ) {
			self::assertStringNotContainsString( 'data-sw-visual-missing', $html );
		} else {
			self::assertStringContainsString( 'data-sw-visual-missing', $html );
		}
	}

	// -----------------------------------------------------------------
	// Packaged browser bundle
	// -----------------------------------------------------------------

	public function test_bundle_readiness_needs_a_readable_non_empty_file(): void {
		$dir  = sys_get_temp_dir() . '/sw-visual-' . uniqid( '', false );
		$file = $dir . '/workspace-browser.js';
		mkdir( $dir, 0o777, true );

		try {
			self::assertFalse( VisualWorkspacePage::bundle_ready( $file ), 'A missing bundle is not ready.' );

			file_put_contents( $file, '' );
			self::assertFalse( VisualWorkspacePage::bundle_ready( $file ), 'An empty bundle is not ready.' );

			file_put_contents( $file, 'var StonewrightVisual = (() => {})();' );
			self::assertTrue( VisualWorkspacePage::bundle_ready( $file ) );
		} finally {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
	}

	public function test_the_bundle_path_points_at_the_packaged_asset(): void {
		self::assertStringEndsWith( 'assets/visual/workspace-browser.js', VisualWorkspacePage::bundle_path() );
	}

	// -----------------------------------------------------------------
	// Enqueue scoping (source-read, see class docblock)
	// -----------------------------------------------------------------

	public function test_the_browser_bundle_is_scoped_to_this_page_only(): void {
		self::assertMatchesRegularExpression(
			'/if \( VisualWorkspacePage::SLUG === \$page \) \{.*?assets\/visual\/workspace-browser\.js.*?wp_localize_script\(.*?stonewrightVisualWorkspace.*?\}/s',
			self::bootstrap(),
			'The workspace bundle and its payload load only on the workspace page.'
		);
	}

	public function test_the_workspace_stylesheet_is_page_scoped(): void {
		self::assertStringContainsString(
			"'stonewright-visual-workspace' => 'visual-workspace.css'",
			self::bootstrap()
		);
	}

	public function test_the_page_is_registered_by_the_admin_bootstrap(): void {
		self::assertStringContainsString( 'VisualWorkspacePage::register();', self::bootstrap() );
	}

	// -----------------------------------------------------------------
	// Stylesheet
	// -----------------------------------------------------------------

	public function test_the_stylesheet_ships_with_the_plugin(): void {
		self::assertFileExists( self::plugin_dir() . '/assets/admin/visual-workspace.css' );
	}

	public function test_the_stylesheet_only_uses_shared_semantic_colour_tokens(): void {
		$css = preg_replace( '#/\*.*?\*/#s', '', self::css() );
		$css = is_string( $css ) ? $css : '';

		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css );
		self::assertDoesNotMatchRegularExpression( '/\brgba?\s*\(\s*\d/', $css );
		self::assertStringContainsString( 'var(--sw-surface', $css );
		self::assertStringContainsString( 'var(--sw-border', $css );
		self::assertStringContainsString( 'var(--sw-text', $css );
	}

	public function test_the_stylesheet_keeps_focus_visible_and_honours_reduced_motion(): void {
		$css = self::css();

		self::assertStringContainsString( ':focus-visible', $css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	public function test_the_inspector_becomes_a_drawer_at_1024px_and_below(): void {
		$css = self::css();

		self::assertStringContainsString( '@media (max-width: 1024px)', $css );
		self::assertDoesNotMatchRegularExpression(
			'/\bmin-width:\s*(?:[4-9]\d{2}|\d{4,})px\s*;/',
			$css,
			'No element may claim a minimum width that overflows a 375px viewport.'
		);
	}

	public function test_the_stylesheet_carries_no_decorative_glyphs(): void {
		self::assertDoesNotMatchRegularExpression(
			'/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u',
			self::css()
		);
	}

	// -----------------------------------------------------------------
	// Page chrome script
	// -----------------------------------------------------------------

	private static function js(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/visual-workspace.js' );
	}

	public function test_the_page_script_is_strict_mode_and_dependency_free(): void {
		$js = self::js();

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringNotContainsString( 'jQuery', $js );
	}

	public function test_the_drawer_keeps_aria_state_and_focus_in_sync(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-visual-inspector-toggle', $js );
		self::assertStringContainsString( 'aria-expanded', $js );
		self::assertStringContainsString( "'Escape'", $js );
		self::assertStringContainsString( 'focus()', $js );
	}

	public function test_the_page_script_never_uses_native_dialogs_or_markup_injection(): void {
		$js = self::js();

		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])confirm\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])alert\s*\(/', $js );
		self::assertStringNotContainsString( 'innerHTML', $js );
		self::assertStringNotContainsString( 'document.' . 'write', $js );
	}

	// -----------------------------------------------------------------
	// Entry point from the Design Studio
	// -----------------------------------------------------------------

	public function test_the_design_studio_boot_payload_carries_the_workspace_url(): void {
		$payload = \Stonewright\WpMcp\Admin\Pages\DesignStudioPage::boot_payload();

		self::assertArrayHasKey( 'visualWorkspaceUrl', $payload );
		self::assertStringContainsString( 'page=stonewright-visual-workspace', (string) $payload['visualWorkspaceUrl'] );
	}

	public function test_the_design_studio_offers_a_link_into_the_workspace(): void {
		$js = (string) file_get_contents( self::plugin_dir() . '/assets/admin/design-studio.js' );

		self::assertStringContainsString( 'visualWorkspaceUrl', $js );
		self::assertStringContainsString( 'Open Visual Workspace', $js );
		self::assertStringContainsString( 'post_id=', $js );
		self::assertStringContainsString( 'editor=', $js );
	}

	// -----------------------------------------------------------------
	// Packaging script
	// -----------------------------------------------------------------

	public function test_package_verification_knows_about_the_staged_bundle(): void {
		$script = (string) file_get_contents( dirname( self::plugin_dir() ) . '/scripts/package-verify.mjs' );

		self::assertStringContainsString( 'assets/visual/workspace-browser.js', $script );
		self::assertStringContainsString( '--require-visual-bundle', $script );
		self::assertStringContainsString( 'node_modules', $script, 'Node-only dependencies must never enter the archive.' );
	}
}

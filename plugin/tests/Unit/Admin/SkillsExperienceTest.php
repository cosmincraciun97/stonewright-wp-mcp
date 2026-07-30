<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SkillsPage;

/**
 * The skill lifecycle experience: the shell the page renders, the assets it
 * loads, and the guarantees those assets have to keep.
 *
 * `AdminBootstrap::enqueue_assets()` calls `wp_localize_script()`, which the
 * unit harness does not stub, so the enqueue path is asserted by reading the
 * shipped source the same way `DesignStudioAssetsTest` does.
 *
 * @covers \Stonewright\WpMcp\Admin\SkillsPage
 */
final class SkillsExperienceTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;

		$GLOBALS['stonewright_test_user_caps']  = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']    = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['wpdb']                        = $this->make_wpdb();
		$_GET                                   = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$_GET                                   = [];
	}

	private static function plugin_dir(): string {
		return dirname( __DIR__, 3 );
	}

	private static function css(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/skills-memory.css' );
	}

	private static function js(): string {
		return (string) file_get_contents( self::plugin_dir() . '/assets/admin/skills.js' );
	}

	private static function bootstrap(): string {
		return (string) file_get_contents( self::plugin_dir() . '/includes/Admin/AdminBootstrap.php' );
	}

	private function render(): string {
		ob_start();
		SkillsPage::render();

		return (string) ob_get_clean();
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			/** @var array<int, array<string, mixed>> */
			public array $rows = [];

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): ?string {
				return 'wp_stonewright_skills';
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return $this->rows[0] ?? null;
			}
		};
	}

	// ---------------------------------------------------------------
	// The page shell
	// ---------------------------------------------------------------

	public function test_the_page_renders_the_studio_shell(): void {
		$html = $this->render();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'data-sw-skills', $html );
		self::assertStringContainsString( 'role="tablist"', $html );
		self::assertStringContainsString( 'role="tab"', $html );
		self::assertStringContainsString( 'role="tabpanel"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( 'data-sw-skills-status', $html );
		self::assertStringContainsString( '<noscript>', $html );

		foreach ( SkillsPage::VIEWS as $view ) {
			self::assertStringContainsString( 'data-sw-view="' . $view . '"', $html );
			self::assertStringContainsString( 'data-sw-panel="' . $view . '"', $html );
		}
	}

	public function test_the_catalog_view_is_the_default_and_unknown_views_fall_back_to_it(): void {
		self::assertSame( 'catalog', SkillsPage::current_view() );

		$_GET['view'] = 'trash';
		self::assertSame( 'trash', SkillsPage::current_view() );

		$_GET['view'] = 'delete-everything';
		self::assertSame( 'catalog', SkillsPage::current_view() );
	}

	public function test_the_boot_payload_carries_everything_the_script_needs(): void {
		$payload = SkillsPage::boot_payload();

		self::assertArrayHasKey( 'restRoot', $payload );
		self::assertArrayHasKey( 'nonce', $payload );
		self::assertSame( 'catalog', $payload['view'] );
		self::assertSame( SkillsPage::VIEWS, $payload['views'] );
		self::assertSame( 'development', $payload['mode'] );
		self::assertTrue( $payload['can']['manageOptions'] );
		self::assertStringContainsString( 'skills-studio', (string) $payload['restRoot'] );
	}

	public function test_the_boot_payload_reports_production_safe_so_the_ui_can_ask_for_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		self::assertSame( 'production-safe', SkillsPage::boot_payload()['mode'] );
	}

	public function test_the_catalog_no_longer_ships_a_form_per_card(): void {
		$GLOBALS['wpdb']->rows = [
			[
				'id'             => 7,
				'slug'           => 'elementor-native',
				'title'          => 'Elementor Native',
				'description'    => 'Use when building Elementor sections.',
				'content'        => '# Elementor Native',
				'enabled'        => 1,
				'enable_agentic' => 1,
				'enable_prompt'  => 1,
				'source'         => 'user',
				'status'         => 'active',
			],
		];

		$html = $this->render();

		self::assertStringNotContainsString( 'data-confirm', $html, 'Confirmation belongs in the review drawer, not a native dialog.' );
		self::assertStringNotContainsString( 'form-table', $html, 'The long form flow is replaced by the studio shell.' );
		self::assertStringNotContainsString( 'stonewright_skill_delete', $html, 'Deletion goes through the reviewed REST lifecycle.' );
		self::assertSame( 1, substr_count( $html, '<form ' ), 'Only the editor view posts a form.' );
	}

	public function test_the_editor_view_still_offers_a_no_javascript_write_path(): void {
		$_GET['view'] = 'editor';

		$html = $this->render();

		self::assertStringContainsString( 'stonewright_skill_save', $html );
		self::assertStringContainsString( 'name="title"', $html );
		self::assertStringContainsString( 'name="slug"', $html );
		self::assertStringContainsString( 'name="content"', $html );
		self::assertStringContainsString( 'name="enabled"', $html );
		self::assertStringContainsString( 'name="enable_agentic"', $html );
		self::assertStringContainsString( 'name="enable_prompt"', $html );
	}

	public function test_the_page_never_inlines_style_or_script_blocks(): void {
		$html = $this->render();

		self::assertStringNotContainsString( '<style>', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertDoesNotMatchRegularExpression(
			'/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u',
			$html,
			'The skills page must not render emoji or dingbats.'
		);
	}

	// ---------------------------------------------------------------
	// The stylesheet
	// ---------------------------------------------------------------

	public function test_both_assets_ship_with_the_plugin(): void {
		self::assertFileExists( self::plugin_dir() . '/assets/admin/skills-memory.css' );
		self::assertFileExists( self::plugin_dir() . '/assets/admin/skills.js' );
	}

	public function test_the_stylesheet_only_uses_shared_semantic_colour_tokens(): void {
		$css = preg_replace( '#/\*.*?\*/#s', '', self::css() );
		$css = is_string( $css ) ? $css : '';

		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'Skills CSS must not hard-code colours.' );
		self::assertDoesNotMatchRegularExpression( '/\brgba?\s*\(\s*\d/', $css, 'Skills CSS must not hard-code colour channels.' );
		self::assertStringContainsString( 'var(--sw-surface', $css );
		self::assertStringContainsString( 'var(--sw-border', $css );
		self::assertStringContainsString( 'var(--sw-text', $css );
	}

	public function test_the_stylesheet_keeps_focus_visible_and_honours_reduced_motion(): void {
		$css = self::css();

		self::assertStringContainsString( ':focus-visible', $css );
		self::assertStringContainsString( 'outline:', $css );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	public function test_the_stylesheet_is_single_column_before_it_is_two_column(): void {
		$css = self::css();

		self::assertStringContainsString( '@media (min-width: 960px)', $css, 'The inspector becomes two-column at a min-width breakpoint, so the 375px layout is the default.' );
		self::assertDoesNotMatchRegularExpression( '/\bmin-width:\s*(?:[4-9]\d{2}|\d{4,})px\s*;/', $css, 'No element may claim a minimum width that overflows a 375px viewport.' );
	}

	public function test_the_stylesheet_carries_no_decorative_glyphs(): void {
		self::assertDoesNotMatchRegularExpression(
			'/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u',
			self::css(),
			'Skills CSS must not inject emoji or dingbat content.'
		);
	}

	// ---------------------------------------------------------------
	// The script
	// ---------------------------------------------------------------

	public function test_the_script_is_a_strict_mode_module_with_no_dependencies(): void {
		$js = self::js();

		self::assertStringContainsString( "'use strict'", $js );
		self::assertStringNotContainsString( 'jQuery', $js );
		self::assertStringNotContainsString( '$(', $js );
	}

	public function test_the_script_boots_from_the_localized_payload(): void {
		$js = self::js();

		self::assertStringContainsString( 'window.stonewrightSkills', $js );
		self::assertStringContainsString( 'restRoot', $js );
		self::assertStringContainsString( 'data-sw-skills', $js );
	}

	public function test_every_write_request_sends_the_rest_nonce(): void {
		$js = self::js();

		self::assertStringContainsString( "'X-WP-Nonce'", $js );
		self::assertStringContainsString( "credentials: 'same-origin'", $js );
		self::assertStringContainsString( "method: 'POST'", $js );
		self::assertStringContainsString( "'DELETE'", $js );
	}

	public function test_the_script_never_uses_native_browser_dialogs(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'window.confirm', $js );
		self::assertStringNotContainsString( 'window.alert', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])confirm\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])alert\s*\(/', $js );
		self::assertDoesNotMatchRegularExpression( '/(?<![\w.])prompt\s*\(/', $js );
	}

	public function test_destructive_actions_happen_in_an_accessible_review_drawer(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-skills-drawer', $js );
		self::assertStringContainsString( "'dialog'", $js );
		self::assertStringContainsString( 'aria-modal', $js );
		self::assertStringContainsString( "'Escape'", $js );
		self::assertStringContainsString( 'trapFocus', $js );
		self::assertStringContainsString( 'restoreFocus', $js );
	}

	public function test_the_script_builds_dom_nodes_instead_of_writing_markup(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'innerHTML', $js, 'Skill titles, descriptions, and imported Markdown are untrusted content — build nodes and set textContent.' );
		self::assertStringNotContainsString( 'outerHTML', $js );
		self::assertStringNotContainsString( 'insertAdjacentHTML', $js );
		self::assertStringNotContainsString( 'document.' . 'write', $js );
		self::assertStringContainsString( 'textContent', $js );
	}

	public function test_view_switching_keeps_the_url_and_the_keyboard_in_sync(): void {
		$js = self::js();

		self::assertStringContainsString( 'history.pushState', $js );
		self::assertStringContainsString( "'popstate'", $js );
		self::assertStringContainsString( "'ArrowRight'", $js );
		self::assertStringContainsString( "'ArrowLeft'", $js );
		self::assertStringContainsString( "'Home'", $js );
		self::assertStringContainsString( "'End'", $js );
		self::assertStringContainsString( 'aria-selected', $js );
	}

	public function test_the_catalog_is_searchable_without_a_round_trip(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-skills-search', $js );
		self::assertStringContainsString( "type: 'search'", $js );
		self::assertStringContainsString( 'function matchesQuery', $js );
	}

	public function test_the_inspector_shows_provenance_and_findings_before_any_action(): void {
		$js = self::js();

		foreach ( [ 'Source', 'Status', 'Revision', 'Verified', 'Findings' ] as $label ) {
			self::assertStringContainsString( $label, $js );
		}
	}

	public function test_trashing_offers_an_undo_before_it_becomes_permanent(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-skills-undo', $js );
		self::assertStringContainsString( "'/restore'", $js );
		self::assertStringContainsString( 'Undo', $js );
	}

	public function test_an_import_is_inspected_before_it_is_written(): void {
		$js = self::js();

		self::assertStringContainsString( "'/import/inspect'", $js );
		self::assertStringContainsString( "'/import'", $js );
		self::assertStringContainsString( 'inspection', $js );
		self::assertLessThan(
			strpos( $js, 'function runImport' ),
			strpos( $js, 'function inspectFile' ),
			'The inspection step is defined and reached before the write step.'
		);
	}

	public function test_a_permanent_delete_asks_for_a_confirmation_token_in_production_safe_mode(): void {
		$js = self::js();

		self::assertStringContainsString( 'confirmation_token', $js );
		self::assertStringContainsString( "'production-safe'", $js );
	}

	public function test_the_script_announces_state_changes_through_the_live_region(): void {
		$js = self::js();

		self::assertStringContainsString( 'data-sw-skills-status', $js );
		self::assertStringContainsString( 'function announce', $js );
	}

	public function test_the_script_emits_the_documented_dom_events(): void {
		$js = self::js();

		foreach ( [ 'stonewright:skill-imported', 'stonewright:skill-trashed', 'stonewright:skill-restored', 'stonewright:skill-destroyed' ] as $event ) {
			self::assertStringContainsString( $event, $js );
		}

		self::assertStringContainsString( 'CustomEvent', $js );
	}

	public function test_the_script_reaches_wordpress_only_through_the_skills_studio_routes(): void {
		$js = self::js();

		self::assertStringNotContainsString( 'admin-ajax.php', $js );
		self::assertStringNotContainsString( '/wp/v2/', $js );
		self::assertStringNotContainsString( 'abilities/run', $js );
		self::assertStringContainsString( "'/catalog'", $js );
		self::assertStringContainsString( "'/export'", $js );
	}

	// ---------------------------------------------------------------
	// Wiring
	// ---------------------------------------------------------------

	public function test_the_admin_rest_controller_registers_on_rest_api_init(): void {
		self::assertStringContainsString(
			"add_action( 'rest_api_init', [ SkillsRestApi::class, 'register' ] );",
			self::bootstrap()
		);
	}

	public function test_the_assets_are_page_scoped_to_the_skills_page(): void {
		$bootstrap = self::bootstrap();

		self::assertStringContainsString( "'stonewright-skills'        => 'skills-memory.css'", $bootstrap );
		self::assertStringContainsString( 'stonewright-admin-skills', $bootstrap );
		self::assertStringContainsString( 'assets/admin/skills.js', $bootstrap );
		self::assertStringContainsString( "'stonewrightSkills'", $bootstrap );
		self::assertStringContainsString( 'SkillsPage::boot_payload()', $bootstrap );
	}

	public function test_the_script_is_only_localized_on_the_skills_page(): void {
		self::assertMatchesRegularExpression(
			'/if \( SkillsPage::SLUG === \$page \) \{.*?wp_enqueue_script\(.*?skills\.js.*?wp_localize_script\(.*?stonewrightSkills.*?\}/s',
			self::bootstrap(),
			'The skills script and its payload load only on the skills page.'
		);
	}
}

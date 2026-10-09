<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Asset-level contracts that cannot be expressed as PHP unit tests on classes:
 * exactly one tooltip engine, a visible primary hover, and no dark mode.
 */
final class AdminAssetContractTest extends TestCase {

	private static function asset( string $file ): string {
		$path = dirname( __DIR__, 3 ) . '/assets/admin/' . $file;
		self::assertFileExists( $path, $file . ' must exist' );

		return (string) file_get_contents( $path );
	}

	public function test_only_shell_js_owns_the_tooltip_engine(): void {
		self::assertStringContainsString( 'function initTooltips()', self::asset( 'shell.js' ) );
		self::assertStringNotContainsString( 'function initTooltips()', self::asset( 'design-studio.js' ) );
		self::assertStringNotContainsString( 'function initTooltips()', self::asset( 'visual-workspace.js' ) );
	}

	public function test_no_page_stylesheet_redeclares_the_tooltip_surface(): void {
		self::assertStringNotContainsString( '.sw-ds-tooltip', self::asset( 'design-studio.css' ) );
		self::assertStringNotContainsString( '.sw-tooltip', self::asset( 'visual-workspace.css' ) );
		self::assertStringContainsString( '.sw-tooltip {', self::asset( 'shell.css' ) );
	}

	public function test_no_page_script_creates_a_tooltip_node(): void {
		self::assertStringNotContainsString( 'sw-ds-tooltip', self::asset( 'design-studio.js' ) );
		self::assertStringNotContainsString( 'sw-visual-tooltip', self::asset( 'visual-workspace.js' ) );
	}

	/**
	 * The declarations of one CSS rule, selected by its exact selector text.
	 *
	 * Asserting that a token appears somewhere in the file would pass even if the
	 * token were declared in an unrelated rule, which is the bug this suite exists
	 * to catch: the hover state has to change the button that is being hovered.
	 */
	private static function rule_body( string $file, string $selector ): string {
		$css   = self::asset( $file );
		$start = strpos( $css, $selector . ' {' );
		self::assertIsInt( $start, $file . ' must declare a rule for ' . $selector );

		$open  = (int) strpos( $css, '{', $start );
		$close = strpos( $css, '}', $open );
		self::assertIsInt( $close, $selector . ' must be a closed rule block' );

		return substr( $css, $open + 1, $close - $open - 1 );
	}

	public function test_primary_button_hover_repaints_the_primary_button(): void {
		$body = self::rule_body( 'visual-workspace.css', '.sw-button--primary:hover:not([disabled])' );

		// The unreadable-hover bug was a hover that changed the background without
		// keeping the label legible against it, so both are part of the contract.
		self::assertStringContainsString( 'background: var(--sw-brand-fill-hover)', $body );
		self::assertStringContainsString( 'color: var(--sw-on-brand)', $body );
	}

	public function test_generic_hover_excludes_primary_and_disabled_buttons(): void {
		$css = self::asset( 'visual-workspace.css' );

		// Both hover rules must opt disabled buttons out explicitly. A disabled
		// button that still lights up on hover advertises an action that no click
		// will perform.
		self::assertStringContainsString( '.sw-button:not(.sw-button--primary):hover:not([disabled]) {', $css );
		self::assertStringContainsString( '.sw-button--primary:hover:not([disabled]) {', $css );
		self::assertStringNotContainsString( '.sw-button:hover {', $css );
	}

	public function test_sandbox_primary_actions_keep_white_text_on_brand_background(): void {
		$body = self::rule_body( 'sandbox.css', '.stonewright-sandbox-page .button.button-primary' );

		self::assertStringContainsString( 'color: var(--sw-on-brand)', $body );
	}

	public function test_sandbox_category_badge_has_explicit_readable_colors(): void {
		$body = self::rule_body( 'sandbox.css', '.stonewright-sandbox-page .sw-badge--category' );

		self::assertStringContainsString( 'background: var(--sw-info-soft)', $body );
		self::assertStringContainsString( 'color: var(--sw-info-text)', $body );
		self::assertStringContainsString( 'min-height: 22px', $body );
	}

	public function test_overview_stylesheet_only_places_things_and_uses_tokens(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/overview.css' );

		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}|rgba?\(/', $css, 'Colours come from tokens.' );
		self::assertStringContainsString( 'var(--sw-card-pad)', $css );
		// Components come from the shared layer, so this file defines none of them.
		foreach ( [ '.sw-ui-card', '.sw-ui-stat', '.sw-ui-badge', '.sw-ui-btn', '.sw-ui-table' ] as $component ) {
			self::assertStringNotContainsString( $component, $css, $component );
		}
	}

	public function test_setup_stylesheet_only_places_things_and_uses_tokens(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/pages/setup.css' );

		self::assertLessThan( 3 * 1024, strlen( $css ), 'A page stylesheet stays under 3 KB.' );
		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}|rgba?\(/', $css, 'Colours come from tokens.' );
		self::assertDoesNotMatchRegularExpression( '/font-size:\s*[0-9.]+(px|rem|em)/', $css, 'Sizes come from tokens.' );
		foreach ( [ '.sw-ui-card', '.sw-ui-badge', '.sw-ui-btn', '.sw-ui-table', '.sw-ui-tabs', '.sw-ui-choice' ] as $component ) {
			self::assertStringNotContainsString( $component . ' {', $css, $component );
			self::assertStringNotContainsString( $component . ',', $css, $component );
		}
	}

	public function test_the_retired_dashboard_stylesheet_is_gone(): void {
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/dashboard.css' );
		self::assertFileExists( dirname( __DIR__, 3 ) . '/assets/admin/pages/overview.css' );
	}

	public function test_the_stylesheets_no_page_maps_any_more_are_gone(): void {
		// Setup and Troubleshoot have their own files in pages/, Knowledge pages theirs, and no page maps Blueprints.
		foreach ( [ 'setup.css', 'skills-memory.css', 'blueprints.css' ] as $retired ) {
			self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/' . $retired, $retired );
		}
		foreach ( [ 'pages/setup.css', 'pages/troubleshoot.css', 'pages/skills.css', 'pages/memory.css' ] as $page_file ) {
			self::assertFileExists( dirname( __DIR__, 3 ) . '/assets/admin/' . $page_file, $page_file );
		}
	}

	public function test_the_copy_fallback_dialog_that_the_page_script_builds_has_styles_in_a_stylesheet_every_page_loads(): void {
		$css = self::asset( 'admin.css' );

		foreach ( [ '.sw-copy-modal {', '.sw-copy-modal[hidden] {', '.sw-copy-modal__dialog {', '.sw-copy-modal__dialog textarea {' ] as $rule ) {
			self::assertStringContainsString( $rule, $css, $rule );
		}
	}

	public function test_domain_lock_status_centers_its_complete_control_group(): void {
		$body = self::rule_body( 'shell.css', '.sw-shell .stonewright-domain-lock-status' );

		self::assertStringContainsString( 'display: flex', $body );
		self::assertStringContainsString( 'justify-content: center', $body );
		self::assertStringContainsString( 'flex-wrap: wrap', $body );
	}

	/** @dataProvider noticeStatusProvider */
	public function test_stonewright_notices_carry_their_status_colour( string $class, string $text, string $soft ): void {
		$body = self::rule_body( 'shell.css', '.sw-shell .sw-notice.' . $class );

		self::assertStringContainsString( 'background: var(--' . $soft . ')', $body );
		self::assertStringContainsString( 'border-color: var(--' . $text . ')', $body );
	}

	/** @return array<string, array{0:string,1:string,2:string}> */
	public static function noticeStatusProvider(): array {
		return [
			'success' => [ 'notice-success', 'sw-ok-text', 'sw-ok-soft' ],
			'error'   => [ 'notice-error', 'sw-danger-text', 'sw-danger-soft' ],
			'warning' => [ 'notice-warning', 'sw-warn-text', 'sw-warn-soft' ],
			'info'    => [ 'notice-info', 'sw-info-text', 'sw-info-soft' ],
		];
	}
}

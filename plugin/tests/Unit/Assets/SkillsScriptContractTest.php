<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts of assets/admin/skills.js: it builds its markup from the classes of the shared layer, keeps the
 * hooks its page and tests boot from, and never composes markup from strings.
 *
 * @coversNothing
 */
final class SkillsScriptContractTest extends TestCase {

	private static function script(): string {
		return CssSource::read( 'admin/skills.js' );
	}

	public function test_it_uses_no_class_of_the_older_skills_stylesheet(): void {
		$script = self::script();

		foreach ( [ 'sw-skills-button', 'sw-skills-tab', 'sw-skills-input', 'sw-skills-toolbar', 'sw-skills-scrim', "className: 'sw-skills-drawer", 'sw-skills-toast', 'sw-skills-notice', 'sw-skills-dropzone', 'sw-skills-panel__loading', "'sw-badge", "'sw-field", "'sw-card", "'sw-actions", 'sw-empty-state' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $script, $legacy );
		}
	}

	public function test_it_builds_buttons_badges_tags_notices_and_empty_states_from_the_layer(): void {
		$script = self::script();

		foreach ( [ 'sw-ui-btn', 'sw-ui-badge', 'sw-ui-tag', 'sw-ui-callout', 'sw-ui-empty', 'sw-ui-field', 'sw-ui-input', 'sw-ui-skeleton', 'sw-ui-dropzone', 'sw-ui-card' ] as $class ) {
			self::assertStringContainsString( $class, $script, $class );
		}
	}

	public function test_the_review_drawer_is_a_native_dialog_of_the_layer_that_returns_focus_and_puts_the_safe_action_first(): void {
		$script = self::script();

		self::assertStringContainsString( "'dialog'", $script );
		self::assertStringContainsString( 'sw-ui-dialog sw-ui-drawer', $script );
		self::assertMatchesRegularExpression( '/\.openDialog\( dialog, opener \)/', $script );
		self::assertStringContainsString( 'data-sw-skills-drawer', $script, 'The hook the page tests find the drawer by stays.' );
		self::assertStringContainsString( 'autofocus', $script, 'The safe action takes focus.' );
		self::assertStringContainsString( 'data-sw-ui-light-dismiss', $script );
	}

	public function test_messages_use_the_layer_an_error_is_a_notice_and_never_a_toast(): void {
		$script = self::script();

		self::assertMatchesRegularExpression( '/\.notify\( statusNode/', $script );
		self::assertMatchesRegularExpression( '/\.toast\(/', $script );
		self::assertMatchesRegularExpression( "/'error' === tone[^;]*notify/s", $script, 'An error is shown as a notice that stays.' );
		self::assertStringContainsString( 'data-sw-skills-undo', $script, 'The undo toast keeps its hook.' );
	}

	public function test_it_never_composes_markup_from_strings(): void {
		$script = self::script();

		foreach ( [ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(' ] as $unsafe ) {
			self::assertStringNotContainsString( $unsafe, $script, $unsafe );
		}
	}

	public function test_it_keeps_the_hooks_the_page_and_the_tests_boot_from(): void {
		$script = self::script();

		foreach ( [ 'data-sw-skills', 'data-sw-skills-status', 'data-sw-skills-list', 'data-sw-skills-ssr', 'data-sw-skills-search', 'data-sw-view', 'data-sw-panel', 'sw-skill-row', 'sw-skill-row__badges' ] as $hook ) {
			self::assertStringContainsString( $hook, $script, $hook );
		}
	}
}

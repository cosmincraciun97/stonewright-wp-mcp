<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts of assets/admin/sw-ui.js. Behaviour is exercised in a real browser by
 * e2e/tests/ui-contract.spec.ts; these checks keep the file safe to ship and keep it from touching pages
 * that have not adopted the layer.
 *
 * @coversNothing
 */
final class SwUiJavascriptContractTest extends TestCase {

	private static function script(): string {
		return CssSource::read( 'admin/sw-ui.js' );
	}

	public function test_the_file_ships_with_a_licence_header_in_strict_mode(): void {
		$script = self::script();

		self::assertStringStartsWith( '// SPDX-License-Identifier: GPL-2.0-or-later', $script );
		self::assertStringContainsString( "'use strict';", $script );
		self::assertStringNotContainsString( "\r", $script );
		self::assertLessThan( 32 * 1024, strlen( $script ), 'Shared behaviour stays small.' );
	}

	public function test_it_never_builds_markup_from_strings_or_evaluates_code(): void {
		$script = self::script();

		foreach ( [ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setTimeout( "', "setTimeout( '" ] as $unsafe ) {
			self::assertStringNotContainsString( $unsafe, $script, $unsafe );
		}
		self::assertStringContainsString( 'textContent', $script, 'Text goes in through textContent.' );
	}

	public function test_it_reacts_only_to_hooks_of_the_layer(): void {
		$script = self::script();

		preg_match_all( '/data-(?:sw|stonewright)[a-z-]*/', $script, $matches );
		foreach ( array_unique( $matches[0] ) as $hook ) {
			self::assertStringStartsWith( 'data-sw-ui-', $hook, 'Only data-sw-ui-* hooks may be read: ' . $hook );
		}
		self::assertNotEmpty( $matches[0] );
		// It does not claim anything older pages rely on.
		foreach ( [ 'data-stonewright-copy', 'data-sw-tooltip', 'sw-copy-prompt', 'sw-notice-drawer', 'data-sw-shell' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $script, $legacy );
		}
	}

	public function test_a_checkbox_can_fill_a_live_region_from_a_template_without_building_markup(): void {
		$script = self::script();

		self::assertStringContainsString( 'data-sw-ui-live-fill', $script );
		self::assertStringContainsString( 'data-sw-ui-live-fill-from', $script );
		self::assertStringContainsString( '.content.cloneNode( true )', $script, 'The region is filled from a template element, never from a string.' );
	}

	public function test_the_public_api_hangs_off_one_namespace_and_loads_once(): void {
		$script = self::script();

		self::assertStringContainsString( 'window.Stonewright = window.Stonewright || {}', $script );
		self::assertStringContainsString( 'if ( root.ui )', $script, 'A second load must not rebind anything.' );
		foreach ( [ 'motionOK', 'announce', 'toast', 'copy', 'flash', 'notify', 'openDialog', 'closeDialog', 'initTabs', 'initDisclosures' ] as $name ) {
			self::assertMatchesRegularExpression( '/\b' . $name . ':\s*' . $name . '\b/', $script, 'Stonewright.ui.' . $name );
		}
	}

	public function test_motion_follows_the_users_preference_and_never_hardcodes_a_smooth_scroll(): void {
		$script = self::script();

		self::assertStringContainsString( "matchMedia( '(prefers-reduced-motion: reduce)' )", $script );
		self::assertStringNotContainsString( "behavior: 'smooth'", str_replace( "behavior: motionOK() ? 'smooth' : 'auto'", '', $script ), 'Scrolling is smooth only when motion is allowed.' );
		self::assertStringContainsString( "'--sw-dur-exit'", $script, 'Timers that wait for an exit read the scaled duration token, which is 0 under reduced motion.' );
	}

	public function test_storage_is_always_guarded_because_it_can_throw(): void {
		$script = self::script();

		foreach ( [ 'localStorage.getItem', 'localStorage.setItem' ] as $call ) {
			$position = strpos( $script, $call );
			self::assertNotFalse( $position, $call );
			self::assertStringContainsString( 'try {', substr( $script, max( 0, (int) $position - 120 ), 120 ), $call . ' must sit in a try block.' );
		}
	}

	public function test_toasts_last_five_seconds_or_eight_with_an_action_and_stack_at_most_three(): void {
		$script = self::script();

		self::assertStringContainsString( 'options.action ? 8000 : 5000', $script );
		self::assertStringContainsString( 'var MAX_TOASTS = 3;', $script );
	}

	public function test_an_open_dialog_keeps_tab_inside_and_forgets_a_typed_confirmation_when_it_closes(): void {
		$script = self::script();

		self::assertStringContainsString( "document.addEventListener( 'keydown', onDialogTab );", $script );
		self::assertStringContainsString( "event.key !== 'Tab'", $script );
		self::assertStringContainsString( "closest( 'dialog.sw-ui-dialog' )", $script, 'Only dialogs of the layer are touched.' );
		self::assertMatchesRegularExpression( '/resetConfirmation\( event\.target \);\s*returnFocus\( event\.target \);/', $script, 'The native close path clears the phrase before focus returns.' );
		self::assertMatchesRegularExpression( '/dialog\.removeAttribute\( \'open\' \);\s*resetConfirmation\( dialog \);/', $script, 'The no-showModal path clears it too.' );
	}

	public function test_a_button_that_carries_only_literal_text_to_copy_still_copies(): void {
		self::assertStringContainsString( "target.closest( '[data-sw-ui-copy], [data-sw-ui-copy-text]' )", self::script() );
	}

	public function test_a_text_filter_hides_items_groups_and_announces_the_count_through_data_hooks(): void {
		$script = self::script();

		self::assertStringContainsString( '// List filter (Knowledge pages)', $script, 'The block is named, so a merge keeps it apart.' );
		foreach ( [ 'data-sw-ui-filter', 'data-sw-ui-filter-item', 'data-sw-ui-filter-group', 'data-sw-ui-filter-count', 'data-sw-ui-filter-empty', 'data-sw-ui-filter-label' ] as $hook ) {
			self::assertStringContainsString( $hook, $script, $hook );
		}
		self::assertMatchesRegularExpression( '/initFilters:\s*initFilters\b/', $script, 'Stonewright.ui.initFilters is public.' );
		self::assertStringContainsString( '.hidden = ', $script, 'Filtering uses the hidden property, so a filtered item leaves the accessibility tree.' );
	}

	public function test_the_search_shortcut_leaves_typing_and_modified_keys_alone(): void {
		$script = self::script();

		self::assertStringContainsString( "event.key !== '/'", $script );
		self::assertStringContainsString( 'event.ctrlKey || event.metaKey || event.altKey', $script );
		self::assertStringContainsString( 'isEditable( event.target )', $script );
	}

	public function test_the_band_tooltip_is_text_only_positioned_in_the_viewport_and_closed_by_escape(): void {
		$script = self::script();

		self::assertStringContainsString( '// Band tooltip (data-sw-ui-tip)', $script, 'The block is named, so a merge keeps it apart.' );
		self::assertStringContainsString( "'data-sw-ui-tip'", $script );
		self::assertStringContainsString( "setAttribute( 'role', 'tooltip' )", $script );
		self::assertStringContainsString( "setAttribute( 'aria-describedby'", $script, 'The link is described by the tooltip while it is shown.' );
		self::assertStringContainsString( "removeAttribute( 'aria-describedby' )", $script, 'At rest the link has no description from the script.' );
		self::assertStringContainsString( "event.key === 'Escape'", $script );
		self::assertStringContainsString( 'TIP_EDGE = 8', $script, 'Kept 8px inside the viewport.' );
		self::assertStringContainsString( 'TIP_GAP = 8', $script, '8px above the link.' );
		self::assertStringContainsString( "'focusin'", $script, 'The keyboard shows it too.' );
		self::assertStringContainsString( 'ensurePortal().appendChild( tip )', $script, 'It is added to the portal of the layer, in scope of its tokens.' );
	}
}

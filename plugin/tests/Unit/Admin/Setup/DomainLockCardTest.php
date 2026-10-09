<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Setup;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Setup\DomainLockCard;
use Stonewright\WpMcp\Security\DomainLock;

/**
 * The domain lock card says what the lock is, why "Clear domain lock" cannot hold while abilities are on, and
 * what a lock action did.
 *
 * Stonewright records the site address again on every request while abilities are on (PluginRegistration::
 * check_domain_lock), so a lock that is cleared while they are on is set again before the page reloads. The card
 * therefore explains that before the click and says what happened after it.
 *
 * @covers \Stonewright\WpMcp\Admin\Setup\DomainLockCard
 */
final class DomainLockCardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']  = [];
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$_GET                                 = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_home_url'] );
		$_GET = [];
	}

	private static function clear_button( string $html ): string {
		self::assertSame( 1, preg_match( '/<button[^>]*>Clear domain lock<\/button>/', $html, $match ), 'The clear action is on the card.' );

		return $match[0];
	}

	public function test_while_abilities_are_on_clearing_is_disabled_and_says_why(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		DomainLock::lock();

		$html   = DomainLockCard::html();
		$button = self::clear_button( $html );

		self::assertStringContainsString( 'disabled', $button );
		self::assertSame( 1, preg_match( '/aria-describedby="([^"]+)"/', $button, $described ) );
		self::assertStringContainsString( 'id="' . $described[1] . '"', $html );
		self::assertStringContainsString( 'Stonewright sets the lock again as soon as it loads while AI abilities are on. Turn them off first to leave it unset.', $html );
		self::assertStringContainsString( 'name="action" value="stonewright_reset_domain_lock"', $html, 'The form and its nonce stay.' );
		self::assertStringContainsString( 'test-nonce-stonewright_reset_domain_lock', $html );
	}

	public function test_the_clear_action_is_a_secondary_button_in_the_card_footer_beside_its_reason(): void {
		foreach ( [ '1', '0' ] as $enabled ) {
			$GLOBALS['stonewright_test_options']['stonewright_enabled'] = $enabled;
			DomainLock::lock();

			$html = DomainLockCard::html();
			self::assertSame( 1, preg_match( '/<div class="sw-ui-card__footer">(.*)<\/div><\/section>/s', $html, $footer ), 'The action sits in the footer of the card.' );
			$button = self::clear_button( $footer[1] );
			self::assertStringContainsString( 'class="sw-ui-btn"', $button, 'The secondary button of the layer, at its default size.' );
			self::assertStringNotContainsString( 'sw-ui-btn--danger', $button );
			self::assertStringNotContainsString( 'sw-ui-btn--primary', $button );
			self::assertStringNotContainsString( 'sw-ui-stack', $footer[1], 'Not stacked under the facts.' );
			self::assertSame( '1' === $enabled ? 1 : 0, substr_count( $footer[1], 'sw-ui-hint' ), 'The reason sits next to a disabled button.' );
		}
	}

	public function test_while_abilities_are_off_clearing_is_available(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '0';
		DomainLock::lock();

		$html = DomainLockCard::html();

		self::assertStringNotContainsString( 'disabled', self::clear_button( $html ) );
		self::assertStringNotContainsString( 'sets the lock again', $html );
	}

	public function test_a_cleared_lock_is_reported_and_the_state_reads_not_set(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '0';
		$_GET['lock_reset']                                         = '1';

		$html = DomainLockCard::html();

		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( 'Domain lock cleared', $html );
		self::assertStringContainsString( 'No domain lock is set.', $html );
		self::assertStringNotContainsString( 'set again', $html );
	}

	public function test_a_lock_that_was_recorded_again_is_reported_as_such(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		DomainLock::lock();
		$_GET['lock_reset'] = '1';

		$html = DomainLockCard::html();

		self::assertStringContainsString( 'Domain lock set again', $html );
		self::assertStringContainsString( 'https://example.test/', $html );
		self::assertStringNotContainsString( 'Domain lock cleared', $html );
	}

	public function test_a_rebind_and_a_restore_are_reported(): void {
		DomainLock::lock();

		$_GET = [ 'lock_rebind' => '1' ];
		self::assertStringContainsString( 'Site address rebound', DomainLockCard::html() );

		$_GET = [ 'lock_rollback' => '1' ];
		self::assertStringContainsString( 'Previous site address restored', DomainLockCard::html() );
	}

	public function test_no_result_is_reported_on_a_plain_visit(): void {
		DomainLock::lock();

		$html = DomainLockCard::html();

		self::assertStringNotContainsString( 'Domain lock cleared', $html );
		self::assertStringNotContainsString( 'set again', $html );
		self::assertStringNotContainsString( 'rebound', $html );
	}

	public function test_a_mismatch_offers_rebind_and_names_both_addresses(): void {
		DomainLock::lock();
		$GLOBALS['stonewright_test_home_url'] = 'https://cloned.test/';

		$html = DomainLockCard::html();

		self::assertStringContainsString( 'name="action" value="stonewright_rebind_domain_lock"', $html );
		self::assertStringContainsString( 'name="stonewright_rebind_confirm"', $html );
		self::assertStringContainsString( 'https://example.test/', $html );
		self::assertStringContainsString( 'https://cloned.test/', $html );
		self::assertStringContainsString( 'Clear domain lock is disabled during a mismatch.', $html );
		self::assertStringNotContainsString( 'value="stonewright_reset_domain_lock"', $html );
	}
}

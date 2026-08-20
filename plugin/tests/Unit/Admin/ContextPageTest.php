<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Pages\ContextPage;
use Stonewright\WpMcp\Context\ContextSnapshot;
use Stonewright\WpMcp\Context\UserContext;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\ContextPage
 * @covers \Stonewright\WpMcp\Context\ContextSnapshot
 * @covers \Stonewright\WpMcp\Context\UserContext
 */
final class ContextPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = $this->empty_wpdb();
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_home_url']        = 'https://secret.example.test/';
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                   => 'development',
			'stonewright_user_context'           => 'This bakery ships sourdough on Tuesdays.',
			'stonewright_user_context_enabled'   => true,
			'stonewright_custom_instructions'    => 'Use native widgets.',
			'stonewright_custom_instructions_enabled' => true,
		];
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		$_GET  = [];
		$_POST = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['stonewright_test_home_url'] );
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$_GET  = [];
		$_POST = [];
	}

	public function test_slug_lives_in_safety_group(): void {
		self::assertSame( 'stonewright-context', ContextPage::SLUG );
		self::assertSame( 'manage_options', ContextPage::CAPABILITY );
		self::assertContains( ContextPage::SLUG, array_keys( AdminShell::pages() ) );
		$safety = [];
		foreach ( AdminShell::menu_groups() as $group ) {
			if ( 'safety-diagnostics' === $group['id'] ) {
				$safety = array_keys( $group['pages'] );
			}
		}
		self::assertContains( ContextPage::SLUG, $safety );
	}

	public function test_render_refuses_users_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		ContextPage::render();
	}

	public function test_render_shows_redacted_snapshot_and_user_context_editor(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-context-page', $html );
		self::assertStringContainsString( 'System context', $html );
		self::assertStringContainsString( 'User context', $html );
		self::assertStringContainsString( 'Show full system context', $html );
		self::assertStringContainsString( 'Stonewright build discipline', $html );
		self::assertStringContainsString( 'sw-toggle', $html );
		self::assertStringContainsString( 'data-sw-context-state', $html );
		self::assertStringContainsString( 'data-context-on="On"', $html );
		self::assertStringContainsString( 'data-context-off="Off"', $html );
		self::assertStringContainsString( '>On<', $html );
		self::assertStringContainsString( 'id="stonewright_user_context_enabled"', $html );
		self::assertStringContainsString( 'name="stonewright_user_context"', $html );
		self::assertStringContainsString( 'name="stonewright_user_context_enabled"', $html );
		self::assertStringContainsString( 'This bakery ships sourdough on Tuesdays.', $html );
		self::assertStringContainsString( '[redacted-url]', $html );
		self::assertStringNotContainsString( 'secret.example.test', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
	}

	public function test_snapshot_redacts_urls_emails_and_post_ids(): void {
		$redacted = ContextSnapshot::redact(
			[
				'normalized_url' => 'https://secret.example.test/page',
				'email'          => 'owner@secret.example.test',
				'post_id'        => 4321,
				'note'           => 'Contact owner@secret.example.test about post 4321 at https://secret.example.test/',
			]
		);

		self::assertSame( '[redacted-url]', $redacted['normalized_url'] );
		self::assertSame( '[redacted-email]', $redacted['email'] );
		self::assertSame( '[redacted-id]', $redacted['post_id'] );
		self::assertStringNotContainsString( 'secret.example.test', (string) $redacted['note'] );
		self::assertStringNotContainsString( '4321', (string) $redacted['note'] );
	}

	public function test_render_shows_off_badge_when_user_context_disabled(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context_enabled'] = false;

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Off<', $html );
		self::assertStringNotContainsString( '>On<', $html );
	}

	public function test_save_persists_user_context_options(): void {
		UserContext::save( 'Keep the homepage hero quiet.', true );

		self::assertSame( 'Keep the homepage hero quiet.', get_option( 'stonewright_user_context', '' ) );
		self::assertTrue( (bool) get_option( 'stonewright_user_context_enabled', false ) );
	}

	/**
	 * @return object{prefix:string}
	 */
	private function empty_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_var( string $query = '' ): ?string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}
		};
	}
}

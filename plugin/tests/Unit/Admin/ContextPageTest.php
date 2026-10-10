<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\MenuRegistry;
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

	public function test_slug_lives_in_the_knowledge_hub(): void {
		self::assertSame( 'stonewright-context', ContextPage::SLUG );
		self::assertSame( 'manage_options', ContextPage::CAPABILITY );
		self::assertContains( ContextPage::SLUG, array_keys( AdminShell::pages() ) );
		self::assertContains( ContextPage::SLUG, array_column( MenuRegistry::hub_entries( 'knowledge' ), 'slug' ) );
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

		self::assertStringContainsString( 'sw-ui sw-ui-page sw-context', $html );
		self::assertStringNotContainsString( 'class="sw-card', $html );
		self::assertStringNotContainsString( 'sw-toggle', $html );
		self::assertStringNotContainsString( 'notice notice-', $html );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( 'System context', $html );
		self::assertStringContainsString( 'User context', $html );
		self::assertStringContainsString( 'Show full system context', $html );
		self::assertStringContainsString( 'Stonewright build discipline', $html );
		self::assertMatchesRegularExpression( '/<input type="checkbox" role="switch" id="stonewright_user_context_enabled" name="stonewright_user_context_enabled" value="1" checked/', $html );
		self::assertMatchesRegularExpression( '/<label class="sw-ui-switch" for="stonewright_user_context_enabled">/', $html );
		self::assertStringContainsString( '>On<', $html );
		self::assertStringContainsString( 'id="stonewright_user_context_enabled"', $html );
		self::assertStringContainsString( 'name="stonewright_user_context"', $html );
		self::assertStringContainsString( 'name="stonewright_user_context_enabled"', $html );
		self::assertStringContainsString( 'This bakery ships sourdough on Tuesdays.', $html );
		self::assertStringContainsString( '[redacted-url]', $html );
		self::assertStringNotContainsString( 'secret.example.test', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
	}

	public function test_the_system_snapshot_is_facts_plus_a_copyable_code_block_that_wraps_and_is_named(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<dl class="sw-ui-kv"[^>]*aria-label="System facts"/', $html );
		self::assertMatchesRegularExpression( '/<dt>Site URL<\/dt><dd>\[redacted-url\]<\/dd>/', $html );
		self::assertMatchesRegularExpression( '/<pre class="sw-ui-code__body"[^>]*id="[^"]+"[^>]*tabindex="0"[^>]*aria-label="System instructions"/', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*data-sw-ui-copy="#[^"]+"[^>]*>/', $html );
		self::assertMatchesRegularExpression( '/<details class="sw-ui-disclosure"[^>]*data-sw-ui-remember="context-system"[^>]*>\s*<summary>.*Show full system context/s', $html );
	}

	public function test_the_copy_button_is_a_small_button_so_it_keeps_the_touch_tier_at_782px_and_below(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<button[^>]*class="sw-ui-btn sw-ui-btn--sm"[^>]*data-sw-ui-copy="#/', $html );
		self::assertStringNotContainsString( 'sw-ui-btn--xs', $html, 'The extra small tier keeps a 24px height at phone width.' );
	}

	public function test_the_user_context_card_says_what_reaches_agents_and_has_one_primary_action(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Up to 4000 characters are stored', $html );
		self::assertStringContainsString( 'first 1200 characters', $html );
		self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="stonewright_user_context">Persisted user context<\/label>/', $html );
		self::assertSame( 1, preg_match_all( '/sw-ui-btn--primary/', $html ), 'One primary button on the page.' );
		self::assertStringContainsString( '>Save user context<', $html );
		self::assertStringContainsString( 'value="stonewright_user_context_save"', $html );
	}

	public function test_the_saved_notice_is_a_status_message_and_tells_whether_agents_receive_the_text(): void {
		$_GET['stonewright_context_notice'] = 'saved';
		ob_start();
		ContextPage::render();
		$on = (string) ob_get_clean();
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*User context saved\./s', $on );
		self::assertStringContainsString( 'Agents receive it at task start.', $on );

		$GLOBALS['stonewright_test_options']['stonewright_user_context_enabled'] = false;
		ob_start();
		ContextPage::render();
		$off = (string) ob_get_clean();
		self::assertStringContainsString( 'It is off, so agents do not receive it.', $off );
		self::assertStringNotContainsString( 'Agents receive it at task start.', $off );
	}

	public function test_the_card_states_what_compact_and_full_task_start_really_carry(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'first 400 characters', $html );
		self::assertStringContainsString( 'first 1200 characters', $html );
		self::assertStringContainsString( 'compact task start', strtolower( $html ) );
		self::assertSame( 400, UserContext::MAX_COMPACT );
		self::assertSame( 1200, UserContext::MAX_INJECTED );
	}

	public function test_the_saved_notice_gives_the_stored_count_and_what_each_mode_receives(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context'] = str_repeat( 'a', 1500 );
		$_GET['stonewright_context_notice']                              = 'saved';

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '1500 characters stored', $html );
		self::assertStringContainsString( 'Compact task start receives 400 of them', $html );
		self::assertStringContainsString( 'full task start and context-bootstrap receive 1200', $html );
		self::assertStringContainsString( 'Agents receive it at task start.', $html );
	}

	public function test_the_saved_notice_for_a_short_text_says_all_of_it_is_received(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context'] = str_repeat( 'b', 120 );
		$_GET['stonewright_context_notice']                              = 'saved';

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '120 characters stored', $html );
		self::assertStringContainsString( 'Compact task start receives 120 of them', $html );
		self::assertStringContainsString( 'full task start and context-bootstrap receive 120', $html );
	}

	public function test_the_saved_notice_counts_characters_when_the_switch_is_off_and_when_the_text_is_empty(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context']         = str_repeat( 'c', 600 );
		$GLOBALS['stonewright_test_options']['stonewright_user_context_enabled'] = false;
		$_GET['stonewright_context_notice']                                      = 'saved';

		ob_start();
		ContextPage::render();
		$off = (string) ob_get_clean();
		self::assertStringContainsString( '600 characters stored', $off );
		self::assertStringContainsString( 'It is off, so agents do not receive it.', $off );

		$GLOBALS['stonewright_test_options']['stonewright_user_context'] = '';
		ob_start();
		ContextPage::render();
		$empty = (string) ob_get_clean();
		self::assertStringContainsString( 'Nothing is stored', $empty );
	}

	public function test_an_empty_user_context_shows_an_empty_editor_and_the_off_state(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context']         = '';
		$GLOBALS['stonewright_test_options']['stonewright_user_context_enabled'] = false;

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<textarea[^>]*name="stonewright_user_context"[^>]*><\/textarea>/', $html );
		self::assertDoesNotMatchRegularExpression( '/role="switch"[^>]*checked/', $html );
	}

	public function test_no_id_is_used_twice(): void {
		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		preg_match_all( '/\bid="([^"]+)"/', $html, $found );
		self::assertSame( [], array_keys( array_filter( array_count_values( $found[1] ), static fn ( int $count ): bool => $count > 1 ) ) );
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
			public $prefix = 'wp_';

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

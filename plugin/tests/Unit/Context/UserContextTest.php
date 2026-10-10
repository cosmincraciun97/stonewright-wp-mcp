<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\ContextPage;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Context\UserContext;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The user context is plain text: stored as typed with tags removed and no HTML entities, sent to agents as that
 * text, and escaped only when the page prints it.
 *
 * @covers \Stonewright\WpMcp\Context\UserContext
 * @covers \Stonewright\WpMcp\Admin\Pages\ContextPage
 */
final class UserContextTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                             = $this->empty_wpdb();
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_memory_enabled'              => false,
			'stonewright_custom_instructions_enabled' => false,
			'stonewright_user_context_enabled'        => true,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$_GET                                        = [];
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET                                        = [];
	}

	private static function stored(): string {
		return (string) get_option( UserContext::OPTION, '' );
	}

	public function test_text_is_stored_as_typed_when_it_has_no_markup(): void {
		UserContext::save( "Prices: A & B, 5 < 6, 7 > 2.\nSay \"hello\" and it's fine.", true );

		self::assertSame( "Prices: A & B, 5 < 6, 7 > 2.\nSay \"hello\" and it's fine.", self::stored() );
	}

	public function test_tags_are_removed_and_nothing_is_entity_encoded(): void {
		UserContext::save( 'Use <b>bold</b> & "q" it\'s 5 < 6 <script>alert(1)</script>done', true );

		self::assertSame( 'Use bold & "q" it\'s 5 < 6 done', self::stored() );
		self::assertDoesNotMatchRegularExpression( '/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i', self::stored() );
	}

	public function test_entities_in_the_submitted_text_become_the_characters_they_stand_for(): void {
		UserContext::save( 'Tom &amp; Jerry, 5 &lt; 6, &quot;q&quot;, it&#039;s', true );

		self::assertSame( 'Tom & Jerry, 5 < 6, "q", it\'s', self::stored() );
	}

	public function test_an_entity_that_spells_a_tag_is_decoded_and_then_removed(): void {
		UserContext::save( '&lt;script&gt;alert(1)&lt;/script&gt;Hello &amp;lt;b&amp;gt;bold&amp;lt;/b&amp;gt;', true );

		self::assertSame( 'Hello bold', self::stored() );
	}

	public function test_saving_the_same_text_again_changes_nothing(): void {
		$inputs = [
			'Prices: A &amp; B, 5 < 6, use <b>bold</b> & "q" it\'s',
			'&amp;amp;lt; and &amp;quot;',
			"Line one\r\nLine two <i>x</i>",
			'100% sure; a & b; c &d; <3',
		];
		foreach ( $inputs as $input ) {
			UserContext::save( $input, true );
			$first = self::stored();
			UserContext::save( $first, true );
			self::assertSame( $first, self::stored(), $input );
			UserContext::save( self::stored(), true );
			self::assertSame( $first, self::stored(), $input );
		}
	}

	public function test_the_editor_round_trip_keeps_the_text_unchanged(): void {
		UserContext::save( 'Prices: A & B, 5 < 6, "q" it\'s', true );
		$before = self::stored();

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();
		self::assertSame( 1, preg_match( '#<textarea[^>]*name="stonewright_user_context"[^>]*>(.*?)</textarea>#s', $html, $match ) );
		// The browser decodes the textarea markup and posts that text back.
		$posted = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		self::assertSame( $before, $posted );

		UserContext::save( $posted, true );
		self::assertSame( $before, self::stored() );
	}

	public function test_the_page_escapes_only_when_it_prints(): void {
		UserContext::save( 'Prices: 5 < 6 & "q"', true );

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '5 &lt; 6 &amp; &quot;q&quot;', $html );
		self::assertStringNotContainsString( '5 < 6', $html );
	}

	public function test_the_text_stays_within_the_stored_limit_counted_in_characters(): void {
		UserContext::save( str_repeat( 'é', 4500 ), true );

		self::assertSame( UserContext::MAX_STORED, mb_strlen( self::stored() ) );
		self::assertSame( str_repeat( 'é', 4000 ), self::stored() );
	}

	public function test_agents_receive_the_plain_text_and_only_its_first_characters(): void {
		UserContext::save( "5 < 6 & \"q\" it's " . str_repeat( 'x', 2000 ), true );

		$text = UserContext::get()['text'];

		self::assertStringStartsWith( '5 < 6 & "q" it\'s x', $text );
		self::assertSame( UserContext::MAX_INJECTED, mb_strlen( $text ) );
	}

	public function test_values_stored_in_encoded_form_before_are_read_as_plain_text(): void {
		$GLOBALS['stonewright_test_options'][ UserContext::OPTION ] = 'Prices: A &amp; B, 5 &lt; 6, &quot;q&quot; it&#039;s';

		self::assertSame( 'Prices: A & B, 5 < 6, "q" it\'s', UserContext::get()['text'] );
		self::assertSame( 'Prices: A & B, 5 < 6, "q" it\'s', UserContext::stored() );
		self::assertSame( 'Prices: A &amp; B, 5 &lt; 6, &quot;q&quot; it&#039;s', self::stored(), 'Reading never rewrites what the operator stored.' );

		$built = ContextBuilder::build( 'Inspect plugins', 'wordpress', 'read' );
		self::assertSame( 'Prices: A & B, 5 < 6, "q" it\'s', $built['user_context']['text'] );
		self::assertSame( 'Prices: A & B, 5 < 6, "q" it\'s', $built['custom_instructions']['text'] );
	}

	public function test_the_editor_shows_a_legacy_value_decoded_so_the_next_save_stores_plain_text(): void {
		$GLOBALS['stonewright_test_options'][ UserContext::OPTION ] = 'it&#039;s 5 &lt; 6';

		ob_start();
		ContextPage::render();
		$html = (string) ob_get_clean();
		preg_match( '#<textarea[^>]*name="stonewright_user_context"[^>]*>(.*?)</textarea>#s', $html, $match );
		$posted = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		self::assertSame( 'it\'s 5 < 6', $posted );
		UserContext::save( $posted, true );
		self::assertSame( 'it\'s 5 < 6', self::stored() );
	}

	public function test_what_agents_receive_is_counted_per_task_start_mode(): void {
		self::assertSame( [ 'stored' => 0, 'compact' => 0, 'full' => 0 ], UserContext::reach( 0 ) );
		self::assertSame( [ 'stored' => 120, 'compact' => 120, 'full' => 120 ], UserContext::reach( 120 ) );
		self::assertSame( [ 'stored' => 900, 'compact' => 400, 'full' => 900 ], UserContext::reach( 900 ) );
		self::assertSame( [ 'stored' => 1500, 'compact' => 400, 'full' => 1200 ], UserContext::reach( 1500 ) );
		self::assertSame( 400, UserContext::MAX_COMPACT );
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

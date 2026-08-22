<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage
 */
final class FinalizerPageStatusStripTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
	}

	public function test_render_includes_status_strip_and_item_list(): void {
		ob_start();
		FinalizerPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-finalizer-strip', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-online"', $html );
		self::assertStringContainsString( 'Last poll:', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-last-poll"', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-queued-count"', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-applied-count"', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-failed-count"', $html );
		self::assertStringContainsString( 'id="stonewright-finalizer-items"', $html );
	}

	public function test_client_script_updates_poll_clock_and_tolerates_missing_error_fields(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 4 ) . '/blocks/finalizer/finalizer.js' );

		self::assertStringContainsString( 'stonewright-finalizer-last-poll', $script );
		self::assertStringContainsString( 'failed_count', $script );
		self::assertStringContainsString( 'item.error', $script );
		self::assertStringContainsString( 'sw-finalizer-item__error', $script );
		self::assertStringContainsString( 'function formatClock', $script );
		self::assertMatchesRegularExpression( '/setInterval\(\s*heartbeat\s*,\s*15000\s*\)/', $script );
	}

	public function test_editor_url_marks_the_iframe_as_a_non_persisting_finalizer_session(): void {
		$url = FinalizerPage::editor_url_for_post( 42 );
		self::assertStringContainsString( 'stonewright_finalizer=1', $url );
		self::assertStringContainsString( 'post=42', $url );
	}

	public function test_finalizer_rest_autosave_from_the_iframe_is_rejected(): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/42/autosaves' );
		$request->set_header( 'X-Stonewright-Finalizer', '1' );
		$result = FinalizerPage::reject_finalizer_live_writes( null, null, $request );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_finalizer_write_blocked', $result->get_error_code() );
	}

	public function test_client_script_locks_saving_before_editor_ready_and_never_unlocks(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 4 ) . '/blocks/finalizer/finalizer.js' );
		$lock   = strpos( $script, 'lockPostAutosaving' );
		$ready  = strpos( $script, '__unstableIsEditorReady' );
		self::assertNotFalse( $lock );
		self::assertNotFalse( $ready );
		self::assertLessThan( $ready, $lock, 'Autosave must be locked before waiting for editor-ready' );
		self::assertStringNotContainsString( 'unlockPostAutosaving', $script );
		self::assertStringNotContainsString( 'unlockPostSaving', $script );
	}
}

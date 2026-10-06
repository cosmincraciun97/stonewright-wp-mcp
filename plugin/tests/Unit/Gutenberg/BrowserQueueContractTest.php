<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueEndpoint;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueRequestGuard;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

final class BrowserQueueContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_nonce_invalid'] = false;
		$GLOBALS['stonewright_test_posts'][42] = (object) [ 'ID' => 42, 'post_type' => 'page', 'post_content' => '' ];
	}

	private function queue(): array {
		$record = BlockQueue::enqueue( [ 'post_id' => 42, 'block_spec' => [ 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ] ] );
		self::assertIsArray( $record );
		$token = BlockQueue::issue_token( $record['session_id'] );
		self::assertIsArray( $token );
		return [ $record, $token['token'] ];
	}

	private function request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/claim', $params );
		$request->set_json_params( $params );
		$request->set_header( 'X-WP-Nonce', 'synthetic-nonce' );
		return $request;
	}

	public function test_console_page_is_registered_without_a_menu_entry_for_post_editors(): void {
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		QueueConsole::attach_page();
		$page = $GLOBALS['stonewright_test_submenu_pages'][ QueueConsole::PAGE ] ?? null;
		self::assertIsArray( $page );
		self::assertSame( 'options.php', $page['parent'] );
		self::assertSame( 'edit_posts', $page['capability'] );
		self::assertSame( [ QueueConsole::class, 'render' ], $page['callback'] );
		ob_start();
		QueueConsole::render();
		self::assertStringContainsString( 'data-queue-journal', (string) ob_get_clean() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
		$this->expectException( \RuntimeException::class );
		QueueConsole::render();
	}

	public function test_console_styles_use_only_defined_admin_tokens(): void {
		$assets = dirname( __DIR__, 3 ) . '/assets/admin/';
		preg_match_all( '/(--sw-[a-z0-9-]+)\s*:/', (string) file_get_contents( $assets . 'shell.css' ), $defined );
		preg_match_all( '/var\(\s*(--sw-[a-z0-9-]+)/', (string) file_get_contents( $assets . 'block-queue.css' ), $used );
		self::assertNotEmpty( $used[1] );
		self::assertSame( [], array_values( array_diff( array_unique( $used[1] ), $defined[1] ) ) );
	}

	public function test_guard_rejects_origin_nonce_and_foreign_owner(): void {
		[ $record, $token ] = $this->queue();
		$request = $this->request( [ 'token' => $token ] );
		self::assertIsArray( QueueRequestGuard::authorize( $request ) );
		$request->set_header( 'Origin', 'https://outside.example.test' );
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
		$request->set_header( 'Origin', '' );
		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
		$GLOBALS['stonewright_test_nonce_invalid'] = false;
		$GLOBALS['stonewright_test_current_user_id'] = 8;
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
	}

	public function test_claim_serialization_preserves_post_and_compact_status(): void {
		[ $record, $token ] = $this->queue();
		$claim = QueueEndpoint::claim( $this->request( [ 'token' => $token, 'lease_id' => 'synthetic-lease' ] ) );
		self::assertIsArray( $claim );
		self::assertSame( $record['id'], $claim['items'][0]['id'] );
		self::assertArrayHasKey( 'block_spec', $claim['items'][0] );
		self::assertArrayNotHasKey( 'block_spec', QueueConsole::target_summaries()[0] );
		$html = '<!-- wp:paragraph --><p>Example</p><!-- /wp:paragraph -->';
		$input = [ 'token' => $token, 'lease_id' => 'synthetic-lease', 'result_id' => 'synthetic-result', 'change_id' => $record['id'], 'status' => 'serialized', 'html' => $html, 'html_hash' => hash( 'sha256', $html ) ];
		$result = QueueEndpoint::result( $this->request( $input ) );
		self::assertIsArray( $result );
		self::assertFalse( $result['retryable'] );
		self::assertSame( 'serialized', BlockQueue::get( $record['id'] )['status'] );
		self::assertSame( '', get_post( 42 )->post_content );
		self::assertIsArray( QueueEndpoint::result( $this->request( $input ) ) );
		$input['html'] = '<p>Different</p>';
		$input['html_hash'] = hash( 'sha256', $input['html'] );
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::result( $this->request( $input ) ) );
	}

	public function test_forged_hash_and_successor_lease_do_not_finish_record(): void {
		[ $record, $token ] = $this->queue();
		QueueEndpoint::claim( $this->request( [ 'token' => $token, 'lease_id' => 'owned-lease' ] ) );
		$input = [ 'token' => $token, 'lease_id' => 'other-lease', 'result_id' => 'result-a', 'change_id' => $record['id'], 'status' => 'serialized', 'html' => '<p>Example</p>', 'html_hash' => hash( 'sha256', '<p>Example</p>' ) ];
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::result( $this->request( $input ) ) );
		$input['lease_id'] = 'owned-lease';
		$input['html_hash'] = str_repeat( '0', 64 );
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::result( $this->request( $input ) ) );
		self::assertSame( 'queued', BlockQueue::get( $record['id'] )['status'] );
	}

	public function test_plain_permalink_query_fields_do_not_mix_with_body_authority(): void {
		[ $record, $token ] = $this->queue();
		$request = $this->request( [ 'token' => $token, 'lease_id' => 'plain-route' ] );
		$request->set_params( [ 'rest_route' => '/stonewright/v1/block-finalizer/claim', 'token' => 'query-value' ] );
		self::assertIsArray( QueueEndpoint::claim( $request ) );
		$request->set_json_params( [ 'lease_id' => 'plain-route' ] );
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
	}

	public function test_failure_identity_cannot_be_reused_with_different_payload(): void {
		[ $record, $token ] = $this->queue();
		QueueEndpoint::claim( $this->request( [ 'token' => $token, 'lease_id' => 'failure-lease' ] ) );
		$input = [ 'token' => $token, 'lease_id' => 'failure-lease', 'result_id' => 'failure-result', 'change_id' => $record['id'], 'status' => 'failed', 'error_code' => 'example_error', 'message' => 'Example failure' ];
		self::assertIsArray( QueueEndpoint::result( $this->request( $input ) ) );
		self::assertIsArray( QueueEndpoint::result( $this->request( $input ) ) );
		$input['message'] = 'Different failure';
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::result( $this->request( $input ) ) );
	}

	public function test_heartbeat_exposes_only_scoped_compact_state_and_cancel_honors_mode(): void {
		[ $record, $token ] = $this->queue();
		$heartbeat = QueueEndpoint::heartbeat( $this->request( [ 'token' => $token, 'lease_id' => 'state-lease' ] ) );
		self::assertArrayHasKey( 'counts', $heartbeat );
		self::assertSame( $record['id'], $heartbeat['items'][0]['id'] );
		self::assertArrayNotHasKey( 'block_spec', $heartbeat['items'][0] );
		$preview = QueueEndpoint::cancel( $this->request( [ 'token' => $token, 'change_ids' => [ $record['id'] ], 'dry_run' => true ] ) );
		self::assertIsArray( $preview );
		self::assertSame( 'queued', BlockQueue::get( $record['id'] )['status'] );
		update_option( 'stonewright_mode', 'production-safe' );
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::cancel( $this->request( [ 'token' => $token, 'change_ids' => [ $record['id'] ], 'dry_run' => false, 'confirm_cancel' => true ] ) ) );
		update_option( 'stonewright_mode', 'staging' );
		self::assertInstanceOf( \WP_Error::class, QueueEndpoint::cancel( $this->request( [ 'token' => $token, 'change_ids' => [ $record['id'] ], 'dry_run' => false ] ) ) );
		self::assertIsArray( QueueEndpoint::cancel( $this->request( [ 'token' => $token, 'change_ids' => [ $record['id'] ], 'dry_run' => false, 'confirm_cancel' => true ] ) ) );
		self::assertNull( BlockQueue::get( $record['id'] ) );
		self::assertSame( '', get_post( 42 )->post_content );
	}

	public function test_invalid_origins_fail_closed_and_foreign_ids_do_not_reveal_conflicts(): void {
		[ $record, $token ] = $this->queue();
		$request = $this->request( [ 'token' => $token ] );
		$GLOBALS['stonewright_test_home_url'] = 'not-an-origin';
		$request->set_header( 'Origin', 'not-an-origin' );
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
		unset( $GLOBALS['stonewright_test_home_url'] );
		$request->set_header( 'Origin', 'https://operator@example.test' );
		self::assertInstanceOf( \WP_Error::class, QueueRequestGuard::authorize( $request ) );
		$GLOBALS['stonewright_test_posts'][43] = (object) [ 'ID' => 43, 'post_type' => 'page', 'post_content' => '' ];
		$GLOBALS['stonewright_test_current_user_id'] = 8;
		$foreign = BlockQueue::enqueue( [ 'post_id' => 43, 'block_spec' => [ 'name' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ] ] );
		self::assertIsArray( $foreign );
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$base = [ 'token' => $token, 'lease_id' => 'foreign-lease', 'result_id' => 'foreign-result', 'status' => 'serialized', 'html' => '<p>Example</p>', 'html_hash' => hash( 'sha256', '<p>Example</p>' ) ];
		$foreign_error = QueueEndpoint::result( $this->request( $base + [ 'change_id' => $foreign['id'] ] ) );
		$missing_error = QueueEndpoint::result( $this->request( $base + [ 'change_id' => 'missing-change' ] ) );
		self::assertInstanceOf( \WP_Error::class, $foreign_error );
		self::assertSame( $missing_error->get_error_code(), $foreign_error->get_error_code() );
		self::assertSame( $missing_error->get_error_data(), $foreign_error->get_error_data() );
	}
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueEndpoint;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueRequestGuard;
use Stonewright\WpMcp\Gutenberg\BrowserQueue\QueueConsole;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\RawHtmlGate;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\CustomCodeGrant;

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

	/** @dataProvider unauthorizedRequestProvider */
	public function test_unauthorized_request_is_refused_with_403_before_its_body_is_read( bool $nonce, bool $capable ): void {
		$GLOBALS['stonewright_test_user_caps'] = $capable ? [ 'edit_posts' => true, 'edit_post' => true ] : [];
		$GLOBALS['stonewright_test_current_user_id'] = $capable ? 7 : 0;
		$GLOBALS['stonewright_test_user_logged_in'] = $capable;
		$request = new class( 'POST', '/stonewright/v1/block-finalizer/claim', [] ) extends \WP_REST_Request {
			public int $body_reads = 0;

			public function get_json_params(): array {
				++$this->body_reads;
				// Not an object body, so reading it would be a 400.
				return [ 'not', 'an', 'object' ];
			}
		};
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', 'synthetic-nonce' );
		}

		$error = QueueRequestGuard::authorize( $request );
		$permission = QueueRequestGuard::permission( $request );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 403, $error->get_error_data()['status'] );
		self::assertInstanceOf( \WP_Error::class, $permission );
		self::assertSame( 403, $permission->get_error_data()['status'] );
		self::assertSame( 0, $request->body_reads, 'The body must not be parsed before the nonce and capability checks.' );
	}

	/** @return array<string, array{0:bool,1:bool}> */
	public static function unauthorizedRequestProvider(): array {
		return [
			'anonymous visitor with a nonce' => [ true, false ],
			'editor without a nonce'         => [ false, true ],
			'anonymous without a nonce'      => [ false, false ],
		];
	}

	public function test_authorized_request_with_a_malformed_body_is_still_a_400(): void {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/claim', [] );
		$request->set_json_params( [ 'not', 'an', 'object' ] );
		$request->set_header( 'X-WP-Nonce', 'synthetic-nonce' );

		$error = QueueRequestGuard::authorize( $request );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 400, $error->get_error_data()['status'] );
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

	/**
	 * @param array<string,mixed> $spec
	 * @param array<string,mixed> $extra Extra enqueue arguments, such as the raw HTML flag and its grant.
	 * @return array{0:array<string,mixed>,1:string}
	 */
	private function leased_change( array $spec, array $extra = [] ): array {
		$record = BlockQueue::enqueue( array_merge( [ 'post_id' => 42, 'action' => 'insert', 'block_spec' => $spec ], $extra ) );
		self::assertIsArray( $record, is_wp_error( $record ) ? $record->get_error_code() : '' );
		$token = BlockQueue::issue_token( (string) $record['session_id'] );
		self::assertIsArray( $token );
		self::assertIsArray( QueueEndpoint::claim( $this->request( [ 'token' => $token['token'], 'lease_id' => 'synthetic-lease' ] ) ) );
		return [ $record, (string) $token['token'] ];
	}

	/** @return array<string,mixed> */
	private function serialized_input( array $record, string $token, string $html, string $result_id = 'synthetic-result' ): array {
		return [
			'token'     => $token,
			'lease_id'  => 'synthetic-lease',
			'result_id' => $result_id,
			'change_id' => $record['id'],
			'status'    => 'serialized',
			'html'      => $html,
			'html_hash' => hash( 'sha256', $html ),
		];
	}

	/** @return array<string,mixed> */
	private function paragraph_spec(): array {
		return [ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Example' ], 'innerBlocks' => [] ];
	}

	/** @dataProvider unfaithfulOutputProvider */
	public function test_unfaithful_browser_output_is_stored_as_a_failed_result_and_never_persisted( string $html, string $code ): void {
		[ $record, $token ] = $this->leased_change( $this->paragraph_spec() );

		$receipt = QueueEndpoint::result( $this->request( $this->serialized_input( $record, $token, $html ) ) );

		self::assertIsArray( $receipt, is_wp_error( $receipt ) ? $receipt->get_error_code() : '' );
		self::assertSame( 'failed', $receipt['status'] );
		self::assertFalse( $receipt['ok'] );
		self::assertFalse( $receipt['retryable'] );
		$stored = BlockQueue::get( (string) $record['id'] );
		self::assertSame( 'failed', $stored['status'] );
		self::assertSame( $code, $stored['error_code'] );
		self::assertSame( '', $stored['serialized_html'] );
		self::assertSame( '', $stored['serialized_html_hash'] );
		self::assertSame( '', get_post( 42 )->post_content );
		self::assertStringNotContainsString( 'onerror', (string) wp_json_encode( $stored ) );
		self::assertStringNotContainsString( '<script', (string) wp_json_encode( $stored ) );

		$finalized = ( new FinalizeBatch() )->execute( [ 'post_id' => 42, 'change_ids' => [ $record['id'] ] ] );
		self::assertInstanceOf( \WP_Error::class, $finalized );
		self::assertSame( 'stonewright_finalizer_not_serialized', $finalized->get_error_code() );
		self::assertSame( '', get_post( 42 )->post_content );
	}

	/** @return array<string, array{0:string,1:string}> */
	public static function unfaithfulOutputProvider(): array {
		$paragraph = "<!-- wp:paragraph -->\n<p>Example</p>\n<!-- /wp:paragraph -->";
		return [
			'a mismatched block name'       => [ "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Example</h2>\n<!-- /wp:heading -->", 'serialized_structure_mismatch' ],
			'extra blocks'                  => [ $paragraph . "\n" . $paragraph, 'serialized_structure_mismatch' ],
			'plain html instead of a block' => [ '<p>Example</p>', 'serialized_structure_mismatch' ],
			'a script tag'                  => [ "<!-- wp:paragraph -->\n<p>Example</p><script>alert(1)</script>\n<!-- /wp:paragraph -->", 'serialized_markup_refused' ],
			'a script tag outside the block' => [ $paragraph . '<script>alert(1)</script>', 'serialized_markup_refused' ],
			'an onerror attribute'          => [ "<!-- wp:paragraph -->\n<p>Example<img src=x onerror=alert(1)></p>\n<!-- /wp:paragraph -->", 'serialized_markup_refused' ],
			'a javascript url'              => [ "<!-- wp:paragraph -->\n<p><a href=\"javascript:alert(1)\">Example</a></p>\n<!-- /wp:paragraph -->", 'serialized_markup_refused' ],
		];
	}

	/**
	 * @dataProvider legitimateOutputProvider
	 * @param array<string,mixed> $spec
	 */
	public function test_legitimate_editor_output_is_accepted( array $spec, string $html ): void {
		[ $record, $token ] = $this->leased_change( $spec );

		$receipt = QueueEndpoint::result( $this->request( $this->serialized_input( $record, $token, $html ) ) );

		self::assertIsArray( $receipt, is_wp_error( $receipt ) ? $receipt->get_error_code() : '' );
		self::assertSame( 'serialized', $receipt['status'] );
		self::assertTrue( $receipt['ok'] );
		$stored = BlockQueue::get( (string) $record['id'] );
		self::assertSame( 'serialized', $stored['status'] );
		self::assertSame( $html, $stored['serialized_html'] );
		self::assertSame( hash( 'sha256', $html ), $stored['serialized_html_hash'] );
		self::assertSame( '', get_post( 42 )->post_content );
	}

	/** @return array<string, array{0:array<string,mixed>,1:string}> */
	public static function legitimateOutputProvider(): array {
		return [
			'a queued paragraph' => [
				[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Example' ], 'innerBlocks' => [] ],
				"<!-- wp:paragraph -->\n<p>Example</p>\n<!-- /wp:paragraph -->",
			],
			'a queued heading'   => [
				[ 'name' => 'core/heading', 'attributes' => [ 'level' => 3, 'content' => 'Title' ], 'innerBlocks' => [] ],
				"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Title</h3>\n<!-- /wp:heading -->",
			],
			'a queued heading at the default level' => [
				[ 'name' => 'core/heading', 'attributes' => [ 'level' => 2, 'content' => 'Title' ], 'innerBlocks' => [] ],
				"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title</h2>\n<!-- /wp:heading -->",
			],
		];
	}

	public function test_custom_code_is_accepted_from_the_browser_only_for_the_kinds_its_grant_covered(): void {
		$GLOBALS['stonewright_test_user_caps']  = [ 'edit_posts' => true, 'edit_post' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_transients'] = [];
		$css    = '<style>.example{display:block}</style>';
		$issued = CustomCodeGrant::issue(
			[
				'path'         => RawHtmlGate::grant_path( 42 ),
				'after_sha256' => hash( 'sha256', $css ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );
		[ $record, $token ] = $this->leased_change(
			[ 'name' => 'core/html', 'attributes' => [ 'content' => $css ], 'innerBlocks' => [] ],
			[ 'allow_raw_html' => true, 'custom_code_grant' => (string) $issued['token'] ]
		);

		$smuggled = QueueEndpoint::result( $this->request( $this->serialized_input( $record, $token, "<!-- wp:html -->\n" . $css . "<script>alert(1)</script>\n<!-- /wp:html -->" ) ) );
		self::assertIsArray( $smuggled );
		self::assertSame( 'failed', $smuggled['status'] );
		self::assertSame( 'serialized_markup_refused', BlockQueue::get( (string) $record['id'] )['error_code'] );

		// The refusal made that change terminal; a corrected change with its own grant is accepted.
		self::assertIsArray( BlockQueue::cancel( [ (string) $record['id'] ], false, 7 ) );
		$again = CustomCodeGrant::issue(
			[
				'path'         => RawHtmlGate::grant_path( 42 ),
				'after_sha256' => hash( 'sha256', $css ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $again );
		[ $record, $token ] = $this->leased_change(
			[ 'name' => 'core/html', 'attributes' => [ 'content' => $css ], 'innerBlocks' => [] ],
			[ 'allow_raw_html' => true, 'custom_code_grant' => (string) $again['token'] ]
		);
		$html     = "<!-- wp:html -->\n" . $css . "\n<!-- /wp:html -->";
		$accepted = QueueEndpoint::result( $this->request( $this->serialized_input( $record, $token, $html ) ) );
		self::assertIsArray( $accepted, is_wp_error( $accepted ) ? $accepted->get_error_code() : '' );
		self::assertSame( 'serialized', $accepted['status'] );
		self::assertSame( $html, BlockQueue::get( (string) $record['id'] )['serialized_html'] );
	}

	public function test_a_refused_result_replays_as_the_same_failed_receipt_and_conflicts_with_other_bytes(): void {
		[ $record, $token ] = $this->leased_change( $this->paragraph_spec() );
		$bad = $this->serialized_input( $record, $token, "<!-- wp:paragraph -->\n<p>Example<script>alert(1)</script></p>\n<!-- /wp:paragraph -->" );

		$first  = QueueEndpoint::result( $this->request( $bad ) );
		$replay = QueueEndpoint::result( $this->request( $bad ) );

		self::assertIsArray( $first );
		self::assertFalse( $first['duplicate'] );
		self::assertIsArray( $replay, is_wp_error( $replay ) ? $replay->get_error_code() : '' );
		self::assertSame( 'failed', $replay['status'] );
		self::assertTrue( $replay['duplicate'] );
		self::assertSame( $first['event_id'], $replay['event_id'] );

		$other = $this->serialized_input( $record, $token, "<!-- wp:paragraph -->\n<p>Example</p>\n<!-- /wp:paragraph -->" );
		$conflict = QueueEndpoint::result( $this->request( $other ) );
		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_queue_result_conflict', $conflict->get_error_code() );
		self::assertSame( 'failed', BlockQueue::get( (string) $record['id'] )['status'] );
	}

	public function test_a_refused_result_is_audited_as_a_blocked_event_without_the_markup(): void {
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients']   = [];
		AuditLog::reset_request_state();
		[ $record, $token ] = $this->leased_change( $this->paragraph_spec() );
		$html = "<!-- wp:paragraph -->\n<p>Example<script>alert(1)</script></p>\n<!-- /wp:paragraph -->";

		AuditLog::begin_request();
		QueueEndpoint::result( $this->request( $this->serialized_input( $record, $token, $html ) ) );

		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'blocked', $row['result_status'] );
		self::assertSame( 'serialized_markup_refused', $row['error_code'] );
		self::assertStringNotContainsString( '<script', (string) $row['sanitized_args'] );
		self::assertStringContainsString( hash( 'sha256', $html ), (string) $row['sanitized_args'] );
	}
}

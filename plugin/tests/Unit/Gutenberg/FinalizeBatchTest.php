<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\RawHtmlGate;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch
 */
final class FinalizeBatchTest extends TestCase {

	private const POST_ID = 42;
	private const BEFORE  = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_user_caps']            = [ 'edit_posts' => true, 'edit_post' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_posts']                = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Finalize target',
				'post_content' => self::BEFORE,
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_persists_the_serialized_block_and_marks_the_change_persisted(): void {
		$html = "<!-- wp:paragraph -->\n<p>After</p>\n<!-- /wp:paragraph -->";
		$id   = $this->serialized_change( $this->paragraph_spec(), $html );

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $id ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( 1, $result['applied'] );
		self::assertSame( self::BEFORE . "\n" . $html, $this->content() );
		self::assertSame( 'persisted', BlockQueue::get( $id )['status'] );
	}

	public function test_backslashes_in_block_comment_attributes_survive_the_write(): void {
		$html = "<!-- wp:paragraph {\"className\":\"Tom \\u0026 Jerry\"} -->\n<p class=\"Tom &amp; Jerry\">After</p>\n<!-- /wp:paragraph -->";
		$id   = $this->serialized_change( $this->paragraph_spec( [ 'className' => 'Tom & Jerry' ] ), $html );

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $id ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertStringContainsString( '\\u0026', $this->content() );
		self::assertSame( self::BEFORE . "\n" . $html, $this->content() );
		self::assertSame( hash( 'sha256', $this->content() ), $result['readback_hash'] );
	}

	/** @dataProvider unfaithfulRecordProvider */
	public function test_refuses_to_write_html_that_no_longer_matches_its_queued_spec( string $html, string $code ): void {
		// The queue refuses such markup when the browser sends it. A record stored by an earlier version,
		// or altered in storage, reaches the finalize step with it anyway, so that step checks again.
		$id = $this->serialized_change( $this->paragraph_spec( [ 'content' => 'Example' ] ), $html, [], true );

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $id ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_' . $code, $result->get_error_code() );
		self::assertSame( $id, $result->get_error_data()['change_id'] );
		self::assertSame( self::BEFORE, $this->content() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'No snapshot is taken for a refused write.' );
		$stored = BlockQueue::get( $id );
		self::assertSame( 'failed', $stored['status'] );
		self::assertSame( $code, $stored['error_code'] );
		self::assertSame( '', $stored['serialized_html'] );
	}

	/** @return array<string, array{0:string,1:string}> */
	public static function unfaithfulRecordProvider(): array {
		$paragraph = "<!-- wp:paragraph -->\n<p>Example</p>\n<!-- /wp:paragraph -->";
		return [
			'a mismatched block name' => [ "<!-- wp:heading -->\n<h2>Example</h2>\n<!-- /wp:heading -->", 'serialized_structure_mismatch' ],
			'extra blocks'            => [ $paragraph . "\n" . $paragraph, 'serialized_structure_mismatch' ],
			'a script tag'            => [ "<!-- wp:paragraph -->\n<p>Example<script>alert(1)</script></p>\n<!-- /wp:paragraph -->", 'serialized_markup_refused' ],
			'an onerror attribute'    => [ "<!-- wp:paragraph -->\n<p>Example<img src=x onerror=alert(1)></p>\n<!-- /wp:paragraph -->", 'serialized_markup_refused' ],
		];
	}

	public function test_one_unfaithful_record_stops_the_whole_batch_before_anything_is_written(): void {
		[ $good, $bad ] = $this->serialized_batch(
			[
				[ $this->paragraph_spec(), "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->" ],
				[ $this->paragraph_spec(), "<!-- wp:heading -->\n<h2>Two</h2>\n<!-- /wp:heading -->" ],
			],
			[],
			true
		);

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $good, $bad ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $bad, $result->get_error_data()['change_id'] );
		self::assertSame( self::BEFORE, $this->content() );
		self::assertSame( 'serialized', BlockQueue::get( $good )['status'] );
		self::assertSame( 'failed', BlockQueue::get( $bad )['status'] );
	}

	public function test_writes_custom_code_that_the_change_was_approved_for(): void {
		$css    = '<style>.example{display:block}</style>';
		$spec   = [ 'name' => 'core/html', 'attributes' => [ 'content' => $css ], 'innerBlocks' => [] ];
		$issued = CustomCodeGrant::issue(
			[
				'path'         => RawHtmlGate::grant_path( self::POST_ID ),
				'after_sha256' => hash( 'sha256', $css ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );
		$approved = "<!-- wp:html -->\n" . $css . "\n<!-- /wp:html -->";
		$id       = $this->serialized_change( $spec, $approved, [ 'allow_raw_html' => true, 'custom_code_grant' => (string) $issued['token'] ] );

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $id ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertStringContainsString( $css, $this->content() );
		self::assertSame( [ 'style' ], BlockQueue::get( $id )['custom_code'] );
	}

	public function test_refuses_script_added_to_a_change_approved_only_for_css(): void {
		$css    = '<style>.example{display:block}</style>';
		$spec   = [ 'name' => 'core/html', 'attributes' => [ 'content' => $css ], 'innerBlocks' => [] ];
		$issued = CustomCodeGrant::issue(
			[
				'path'         => RawHtmlGate::grant_path( self::POST_ID ),
				'after_sha256' => hash( 'sha256', $css ),
				'language'     => 'html',
			]
		);
		self::assertIsArray( $issued );
		$smuggled = "<!-- wp:html -->\n" . $css . "<script>alert(1)</script>\n<!-- /wp:html -->";
		$id       = $this->serialized_change( $spec, $smuggled, [ 'allow_raw_html' => true, 'custom_code_grant' => (string) $issued['token'] ], true );

		$result = ( new FinalizeBatch() )->execute( [ 'post_id' => self::POST_ID, 'change_ids' => [ $id ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_serialized_markup_refused', $result->get_error_code() );
		self::assertSame( self::BEFORE, $this->content() );
	}

	/**
	 * @param array<string,mixed> $attributes
	 * @return array<string,mixed>
	 */
	private function paragraph_spec( array $attributes = [] ): array {
		return [
			'name'        => 'core/paragraph',
			'attributes'  => $attributes,
			'innerBlocks' => [],
		];
	}

	/**
	 * Queues the spec, leases it to a browser, and stores the browser's HTML on the queue record.
	 *
	 * @param array<string,mixed> $spec
	 * @param array<string,mixed> $extra Extra enqueue arguments, such as the raw HTML flag and its grant.
	 * @param bool                $bypass_guard Store the HTML the way a record from before the queue checked markup holds it.
	 */
	private function serialized_change( array $spec, string $html, array $extra = [], bool $bypass_guard = false ): string {
		return $this->serialized_batch( [ [ $spec, $html ] ], $extra, $bypass_guard )[0];
	}

	/**
	 * Queues one change per [spec, html] pair as a single batch for the post, leases the batch to a
	 * browser, and stores each browser HTML on its queue record.
	 *
	 * @param list<array{0:array<string,mixed>,1:string}> $changes
	 * @param array<string,mixed> $extra Extra enqueue arguments for every change.
	 * @param bool                $bypass_guard Use the unchecked store, as a record from before the queue checked markup would.
	 * @return list<string> Change ids in the order given.
	 */
	private function serialized_batch( array $changes, array $extra = [], bool $bypass_guard = false ): array {
		$items = [];
		foreach ( $changes as [ $spec ] ) {
			$items[] = array_merge(
				[
					'post_id'               => self::POST_ID,
					'expected_content_hash' => hash( 'sha256', $this->content() ),
					'action'                => 'insert',
					'path'                  => [],
					'block_spec'            => $spec,
				],
				$extra
			);
		}
		$queued = BlockQueue::enqueue_many( $items );
		self::assertIsArray( $queued, is_wp_error( $queued ) ? $queued->get_error_code() : '' );
		$token = BlockQueue::issue_token( (string) $queued[0]['session_id'] );
		self::assertIsArray( $token );
		$scope = BlockQueue::verify_token( (string) $token['token'] );
		self::assertIsArray( $scope );
		BlockQueue::lease_pending_for_scope( $scope, 'lease-1' );
		$ids = [];
		foreach ( $queued as $index => $record ) {
			$html = $changes[ $index ][1];
			if ( $bypass_guard ) {
				self::assertTrue( BlockQueue::store_serialized( (string) $record['id'], $html, hash( 'sha256', $html ), $scope ) );
			} else {
				$receipt = BlockQueue::accept_serialized_result( (string) $record['id'], $html, hash( 'sha256', $html ), $scope, 'lease-1', 'result-' . $index );
				self::assertIsArray( $receipt, is_wp_error( $receipt ) ? $receipt->get_error_code() : '' );
				self::assertSame( 'serialized', $receipt['status'] );
			}
			$ids[] = (string) $record['id'];
		}
		return $ids;
	}

	private function content(): string {
		return (string) $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content;
	}
}

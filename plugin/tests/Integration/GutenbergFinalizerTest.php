<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch;
use Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizationUrl;
use Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime;
use Stonewright\WpMcp\Abilities\Gutenberg\GetPendingBatch;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\QueueBlockChange;
use Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\QueueBlockChange
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetPendingBatch
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizationUrl
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockSource
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock
 */
final class GutenbergFinalizerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts'      => true,
			'edit_post'       => true,
			'manage_options'  => true,
		];
		$GLOBALS['stonewright_test_rest_routes']     = [];
		$GLOBALS['stonewright_test_actions']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_posts']           = [
			42 => (object) [
				'ID'           => 42,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Finalizer target',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/paragraph' => (object) [
				'attributes' => [ 'className' => [ 'type' => 'string' ] ],
			],
			'core/latest-posts' => (object) [
				'attributes'      => [ 'postsToShow' => [ 'type' => 'integer' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
		$GLOBALS['stonewright_test_rest_routes']       = [];
		$GLOBALS['stonewright_test_actions']           = [];
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_queue_block_change_stores_normalized_spec_not_html(): void {
		$result = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Queued' ],
					'innerBlocks' => [],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );
		$stored = BlockQueue::get( (string) $result['change_id'] );
		self::assertSame( 'vendor/card', $stored['block_spec']['name'] );
		self::assertSame( [ 'title' => 'Queued' ], $stored['block_spec']['attributes'] );
		self::assertSame( [], $stored['block_spec']['innerBlocks'] );
		self::assertArrayNotHasKey( 'innerHTML', $stored['block_spec'] );
	}

	public function test_pending_batch_and_runtime_omit_full_block_spec(): void {
		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'hidden-from-list' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$pending = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $pending );
		$encoded = (string) wp_json_encode( $pending );
		self::assertStringNotContainsString( 'hidden-from-list', $encoded );
		self::assertArrayHasKey( 'items', $pending );
		self::assertArrayNotHasKey( 'block_spec', $pending['items'][0] );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertSame( 1, $runtime['queued_count'] );
		self::assertStringNotContainsString( 'hidden-from-list', (string) wp_json_encode( $runtime ) );

		$url = ( new GetFinalizationUrl() )->execute( [] );
		self::assertIsArray( $url );
		self::assertStringContainsString( 'stonewright-block-finalizer', (string) $url['url'] );
	}

	public function test_finalize_batch_rejects_html_that_did_not_come_from_the_queue(): void {
		$rejected = ( new FinalizeBatch() )->execute(
			[
				'post_id' => 42,
				'html'    => '<!-- wp:paragraph --><p>smuggled</p><!-- /wp:paragraph -->',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $rejected );
		self::assertSame( 'stonewright_finalizer_queue_required', $rejected->get_error_code() );
		self::assertSame( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->', $GLOBALS['stonewright_test_posts'][42]->post_content );

		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Card' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$not_ready = ( new FinalizeBatch() )->execute(
			[ 'change_ids' => [ $queued['change_id'] ] ]
		);
		self::assertInstanceOf( \WP_Error::class, $not_ready );
		self::assertSame( 'stonewright_finalizer_not_serialized', $not_ready->get_error_code() );
	}

	public function test_finalize_batch_persists_only_hashed_serialized_html_from_the_queue(): void {
		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Card' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$html = '<!-- wp:vendor/card {"title":"Card"} --><div class="card">Card</div><!-- /wp:vendor/card -->';
		$stored = BlockQueue::store_serialized( (string) $queued['change_id'], $html, hash( 'sha256', $html ) );
		self::assertTrue( $stored );

		$result = ( new FinalizeBatch() )->execute(
			[ 'change_ids' => [ $queued['change_id'] ] ]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertStringContainsString( $html, (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
		self::assertStringContainsString( 'Before', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
		self::assertNotEmpty( $GLOBALS['stonewright_test_posts'][42]->meta['_stonewright_backups'] ?? [] );
	}

	public function test_hmac_token_invalid_returns_403(): void {
		FinalizerPage::register();
		do_action( 'rest_api_init' );

		$route = $this->find_route( '/block-finalizer/pending' );
		self::assertNotNull( $route );
		$permission = $route['args']['permission_callback'];
		self::assertIsCallable( $permission );

		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/block-finalizer/pending' );
		$request->set_params( [ 'token' => 'not-a-real-hmac' ] );
		$result = $permission( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_insert_block_queues_static_third_party_instead_of_server_serializing(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][42]->post_content;
		$result = ( new InsertBlock() )->execute(
			[
				'post_id' => 42,
				'block'   => [
					'name'       => 'vendor/card',
					'attributes' => [ 'title' => 'Queued insert' ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][42]->post_content );
		$stored = BlockQueue::get( (string) $result['change_id'] );
		self::assertSame( 'vendor/card', $stored['block_spec']['name'] );
		self::assertArrayNotHasKey( 'innerHTML', $stored['block_spec'] );
	}

	public function test_insert_block_keeps_server_fast_path_for_dynamic_blocks(): void {
		$result = ( new InsertBlock() )->execute(
			[
				'post_id' => 42,
				'block'   => [
					'name'  => 'core/latest-posts',
					'attrs' => [ 'postsToShow' => 3 ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'queued', $result );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertStringContainsString( 'latest-posts', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}

	public function test_finalizer_page_is_hidden_and_asks_to_keep_the_session_open(): void {
		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
		FinalizerPage::register();
		do_action( 'admin_menu' );

		$slug = FinalizerPage::SLUG;
		self::assertArrayHasKey( $slug, $GLOBALS['stonewright_test_submenu_pages'] );
		self::assertSame( '', $GLOBALS['stonewright_test_submenu_pages'][ $slug ]['parent'] );

		$_GET['page'] = $slug;
		ob_start();
		FinalizerPage::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Keep this page open while a session runs', $html );
		self::assertStringContainsString( 'queued', strtolower( $html ) );
	}

	public function test_client_side_block_is_not_server_serialized_and_runtime_exposes_editor_frame_url(): void {
		$before = (string) $GLOBALS['stonewright_test_posts'][42]->post_content;
		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Client only' ],
					'innerBlocks' => [],
				],
			]
		);

		self::assertIsArray( $queued );
		self::assertTrue( $queued['queued'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][42]->post_content );

		$stored = BlockQueue::get( (string) $queued['change_id'] );
		self::assertIsArray( $stored );
		self::assertSame( '', (string) ( $stored['serialized_html'] ?? '' ) );
		self::assertSame( 'queued', $stored['status'] );

		$server_html = \Stonewright\WpMcp\Support\BlockSerializer::serialize(
			[
				[
					'blockName'    => 'vendor/card',
					'attrs'        => [ 'title' => 'Client only' ],
					'innerHTML'    => '',
					'innerContent' => [ '' ],
					'innerBlocks'  => [],
				],
			]
		);
		self::assertStringNotContainsString( $server_html, (string) $GLOBALS['stonewright_test_posts'][42]->post_content );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertIsBool( $runtime['online'] );
		self::assertArrayHasKey( 'targets', $runtime );
		self::assertNotEmpty( $runtime['targets'] );
		self::assertSame( 42, (int) $runtime['targets'][0]['post_id'] );
		self::assertSame(
			'https://example.test/wp-admin/post.php?post=42&action=edit',
			(string) $runtime['targets'][0]['editor_frame_url']
		);
		self::assertStringNotContainsString( 'Client only', (string) wp_json_encode( $runtime ) );

		FinalizerPage::register();
		do_action( 'rest_api_init' );
		$issued  = BlockQueue::issue_token();
		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/block-finalizer/pending' );
		$request->set_params( [ 'token' => $issued['token'] ] );
		$response = FinalizerPage::rest_pending( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame(
			'https://example.test/wp-admin/post.php?post=42&action=edit',
			(string) ( $data['items'][0]['editor_url'] ?? '' )
		);
		self::assertSame( 'vendor/card', (string) ( $data['items'][0]['block_spec']['name'] ?? '' ) );

		$_GET['page'] = FinalizerPage::SLUG;
		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
		ob_start();
		FinalizerPage::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="stonewright-finalizer-frame"', $html );
	}

	public function test_result_endpoint_recomputes_hash_when_client_flags_hash_unavailable(): void {
		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Card' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$html     = '<!-- wp:vendor/card {"title":"Card"} --><div class="card">Card</div><!-- /wp:vendor/card -->';
		$request  = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result' );
		$request->set_json_params(
			[
				'change_id'         => $queued['change_id'],
				'html'              => $html,
				'html_hash'         => '',
				'hash_unavailable'  => true,
			]
		);
		$response = FinalizerPage::rest_result( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertTrue( $data['ok'] );
		self::assertSame( 'serialized', $data['status'] );

		$stored = BlockQueue::get( (string) $queued['change_id'] );
		self::assertIsArray( $stored );
		self::assertSame( 'serialized', $stored['status'] );
		self::assertSame( $html, $stored['serialized_html'] );
		self::assertSame( hash( 'sha256', $html ), $stored['serialized_html_hash'] );
	}

	public function test_finalize_update_on_nested_path_preserves_outside_bytes_and_existing_children(): void {
		$child   = '<!-- wp:paragraph --><p>KEEP_CHILD_INNER</p><!-- /wp:paragraph -->';
		$target  = '<!-- wp:vendor/card {"title":"Old"} --><div class="card-wrap">' . $child . '</div><!-- /wp:vendor/card -->';
		$sibling = '<!-- wp:vendor/hero {"z": 1, "a": 2} --><div data-keep="SIBLING_BYTE_MARK">Hero</div><!-- /wp:vendor/hero -->';
		$other   = '<!-- wp:paragraph --><p>Keep me too</p><!-- /wp:paragraph -->';
		$content = '<!-- wp:group --><div class="wp-block-group">' . $target . $sibling . '</div><!-- /wp:group -->' . $other;
		$GLOBALS['stonewright_test_posts'][42]->post_content = $content;

		$queued = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => hash( 'sha256', $content ),
				'action'                => 'update',
				'path'                  => [ 0, 0 ],
				'block_spec'            => [
					'name'       => 'vendor/card',
					'attributes' => [ 'title' => 'New' ],
				],
			]
		);
		self::assertIsArray( $queued );

		$html = '<!-- wp:vendor/card {"title":"New"} --><div class="card">New</div><!-- /wp:vendor/card -->';
		self::assertTrue( BlockQueue::store_serialized( (string) $queued['id'], $html, hash( 'sha256', $html ) ) );

		$result = ( new FinalizeBatch() )->execute( [ 'change_ids' => [ $queued['id'] ] ] );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );

		$after = (string) $GLOBALS['stonewright_test_posts'][42]->post_content;
		self::assertStringContainsString( $sibling, $after );
		self::assertStringContainsString( '{"z": 1, "a": 2}', $after );
		self::assertStringContainsString( $other, $after );
		self::assertStringNotContainsString( '"title":"Old"', $after );
		self::assertMatchesRegularExpression(
			'/<!-- wp:vendor\/card \{"title":"New"\} -->.*KEEP_CHILD_INNER.*<!-- \/wp:vendor\/card -->/s',
			$after
		);
	}

	public function test_finalize_insert_at_position_two_in_parent_path_lands_at_index_two(): void {
		$a       = '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->';
		$b       = '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->';
		$c       = '<!-- wp:paragraph --><p>C</p><!-- /wp:paragraph -->';
		$content = '<!-- wp:group --><div class="wp-block-group">' . $a . $b . $c . '</div><!-- /wp:group -->';
		$GLOBALS['stonewright_test_posts'][42]->post_content = $content;

		$queued = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => hash( 'sha256', $content ),
				'action'                => 'insert',
				'path'                  => [ 0 ],
				'position'              => 2,
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Mid' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$html = '<!-- wp:vendor/card {"title":"Mid"} --><div class="card">Mid</div><!-- /wp:vendor/card -->';
		self::assertTrue( BlockQueue::store_serialized( (string) $queued['id'], $html, hash( 'sha256', $html ) ) );

		$result = ( new FinalizeBatch() )->execute( [ 'change_ids' => [ $queued['id'] ] ] );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );

		$after = (string) $GLOBALS['stonewright_test_posts'][42]->post_content;
		self::assertMatchesRegularExpression(
			'/<!-- wp:paragraph --><p>A<\/p><!-- \/wp:paragraph -->.*<!-- wp:paragraph --><p>B<\/p><!-- \/wp:paragraph -->.*<!-- wp:vendor\/card \{"title":"Mid"\} -->.*<!-- wp:paragraph --><p>C<\/p><!-- \/wp:paragraph -->/s',
			$after
		);
		self::assertStringNotContainsString(
			$c . '<!-- wp:vendor/card {"title":"Mid"} -->',
			$after
		);
	}

	public function test_update_block_omits_inner_blocks_when_caller_does_not_supply_them(): void {
		$result = ( new UpdateBlock() )->execute(
			[
				'post_id' => 42,
				'path'    => [ 0 ],
				'attrs'   => [ 'className' => 'updated' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );
		$stored = BlockQueue::get( (string) $result['change_id'] );
		self::assertIsArray( $stored );
		self::assertSame( 'update', $stored['action'] );
		self::assertArrayNotHasKey( 'innerBlocks', $stored['block_spec'] );
	}

	public function test_result_endpoint_still_rejects_empty_hash_without_unavailable_flag(): void {
		$queued = ( new QueueBlockChange() )->execute(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Card' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$html     = '<!-- wp:vendor/card {"title":"Card"} --><div class="card">Card</div><!-- /wp:vendor/card -->';
		$request  = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result' );
		$request->set_json_params(
			[
				'change_id' => $queued['change_id'],
				'html'      => $html,
				'html_hash' => 'pending',
			]
		);
		$response = FinalizerPage::rest_result( $request );
		self::assertInstanceOf( \WP_Error::class, $response );
		self::assertSame( 'stonewright_finalizer_hash_mismatch', $response->get_error_code() );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_route( string $route ): ?array {
		foreach ( $GLOBALS['stonewright_test_rest_routes'] as $entry ) {
			if ( $route === (string) ( $entry['route'] ?? '' ) ) {
				return $entry;
			}
		}
		return null;
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}
}

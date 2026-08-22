<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\FinalizeBatch;
use Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime;
use Stonewright\WpMcp\Abilities\Gutenberg\GetPendingBatch;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\FinalizerPage
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetPendingBatch
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime
 */
final class FinalizerAuthorizationTest extends TestCase {

	private const USER_A = 401;
	private const USER_B = 402;
	private const ADMIN  = 403;
	private const POST_A = 42;
	private const POST_B = 43;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = self::USER_A;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts' => true,
			'edit_post'  => true,
		];
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_A => $this->post( self::POST_A, 'Target A' ),
			self::POST_B => $this->post( self::POST_B, 'Target B' ),
		];
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_before_option_delete'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_before_option_delete'] );
	}

	public function test_user_a_cannot_see_serialize_or_fail_records_of_user_b(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Secret A' );
		self::assertIsArray( $owned );

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );

		$pending = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $pending );
		self::assertSame( [], $pending['items'] );
		self::assertSame( 0, (int) $pending['queued_count'] );
		self::assertStringNotContainsString( 'Secret A', (string) wp_json_encode( $pending ) );

		$issued = BlockQueue::issue_token();
		self::assertInstanceOf( \WP_Error::class, $issued );

		$stored = BlockQueue::store_serialized(
			(string) $owned['id'],
			'<!-- wp:vendor/card /-->',
			hash( 'sha256', '<!-- wp:vendor/card /-->' )
		);
		self::assertInstanceOf( \WP_Error::class, $stored );
		self::assertSame( 403, (int) ( $stored->get_error_data()['status'] ?? 0 ) );
		self::assertStringNotContainsString( (string) self::USER_A, (string) wp_json_encode( $stored ) );

		$failed = BlockQueue::mark_failed( (string) $owned['id'], 'nope' );
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 403, (int) ( $failed->get_error_data()['status'] ?? 0 ) );
		self::assertStringNotContainsString( (string) self::USER_A, (string) wp_json_encode( $failed ) );

		$fresh = BlockQueue::get( (string) $owned['id'] );
		self::assertIsArray( $fresh );
		self::assertSame( 'queued', $fresh['status'] );
	}

	public function test_session_a_cannot_touch_session_b(): void {
		$first = $this->enqueue_card( self::POST_A, 'One' );
		self::assertIsArray( $first );
		$second = $this->enqueue_card( self::POST_B, 'Two' );
		self::assertIsArray( $second );
		self::assertNotSame( $first['session_id'], $second['session_id'] );

		$scope = [
			'session_id'    => (string) $first['session_id'],
			'owner_user_id' => self::USER_A,
			'post_id'       => self::POST_A,
		];
		$html = '<!-- wp:vendor/card {"title":"Two"} --><div>Two</div><!-- /wp:vendor/card -->';
		$stored = BlockQueue::store_serialized( (string) $second['id'], $html, hash( 'sha256', $html ), $scope );
		self::assertInstanceOf( \WP_Error::class, $stored );
		self::assertSame( 403, (int) ( $stored->get_error_data()['status'] ?? 0 ) );
		self::assertSame( 'queued', BlockQueue::get( (string) $second['id'] )['status'] ?? '' );
	}

	public function test_token_for_post_a_cannot_touch_post_b(): void {
		$first = $this->enqueue_card( self::POST_A, 'A' );
		$second = $this->enqueue_card( self::POST_B, 'B' );
		self::assertIsArray( $first );
		self::assertIsArray( $second );

		$issued = BlockQueue::issue_token( (string) $first['session_id'] );
		self::assertIsArray( $issued );
		$verified = BlockQueue::verify_token( $issued['token'] );
		self::assertIsArray( $verified );
		self::assertSame( self::POST_A, (int) $verified['post_id'] );

		$html = '<!-- wp:vendor/card --><div>B</div><!-- /wp:vendor/card -->';
		$stored = BlockQueue::store_serialized(
			(string) $second['id'],
			$html,
			hash( 'sha256', $html ),
			$verified
		);
		self::assertInstanceOf( \WP_Error::class, $stored );
		self::assertSame( 403, (int) ( $stored->get_error_data()['status'] ?? 0 ) );
	}

	public function test_expired_token_is_rejected(): void {
		$queued = $this->enqueue_card( self::POST_A, 'Soon' );
		self::assertIsArray( $queued );

		$token = $this->forge_token(
			[
				's' => (string) $queued['session_id'],
				'u' => self::USER_A,
				'p' => self::POST_A,
				'e' => time() - 10,
			]
		);
		$verified = BlockQueue::verify_token( $token );
		self::assertInstanceOf( \WP_Error::class, $verified );
		self::assertSame( 403, (int) ( $verified->get_error_data()['status'] ?? 0 ) );
	}

	public function test_legacy_hmac_without_post_id_fails_closed(): void {
		$queued = $this->enqueue_card( self::POST_A, 'Legacy token' );
		self::assertIsArray( $queued );

		$token = $this->forge_token(
			[
				's' => (string) $queued['session_id'],
				'u' => self::USER_A,
				'e' => time() + 3600,
			]
		);
		$verified = BlockQueue::verify_token( $token );
		self::assertInstanceOf( \WP_Error::class, $verified );
		self::assertSame( 'stonewright_finalizer_forbidden', $verified->get_error_code() );
	}

	public function test_replay_after_serialized_failed_or_persisted_returns_409(): void {
		$html = '<!-- wp:vendor/card --><div>Card</div><!-- /wp:vendor/card -->';
		$hash = hash( 'sha256', $html );

		$serialized = $this->enqueue_card( self::POST_A, 'Ser' );
		self::assertIsArray( $serialized );
		self::assertTrue( BlockQueue::store_serialized( (string) $serialized['id'], $html, $hash ) );
		$replay = BlockQueue::store_serialized( (string) $serialized['id'], $html, $hash );
		self::assertInstanceOf( \WP_Error::class, $replay );
		self::assertSame( 409, (int) ( $replay->get_error_data()['status'] ?? 0 ) );

		$failed = $this->enqueue_card( self::POST_B, 'Fail' );
		self::assertIsArray( $failed );
		self::assertTrue( BlockQueue::mark_failed( (string) $failed['id'], 'boom', $html, 'serialize_roundtrip_failed' ) );
		$fail_replay = BlockQueue::store_serialized( (string) $failed['id'], $html, $hash );
		self::assertInstanceOf( \WP_Error::class, $fail_replay );
		self::assertSame( 409, (int) ( $fail_replay->get_error_data()['status'] ?? 0 ) );

		$GLOBALS['stonewright_test_posts'][44] = $this->post( 44, 'Target C' );
		$persisted = $this->enqueue_card( 44, 'Persisted' );
		self::assertIsArray( $persisted );
		self::assertTrue( BlockQueue::store_serialized( (string) $persisted['id'], $html, $hash ) );
		BlockQueue::mark_persisted( (string) $persisted['id'] );
		$persist_replay = BlockQueue::store_serialized( (string) $persisted['id'], $html, $hash );
		self::assertInstanceOf( \WP_Error::class, $persist_replay );
		self::assertSame( 409, (int) ( $persist_replay->get_error_data()['status'] ?? 0 ) );

		$GLOBALS['stonewright_test_posts'][45] = $this->post( 45, 'Target D' );
		$cancelled = $this->enqueue_card( 45, 'Cancelled' );
		self::assertIsArray( $cancelled );
		$state = get_option( BlockQueue::OPTION );
		self::assertIsArray( $state );
		$cancelled_id = (string) $cancelled['id'];
		$state['changes'][ $cancelled_id ]['status'] = 'cancelled';
		update_option( BlockQueue::OPTION, $state, false );
		$cancel_replay = BlockQueue::store_serialized( $cancelled_id, $html, $hash );
		self::assertInstanceOf( \WP_Error::class, $cancel_replay );
		self::assertSame( 409, (int) ( $cancel_replay->get_error_data()['status'] ?? 0 ) );
	}

	public function test_manage_options_editor_can_see_foreign_records_but_cannot_serialize(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Foreign payload' );
		self::assertIsArray( $owned );

		$this->become(
			self::ADMIN,
			[
				'edit_posts'     => true,
				'edit_post'      => true,
				'manage_options' => true,
			]
		);

		$pending = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $pending );
		self::assertNotEmpty( $pending['items'] );
		self::assertSame( (string) $owned['id'], (string) $pending['items'][0]['id'] );
		self::assertStringNotContainsString( 'Foreign payload', (string) wp_json_encode( $pending ) );
		self::assertArrayNotHasKey( 'block_spec', $pending['items'][0] );

		$html = '<!-- wp:vendor/card --><div>Nope</div><!-- /wp:vendor/card -->';
		$stored = BlockQueue::store_serialized( (string) $owned['id'], $html, hash( 'sha256', $html ) );
		self::assertInstanceOf( \WP_Error::class, $stored );
		self::assertSame( 403, (int) ( $stored->get_error_data()['status'] ?? 0 ) );
		self::assertStringNotContainsString( (string) self::USER_A, (string) wp_json_encode( $stored ) );

		$issued = BlockQueue::issue_token( (string) $owned['session_id'] );
		self::assertInstanceOf( \WP_Error::class, $issued );

		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
		$_GET['page'] = FinalizerPage::SLUG;
		ob_start();
		FinalizerPage::render();
		$page = (string) ob_get_clean();
		self::assertStringContainsString( 'These queued changes belong to another user', $page );
		self::assertStringContainsString( 'stonewright/blocks-finalizer-cancel', $page );
		self::assertStringNotContainsString( 'Foreign payload', $page );
	}

	public function test_generic_editor_does_not_see_foreign_or_legacy_records(): void {
		$this->seed_v1_open_record( self::POST_A, 'legacy-open' );
		$this->become( self::USER_A, [ 'edit_posts' => true, 'edit_post' => true ] );
		$owned = $this->enqueue_card( self::POST_B, 'Mine' );
		self::assertIsArray( $owned );

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );
		$pending = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $pending );
		self::assertSame( [], $pending['items'] );
		self::assertSame( 0, (int) $pending['queued_count'] );
		self::assertSame( 0, (int) $pending['failed_count'] );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertSame( 0, (int) $runtime['queued_count'] );
		self::assertSame( 0, (int) $runtime['failed_count'] );
		self::assertSame( [], $runtime['targets'] );
		self::assertSame( [], $runtime['sessions'] ?? [] );
	}

	public function test_default_token_and_url_bind_to_open_session_not_persisted(): void {
		$first = $this->enqueue_card( self::POST_A, 'Persisted first' );
		self::assertIsArray( $first );
		$html = '<!-- wp:vendor/card --><div>A</div><!-- /wp:vendor/card -->';
		self::assertTrue( BlockQueue::store_serialized( (string) $first['id'], $html, hash( 'sha256', $html ) ) );
		BlockQueue::mark_persisted( (string) $first['id'] );
		self::assertSame( 'persisted', BlockQueue::get( (string) $first['id'] )['status'] ?? '' );

		$second = $this->enqueue_card( self::POST_B, 'Open second' );
		self::assertIsArray( $second );
		self::assertNotSame( $first['session_id'], $second['session_id'] );

		$issued = BlockQueue::issue_token();
		self::assertIsArray( $issued );
		self::assertSame( (string) $second['session_id'], $issued['session_id'] );
		self::assertSame( self::POST_B, (int) $issued['post_id'] );

		$verified = $this->token_from_url( FinalizerPage::url() );
		self::assertIsArray( $verified );
		self::assertSame( (string) $second['session_id'], (string) $verified['session_id'] );
		self::assertSame( self::POST_B, (int) $verified['post_id'] );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertSame( (string) $second['session_id'], (string) $runtime['session_id'] );
		$runtime_verified = $this->token_from_url( (string) $runtime['finalizer_url'] );
		self::assertIsArray( $runtime_verified );
		self::assertSame( (string) $second['session_id'], (string) $runtime_verified['session_id'] );
	}

	public function test_user_b_cannot_finalize_or_mark_persisted_user_a_serialized_change(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Owner payload' );
		self::assertIsArray( $owned );
		$html = '<!-- wp:vendor/card --><div>Owner</div><!-- /wp:vendor/card -->';
		self::assertTrue( BlockQueue::store_serialized( (string) $owned['id'], $html, hash( 'sha256', $html ) ) );
		$before = (string) $GLOBALS['stonewright_test_posts'][ self::POST_A ]->post_content;

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true, 'manage_options' => true ] );

		$ability = new FinalizeBatch();
		$args    = [ 'change_ids' => [ (string) $owned['id'] ] ];
		$perm    = $ability->permission_callback( $args );
		self::assertTrue( false === $perm || $perm instanceof \WP_Error );

		$result = $ability->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertStringNotContainsString( (string) self::USER_A, (string) wp_json_encode( $result ) );
		self::assertSame( $before, (string) $GLOBALS['stonewright_test_posts'][ self::POST_A ]->post_content );
		self::assertSame( 'serialized', BlockQueue::get( (string) $owned['id'] )['status'] ?? '' );

		$persisted = BlockQueue::mark_persisted( (string) $owned['id'] );
		self::assertInstanceOf( \WP_Error::class, $persisted );
		self::assertSame( 403, (int) ( $persisted->get_error_data()['status'] ?? 0 ) );
		self::assertSame( 'serialized', BlockQueue::get( (string) $owned['id'] )['status'] ?? '' );
	}

	public function test_foreign_pending_conflict_omits_change_id_and_cancel_copy(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Owner pending' );
		self::assertIsArray( $owned );

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );
		$second = $this->enqueue_card( self::POST_A, 'Intruder' );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_finalizer_pending_change', $second->get_error_code() );
		self::assertSame( 409, (int) ( $second->get_error_data()['status'] ?? 0 ) );
		self::assertArrayNotHasKey( 'change_id', (array) $second->get_error_data() );
		self::assertSame( 'This post already has a pending block finalizer change.', $second->get_error_message() );
		self::assertStringNotContainsString( 'cancel', strtolower( $second->get_error_message() ) );
		self::assertStringNotContainsString( (string) $owned['id'], (string) wp_json_encode( $second ) );
		self::assertStringNotContainsString( (string) self::USER_A, (string) wp_json_encode( $second ) );
	}

	public function test_generic_editor_sees_owner_mismatch_without_foreign_ids(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Hidden foreign spec' );
		self::assertIsArray( $owned );

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );
		$pending = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $pending );
		self::assertSame( [], $pending['items'] );

		if ( ! defined( 'STONEWRIGHT_URL' ) ) {
			define( 'STONEWRIGHT_URL', 'https://example.test/wp-content/plugins/stonewright/' );
		}
		$_GET['page'] = FinalizerPage::SLUG;
		ob_start();
		FinalizerPage::render();
		$page = (string) ob_get_clean();
		self::assertStringContainsString( 'These queued changes belong to another user', $page );
		self::assertStringContainsString( 'stonewright/blocks-finalizer-cancel', $page );
		self::assertStringNotContainsString( (string) $owned['id'], $page );
		self::assertStringNotContainsString( 'Hidden foreign spec', $page );
	}

	public function test_legacy_v1_state_migrates_open_records_to_failed_unbound(): void {
		$this->seed_v1_open_record( self::POST_A, 'legacy-copy' );

		$list = BlockQueue::list();
		$state = get_option( BlockQueue::OPTION );
		self::assertIsArray( $state );
		self::assertSame( 2, (int) ( $state['schema_version'] ?? 0 ) );
		self::assertArrayNotHasKey( 'session_id', $state );
		self::assertNotEmpty( $list );
		$record = BlockQueue::get( 'legacy-change-1' );
		self::assertIsArray( $record );
		self::assertTrue( ! empty( $record['legacy'] ) );
		self::assertSame( 0, (int) $record['owner_user_id'] );
		self::assertSame( 'failed', $record['status'] );
		self::assertSame( 'legacy_session_unbound', (string) ( $record['error_code'] ?? '' ) );

		$html = '<!-- wp:vendor/card /-->';
		$stored = BlockQueue::store_serialized( 'legacy-change-1', $html, hash( 'sha256', $html ) );
		self::assertInstanceOf( \WP_Error::class, $stored );

		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );
		$editor = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $editor );
		self::assertSame( [], $editor['items'] );

		$this->become(
			self::ADMIN,
			[
				'edit_posts'     => true,
				'edit_post'      => true,
				'manage_options' => true,
			]
		);
		$admin = ( new GetPendingBatch() )->execute( [] );
		self::assertIsArray( $admin );
		self::assertNotEmpty( $admin['items'] );
		self::assertSame( 'legacy-change-1', $admin['items'][0]['id'] );
		self::assertSame( 'failed', $admin['items'][0]['status'] );
		self::assertStringNotContainsString( 'legacy-copy', (string) wp_json_encode( $admin ) );
	}

	public function test_rest_pending_and_result_are_scoped_to_verified_token(): void {
		$owned = $this->enqueue_card( self::POST_A, 'Scoped' );
		self::assertIsArray( $owned );
		$this->become( self::USER_B, [ 'edit_posts' => true, 'edit_post' => true ] );
		$other = $this->enqueue_card( self::POST_B, 'Other' );
		self::assertIsArray( $other );

		$this->become( self::USER_A, [ 'edit_posts' => true, 'edit_post' => true ] );
		$issued = BlockQueue::issue_token( (string) $owned['session_id'] );
		self::assertIsArray( $issued );

		$request = new \WP_REST_Request( 'GET', '/stonewright/v1/block-finalizer/pending' );
		$request->set_params( [ 'token' => $issued['token'] ] );
		$response = FinalizerPage::rest_pending( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertCount( 1, $data['items'] );
		self::assertSame( (string) $owned['id'], (string) $data['items'][0]['id'] );
		self::assertSame( 1, (int) $data['queued_count'] );
		self::assertSame( 0, (int) $data['failed_count'] );

		$html = '<!-- wp:vendor/card --><div>Other</div><!-- /wp:vendor/card -->';
		$result = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result' );
		$result->set_json_params(
			[
				'token'     => $issued['token'],
				'change_id' => $other['id'],
				'html'      => $html,
				'html_hash' => hash( 'sha256', $html ),
			]
		);
		$denied = FinalizerPage::rest_result( $result );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 403, (int) ( $denied->get_error_data()['status'] ?? 0 ) );
		self::assertStringNotContainsString( (string) self::USER_B, (string) wp_json_encode( $denied ) );
	}

	public function test_runtime_emits_one_token_per_owned_session(): void {
		$first = $this->enqueue_card( self::POST_A, 'A' );
		$second = $this->enqueue_card( self::POST_B, 'B' );
		self::assertIsArray( $first );
		self::assertIsArray( $second );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertArrayHasKey( 'sessions', $runtime );
		self::assertCount( 2, $runtime['sessions'] );
		$urls = [
			(string) $runtime['sessions'][0]['queue_url'],
			(string) $runtime['sessions'][1]['queue_url'],
		];
		self::assertNotSame( $urls[0], $urls[1] );
		self::assertStringContainsString( 'token=', $urls[0] );
		self::assertSame( 2, (int) $runtime['queued_count'] );
		self::assertStringNotContainsString( '"block_spec"', (string) wp_json_encode( $runtime ) );
	}

	public function test_concurrent_mutations_do_not_lose_records(): void {
		$nested = null;
		$GLOBALS['stonewright_test_after_add_option'] = static function ( string $option ) use ( &$nested ): void {
			if ( BlockQueue::LOCK_OPTION !== $option ) {
				return;
			}
			$nested = BlockQueue::enqueue(
				[
					'post_id'               => self::POST_B,
					'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][ self::POST_B ]->post_content ),
					'block_spec'            => [
						'name'        => 'vendor/card',
						'attributes'  => [ 'title' => 'Nested' ],
						'innerBlocks' => [],
					],
				]
			);
		};

		$first = $this->enqueue_card( self::POST_A, 'Outer' );
		self::assertIsArray( $first );
		self::assertIsArray( $nested );
		self::assertNotSame( $first['id'], $nested['id'] );
		self::assertNotNull( BlockQueue::get( (string) $first['id'] ) );
		self::assertNotNull( BlockQueue::get( (string) $nested['id'] ) );
	}

	public function test_stale_lock_recovers_and_foreign_lock_is_not_deleted(): void {
		update_option(
			BlockQueue::LOCK_OPTION,
			[
				'token'         => 'stale-lock',
				'owner_user_id' => 99,
				'expires_at'    => time() - 5,
			],
			false
		);
		$recovered = $this->enqueue_card( self::POST_A, 'Recovered' );
		self::assertIsArray( $recovered );
		self::assertArrayNotHasKey( BlockQueue::LOCK_OPTION, $GLOBALS['stonewright_test_options'] );

		update_option(
			BlockQueue::LOCK_OPTION,
			[
				'token'         => 'live-foreign',
				'owner_user_id' => 99,
				'expires_at'    => time() + 30,
			],
			false
		);
		$before = $GLOBALS['stonewright_test_options'][ BlockQueue::OPTION ];
		$busy   = $this->enqueue_card( self::POST_B, 'Blocked' );
		self::assertInstanceOf( \WP_Error::class, $busy );
		self::assertContains( (int) ( $busy->get_error_data()['status'] ?? 0 ), [ 409, 503 ] );
		self::assertSame( $before, $GLOBALS['stonewright_test_options'][ BlockQueue::OPTION ] );
		self::assertSame( 'live-foreign', $GLOBALS['stonewright_test_options'][ BlockQueue::LOCK_OPTION ]['token'] );
	}

	public function test_expired_takeover_cannot_delete_a_newer_live_lock(): void {
		update_option(
			BlockQueue::LOCK_OPTION,
			[
				'token'         => 'expired-observer',
				'owner_user_id' => 99,
				'expires_at'    => time() - 1,
			],
			false
		);
		$GLOBALS['stonewright_test_before_option_delete'] = static function ( string $option ): void {
			if ( BlockQueue::LOCK_OPTION !== $option ) {
				return;
			}
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'token'         => 'replacement-live',
				'owner_user_id' => 77,
				'expires_at'    => time() + 30,
			];
		};

		$busy = $this->enqueue_card( self::POST_A, 'Should not steal' );
		self::assertInstanceOf( \WP_Error::class, $busy );
		self::assertSame( 'replacement-live', $GLOBALS['stonewright_test_options'][ BlockQueue::LOCK_OPTION ]['token'] );
		self::assertNull( BlockQueue::get( 'missing' ) );
		self::assertSame( 0, BlockQueue::pending_count() );
	}

	/**
	 * @param array<string, bool> $caps
	 */
	private function become( int $user_id, array $caps ): void {
		$GLOBALS['stonewright_test_current_user_id'] = $user_id;
		$GLOBALS['stonewright_test_user_logged_in']  = $user_id > 0;
		$GLOBALS['stonewright_test_user_caps']       = $caps;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enqueue_card( int $post_id, string $title ): array|\WP_Error {
		return BlockQueue::enqueue(
			[
				'post_id'               => $post_id,
				'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->post_content ),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => $title ],
					'innerBlocks' => [],
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function token_from_url( string $url ): array|\WP_Error {
		$query = [];
		parse_str( (string) ( parse_url( $url, PHP_URL_QUERY ) ?: '' ), $query );
		return BlockQueue::verify_token( (string) ( $query['token'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function forge_token( array $payload ): string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$body = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
	}

	private function seed_v1_open_record( int $post_id, string $secret ): void {
		update_option(
			BlockQueue::OPTION,
			[
				'session_id' => 'global-v1-session',
				'changes'    => [
					'legacy-change-1' => [
						'id'                    => 'legacy-change-1',
						'post_id'               => $post_id,
						'status'                => 'queued',
						'block_spec'            => [
							'name'        => 'vendor/card',
							'attributes'  => [ 'title' => $secret ],
							'innerBlocks' => [],
						],
						'action'                => 'insert',
						'path'                  => [],
						'position'              => null,
						'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->post_content ),
						'serialized_html'       => '',
						'serialized_html_hash'  => '',
						'created_at'            => time(),
					],
				],
			],
			false
		);
	}

	private function post( int $id, string $title ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
	}
}

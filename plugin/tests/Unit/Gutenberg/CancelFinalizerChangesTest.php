<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\CancelFinalizerChanges;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\CancelFinalizerChanges
 */
final class CancelFinalizerChangesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
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
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_filters'], $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_permission_denies_when_edit_post_is_false(): void {
		$queued = $this->enqueue_card( 'Denied' );
		self::assertIsArray( $queued );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_post' => false ];
		$perm = ( new CancelFinalizerChanges() )->permission_callback(
			[ 'change_ids' => [ (string) $queued['id'] ] ]
		);
		self::assertFalse( $perm );
	}

	public function test_production_safe_requires_token_bound_to_sorted_ids(): void {
		$batch = BlockQueue::enqueue_many(
			[
				$this->card_args( 'One' ),
				$this->card_args( 'Two' ),
			]
		);
		self::assertIsArray( $batch );
		$ids = [
			(string) $batch[0]['id'],
			(string) $batch[1]['id'],
		];
		sort( $ids, SORT_STRING );
		$reversed = array_reverse( $ids );

		$ability = new CancelFinalizerChanges();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$blocked = $ability->execute( [ 'change_ids' => $reversed ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertNotNull( BlockQueue::get( $ids[0] ) );

		$wrong = ConfirmationToken::issue(
			'stonewright/blocks-finalizer-cancel',
			[ 'change_ids' => [ $ids[0] ] ]
		);
		$mismatch = $ability->execute(
			[
				'change_ids'         => $reversed,
				'confirmation_token' => $wrong,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $mismatch );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $mismatch->get_error_code() );
		self::assertNotNull( BlockQueue::get( $ids[0] ) );
		self::assertNotNull( BlockQueue::get( $ids[1] ) );

		$token  = ConfirmationToken::issue(
			'stonewright/blocks-finalizer-cancel',
			[ 'change_ids' => $ids ]
		);
		$result = $ability->execute(
			[
				'change_ids'         => $reversed,
				'confirmation_token' => $token,
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( $ids, $result['change_ids'] );
		self::assertSame( 2, (int) $result['removed_count'] );
		self::assertNull( BlockQueue::get( $ids[0] ) );
		self::assertNull( BlockQueue::get( $ids[1] ) );
	}

	public function test_audit_event_is_recorded_and_output_omits_block_spec(): void {
		$queued = $this->enqueue_card( 'Secret spec copy' );
		self::assertIsArray( $queued );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		$result = ( new CancelFinalizerChanges() )->execute(
			[ 'change_ids' => [ (string) $queued['id'] ] ]
		);
		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'block_spec', $result );
		self::assertStringNotContainsString( 'Secret spec copy', (string) wp_json_encode( $result ) );

		$inserts = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $inserts );
		$found = false;
		foreach ( $inserts as $insert ) {
			$data = is_array( $insert['data'] ?? null ) ? $insert['data'] : [];
			if ( 'stonewright/blocks-finalizer-cancel' !== (string) ( $data['ability_name'] ?? '' ) ) {
				continue;
			}
			$found = true;
			self::assertSame( 'ok', (string) ( $data['result_status'] ?? '' ) );
			self::assertStringNotContainsString( 'Secret spec copy', (string) ( $data['sanitized_args'] ?? '' ) );
		}
		self::assertTrue( $found );
	}

	public function test_dry_run_true_returns_statuses_without_mutation(): void {
		$queued = $this->enqueue_card( 'Dry run copy' );
		self::assertIsArray( $queued );

		$result = ( new CancelFinalizerChanges() )->execute(
			[
				'change_ids' => [ (string) $queued['id'] ],
				'dry_run'    => true,
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 0, (int) $result['removed_count'] );
		self::assertSame( [ 'queued' ], $result['previous_statuses'] );
		self::assertSame( 'planned', $result['verification_status'] );
		self::assertArrayNotHasKey( 'block_spec', $result );
		self::assertStringNotContainsString( 'Dry run copy', (string) wp_json_encode( $result ) );
		self::assertSame( 'queued', BlockQueue::get( (string) $queued['id'] )['status'] ?? '' );
	}

	public function test_change_ids_are_normalized_and_invalid_inputs_fail_closed(): void {
		$GLOBALS['stonewright_test_posts'][43] = (object) [
			'ID'           => 43,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Finalizer target two',
			'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
		$first = $this->enqueue_card( 'Normalize One' );
		self::assertIsArray( $first );
		$second = BlockQueue::enqueue(
			[
				'post_id'               => 43,
				'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][43]->post_content ),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Normalize Two' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $second );

		$ability = new CancelFinalizerChanges();

		$blank = $ability->execute( [ 'change_ids' => [ '   ' ] ] );
		self::assertInstanceOf( \WP_Error::class, $blank );
		self::assertSame( 'stonewright_invalid_change_ids', $blank->get_error_code() );

		$non_string = $ability->execute( [ 'change_ids' => [ 42 ] ] );
		self::assertInstanceOf( \WP_Error::class, $non_string );
		self::assertSame( 'stonewright_invalid_change_ids', $non_string->get_error_code() );

		$too_many = $ability->execute(
			[ 'change_ids' => array_map( static fn ( int $i ): string => 'missing-' . $i, range( 1, 21 ) ) ]
		);
		self::assertInstanceOf( \WP_Error::class, $too_many );
		self::assertSame( 'stonewright_finalizer_batch_limit', $too_many->get_error_code() );

		$expected = [
			(string) $first['id'],
			(string) $second['id'],
		];
		sort( $expected, SORT_STRING );

		$result = $ability->execute(
			[ 'change_ids' => [ (string) $first['id'], (string) $first['id'], (string) $second['id'] ] ]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 2, (int) $result['removed_count'] );
		self::assertSame( $expected, $result['change_ids'] );
		self::assertNull( BlockQueue::get( (string) $first['id'] ) );
		self::assertNull( BlockQueue::get( (string) $second['id'] ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enqueue_card( string $title ): array|\WP_Error {
		return BlockQueue::enqueue( $this->card_args( $title ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card_args( string $title ): array {
		return [
			'post_id'               => 42,
			'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content ),
			'block_spec'            => [
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => $title ],
				'innerBlocks' => [],
			],
		];
	}
}

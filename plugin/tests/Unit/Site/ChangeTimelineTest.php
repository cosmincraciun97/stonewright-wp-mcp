<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\ChangeLog;
use Stonewright\WpMcp\Abilities\Site\ChangeRestore;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeLog
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeRestore
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class ChangeTimelineTest extends TestCase {

	private int $post_id = 4101;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']            = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']              = [];
		$GLOBALS['stonewright_test_post_meta_calls']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts']       = [];
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_user_caps']          = [
			'manage_options' => true,
			'edit_posts'     => true,
			'edit_pages'     => true,
			'edit_post'      => true,
		];
		$GLOBALS['stonewright_test_current_user_id']    = 7;
		$GLOBALS['stonewright_test_posts'][ $this->post_id ] = (object) [
			'ID'           => $this->post_id,
			'post_type'    => 'page',
			'post_content' => 'original content',
			'post_status'  => 'publish',
			'post_title'   => 'Timeline Page',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data' => '[{"id":"a"}]',
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_registry_exposes_change_abilities(): void {
		self::assertInstanceOf( ChangeLog::class, AbilityRegistry::ability_by_name( 'stonewright/change-log' ) );
		self::assertInstanceOf( ChangeRestore::class, AbilityRegistry::ability_by_name( 'stonewright/change-restore' ) );
	}

	public function test_list_is_compact_without_payloads(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );
		self::assertNotSame( '', $snapshot_id );

		$result = ( new ChangeLog() )->execute(
			[
				'post_id' => $this->post_id,
				'limit'   => 20,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['count'] );
		self::assertCount( 1, $result['entries'] );
		$entry = $result['entries'][0];
		self::assertSame( $snapshot_id, $entry['snapshot_id'] );
		self::assertSame( $this->post_id, $entry['post_id'] );
		self::assertArrayHasKey( 'created_at', $entry );
		self::assertArrayHasKey( 'meta_keys', $entry );
		self::assertArrayNotHasKey( 'post_content', $entry );
		self::assertArrayNotHasKey( 'meta', $entry );
	}

	public function test_full_snapshot_payload_only_when_id_requested(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );

		$result = ( new ChangeLog() )->execute(
			[
				'post_id'     => $this->post_id,
				'snapshot_id' => $snapshot_id,
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'snapshot', $result );
		self::assertSame( $snapshot_id, $result['snapshot']['snapshot_id'] );
		self::assertArrayHasKey( 'post_content', $result['snapshot'] );
		self::assertArrayHasKey( 'meta', $result['snapshot'] );
		self::assertSame( 'original content', $result['snapshot']['post_content'] );
	}

	public function test_restore_round_trip(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );

		$GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_content = 'mutated';
		$GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_title   = 'Mutated';
		update_post_meta( $this->post_id, '_elementor_data', '[{"id":"mutated"}]' );

		$result = ( new ChangeRestore() )->execute(
			[
				'post_id'     => $this->post_id,
				'snapshot_id' => $snapshot_id,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['restored'] );
		self::assertSame( 'original content', $GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_content );
		self::assertSame( 'Timeline Page', $GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_title );
		self::assertSame( '[{"id":"a"}]', get_post_meta( $this->post_id, '_elementor_data', true ) );

		// Audit write recorded.
		$audit = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $row ): bool => str_contains( (string) ( $row['table'] ?? '' ), 'stonewright_audit_log' )
				&& ( $row['data']['ability_name'] ?? '' ) === 'stonewright/change-restore'
		);
		self::assertNotEmpty( $audit );
	}

	public function test_restore_requires_confirmation_token_in_production_safe(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$denied = ( new ChangeRestore() )->execute(
			[
				'post_id'     => $this->post_id,
				'snapshot_id' => $snapshot_id,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_confirmation_required', $denied->get_error_code() );

		$token = ConfirmationToken::issue(
			'stonewright/change-restore',
			[
				'post_id'     => $this->post_id,
				'snapshot_id' => $snapshot_id,
			]
		);

		$ok = ( new ChangeRestore() )->execute(
			[
				'post_id'            => $this->post_id,
				'snapshot_id'        => $snapshot_id,
				'confirmation_token' => $token,
			]
		);
		self::assertIsArray( $ok );
		self::assertTrue( $ok['restored'] );
	}

	public function test_restore_snapshot_alias_matches_restore(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );
		$GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_content = 'changed';
		self::assertTrue( Backup::restore_snapshot( $this->post_id, $snapshot_id ) );
		self::assertSame( 'original content', $GLOBALS['stonewright_test_posts'][ $this->post_id ]->post_content );
	}

	public function test_restore_permission_denied_without_edit_cap(): void {
		$snapshot_id = Backup::snapshot_post( $this->post_id );
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];

		$ability = new ChangeRestore();
		$perm    = $ability->permission_callback(
			[
				'post_id'     => $this->post_id,
				'snapshot_id' => $snapshot_id,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $perm );
	}
}

<?php
/**
 * Adapters from a write's own result to change-set inputs.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetSources;

require_once __DIR__ . '/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Security\ChangeSetSources
 */
final class ChangeSetSourcesTest extends TestCase {
	use ChangeSetAssertions;

	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']          = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Plan target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode( self::tree() ),
					'_elementor_edit_mode' => 'builder',
				],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	/** @return list<array<string, mixed>> */
	private static function tree(): array {
		return [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[ 'id' => 'hero', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Hi' ], 'elements' => [] ],
				],
			],
		];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function store( array $tree ): void {
		$GLOBALS['stonewright_test_posts'][601]->meta['_elementor_data'] = wp_json_encode( $tree );
	}

	public function test_the_readback_is_compared_with_the_snapshot_taken_before_the_write(): void {
		$snapshot = Backup::snapshot_post( 601 );
		self::assertNotSame( '', $snapshot );

		$after                                  = self::tree();
		$after[0]['settings']['container_type'] = 'grid';
		$after[0]['elements'][]                 = [ 'id' => 'planned', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ];
		$after[0]['elements'][]                 = [ 'id' => 'stray', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ];
		self::store( $after );

		$unexpected = ChangeSetSources::elements_outside_plan( 601, $snapshot, [ 'planned' ] );

		self::assertEqualsCanonicalizing(
			[
				ChangeSet::entry( 'element', 'root', 'update' ),
				ChangeSet::entry( 'element', 'stray', 'add' ),
			],
			$unexpected
		);
		self::assertSame( [], ChangeSetSources::elements_outside_plan( 601, $snapshot, [ 'planned', 'stray', 'root' ] ) );
	}

	public function test_an_unreadable_snapshot_or_post_reports_nothing_unexpected(): void {
		self::assertSame( [], ChangeSetSources::elements_outside_plan( 601, '', [ 'hero' ] ) );
		self::assertSame( [], ChangeSetSources::elements_outside_plan( 601, 'snap_missing', [ 'hero' ] ) );
		self::assertSame( [], ChangeSetSources::elements_outside_plan( 0, 'snap_x', [ 'hero' ] ) );
	}

	/** @return array<string, array{0: string, 1: bool, 2: string, 3: bool, 4: bool, 5: string}> */
	public static function outcomes(): array {
		return [
			'verified write'         => [ 'ok', false, 'verified', false, false, 'verified' ],
			'passed verification'    => [ 'ok', false, 'passed', false, false, 'verified' ],
			'dry run'                => [ 'ok', true, 'planned', false, false, 'dry_run' ],
			'queued for finalizer'   => [ 'ok', false, 'queued', false, false, 'queued' ],
			'queued flag'            => [ 'ok', false, '', true, false, 'queued' ],
			'unchanged word'         => [ 'ok', false, 'unchanged', false, false, 'unchanged' ],
			'unchanged flag'         => [ 'ok', true, 'planned', false, true, 'unchanged' ],
			'no verification word'   => [ 'ok', false, '', false, false, 'pending' ],
			'error'                  => [ 'error', false, 'failed', false, false, 'failed' ],
			'error during dry run'   => [ 'error', true, '', false, false, 'failed' ],
			'gate stopped the write' => [ 'blocked', false, '', false, false, 'not_applied' ],
		];
	}

	/** @dataProvider outcomes */
	public function test_calls_are_classified_by_status_and_what_they_reported( string $status, bool $dry_run, string $word, bool $queued, bool $unchanged, string $expected ): void {
		self::assertSame( $expected, ChangeSetSources::outcome( $status, $dry_run, $word, $queued, $unchanged ) );
	}

	public function test_only_a_verified_outcome_is_verified_and_only_a_failure_is_failed(): void {
		self::assertSame( 'verified', ChangeSetSources::verification_status( 'verified' ) );
		self::assertSame( 'failed', ChangeSetSources::verification_status( 'failed' ) );
		foreach ( [ 'dry_run', 'unchanged', 'queued', 'pending', 'not_applied' ] as $outcome ) {
			self::assertSame( 'unverified', ChangeSetSources::verification_status( $outcome ), $outcome );
		}
	}

	public function test_planned_changes_are_split_by_outcome(): void {
		$change = [ ChangeSet::entry( 'file', 'style.css', 'append' ) ];

		self::assertSame( [ 'applied' => $change, 'missing' => [] ], ChangeSetSources::lists( 'verified', $change ) );
		self::assertSame( [ 'applied' => [], 'missing' => $change ], ChangeSetSources::lists( 'failed', $change ) );
		self::assertSame( [ 'applied' => [], 'missing' => $change ], ChangeSetSources::lists( 'not_applied', $change ) );
		foreach ( [ 'dry_run', 'unchanged', 'queued', 'pending' ] as $outcome ) {
			self::assertSame( [ 'applied' => [], 'missing' => [] ], ChangeSetSources::lists( $outcome, $change ), $outcome );
		}
	}

	public function test_the_approval_a_write_ran_under_is_named(): void {
		self::assertNull( ChangeSetSources::approval_reason( [ 'custom_code_grant' => 'x' ], false ) );
		self::assertSame( 'mode_policy', ChangeSetSources::approval_reason( [], true ) );
		self::assertSame( 'custom_code_grant', ChangeSetSources::approval_reason( [ 'custom_code_grant' => 'grant-value', 'confirmation_token' => 'token-value' ], true ) );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		self::assertSame( 'confirmation_token', ChangeSetSources::approval_reason( [ 'confirmation_token' => 'token-value' ], true ) );
		self::assertSame( 'mode_policy', ChangeSetSources::approval_reason( [], true ) );
	}

	public function test_a_receipt_with_a_failed_rollback_keeps_the_recipe_available(): void {
		$receipt = [
			'change_set_id'       => 'cs-rollback-failed',
			'post_id'             => 601,
			'snapshot_id'         => 'snap_1',
			'before_hash'         => self::HASH_A,
			'planned_hash'        => self::HASH_B,
			'readback_hash'       => self::HASH_A,
			'verification_status' => 'failed',
			'rollback_status'     => 'failed',
		];
		$error  = new \WP_Error( 'stonewright_readback_mismatch', 'Mismatch.', [ 'write_receipt' => $receipt ] );
		$entry  = ChangeSet::entry( 'element', 'hero', 'update_element', 0 );

		$change_set = ChangeSet::build( ChangeSetSources::receipt( [ 'post_id' => 601 ], $error, 'error', [ $entry ], [ $entry ] ) );

		self::assertSame( 'cs-rollback-failed', $change_set['change_set_id'] );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertTrue( $change_set['rollback_available'], 'A failed rollback needs the recipe.' );
		self::assertSame( 'post_snapshot', $change_set['rollback_recipe_ref']['kind'] );
		self::assertSame( [ $entry ], $change_set['missing'] );
		self::assertSame( self::HASH_A, $change_set['after_hash'], 'The observed readback, before any rollback.' );
		self::assertSame( self::HASH_B, $change_set['verification']['evidence']['expected_hash'] );
		self::assertValidChangeSet( $change_set );
	}

	public function test_a_receipt_that_rolled_back_offers_no_recipe(): void {
		$receipt = [
			'change_set_id'       => 'cs-rolled-back',
			'post_id'             => 601,
			'snapshot_id'         => 'snap_1',
			'before_hash'         => self::HASH_A,
			'planned_hash'        => self::HASH_B,
			'readback_hash'       => self::HASH_A,
			'verification_status' => 'failed',
			'rollback_status'     => 'succeeded',
		];
		$error = new \WP_Error( 'stonewright_readback_mismatch', 'Mismatch.', [ 'write_receipt' => $receipt ] );

		$change_set = ChangeSet::build( ChangeSetSources::receipt( [ 'post_id' => 601 ], $error, 'error', [], [] ) );

		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['rollback_recipe_ref'] );
		self::assertSame( 'succeeded', $change_set['verification']['evidence']['rollback_status'] );
		self::assertSame( 'mode_policy', $change_set['approval_reason'], 'The write ran before it was rolled back.' );
	}

	public function test_a_file_write_reads_hashes_backup_and_smoke_from_its_result(): void {
		$entry  = ChangeSet::entry( 'file', 'style.css', 'append' );
		$result = [
			'ok'                  => true,
			'changed'             => true,
			'before_sha256'       => self::HASH_A,
			'after_sha256'        => self::HASH_B,
			'backup_ref'          => 'sw-theme-backup-1',
			'execution_status'    => 'ok',
			'verification_status' => 'verified',
			'rollback_status'     => 'not_needed',
			'smoke_summary'       => [ 'status' => 'passed' ],
			'changed_bytes'       => 12,
		];

		$change_set = ChangeSet::build( ChangeSetSources::file( [ 'path' => 'style.css' ], $result, 'ok', $entry, [ 'recipe_kind' => 'theme_backup', 'recipe_target' => 'style.css' ] ) );

		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( [ $entry ], $change_set['applied'] );
		self::assertSame( self::HASH_A, $change_set['before_hash'] );
		self::assertSame( self::HASH_B, $change_set['after_hash'] );
		self::assertSame( [ 'kind' => 'theme_backup', 'ref' => 'sw-theme-backup-1', 'target' => 'style.css' ], $change_set['rollback_recipe_ref'] );
		self::assertSame( 'passed', $change_set['verification']['evidence']['smoke'] );
		self::assertSame( 12, $change_set['verification']['evidence']['changed_bytes'] );
		self::assertValidChangeSet( $change_set );

		$replay = ChangeSet::build( ChangeSetSources::file( [ 'path' => 'style.css' ], $result, 'ok', $entry ) );
		self::assertSame( $change_set['change_set_id'], $replay['change_set_id'], 'The same write gets the same id.' );
		self::assertFalse( $replay['rollback_available'], 'Without a recipe kind there is no recipe.' );
	}

	public function test_a_file_write_whose_smoke_failed_and_rolled_back_misses_its_change(): void {
		$entry = ChangeSet::entry( 'file', 'functions.php', 'append' );
		$error = new \WP_Error(
			'stonewright_theme_write_smoke_failed',
			'Smoke failed.',
			[
				'execution_status'    => 'ok',
				'verification_status' => 'failed',
				'rollback_status'     => 'succeeded',
				'before_sha256'       => self::HASH_A,
				'after_sha256'        => self::HASH_B,
				'backup_ref'          => 'sw-theme-backup-2',
				'smoke_summary'       => [ 'status' => 'failed' ],
			]
		);

		$change_set = ChangeSet::build( ChangeSetSources::file( [ 'path' => 'functions.php' ], $error, 'error', $entry, [ 'recipe_kind' => 'theme_backup' ] ) );

		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [ $entry ], $change_set['missing'] );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( self::HASH_B, $change_set['after_hash'], 'The write ran: the candidate was read back before the smoke failed.' );
		self::assertFalse( $change_set['rollback_available'], 'The original bytes are already restored.' );
		self::assertSame( 'failed', $change_set['verification']['evidence']['smoke'] );
		self::assertValidChangeSet( $change_set );
	}

	public function test_a_file_write_stopped_before_it_ran_has_no_after_state(): void {
		$entry = ChangeSet::entry( 'file', 'style.css', 'replace_all' );
		$error = new \WP_Error(
			'stonewright_custom_code_grant_required',
			'Grant required.',
			[ 'execution_status' => 'blocked', 'verification_status' => 'blocked', 'before_sha256' => self::HASH_A, 'after_sha256' => self::HASH_B ]
		);

		$change_set = ChangeSet::build( ChangeSetSources::file( [ 'path' => 'style.css' ], $error, 'blocked', $entry, [ 'recipe_kind' => 'theme_backup' ] ) );

		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( [ $entry ], $change_set['missing'] );
		self::assertSame( '', $change_set['after_hash'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertValidChangeSet( $change_set );
	}

	public function test_option_hashes_describe_the_snapshot_and_the_live_values(): void {
		$GLOBALS['stonewright_test_options']['sw_probe_color'] = 'red';
		$restore = Backup::snapshot_options( [ 'sw_probe_color', 'sw_probe_absent' ] );
		$same    = ChangeSetSources::option_hashes( $restore );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $same['before'] );
		self::assertSame( $same['before'], $same['after'], 'Nothing changed yet.' );

		$GLOBALS['stonewright_test_options']['sw_probe_color'] = 'blue';
		$GLOBALS['stonewright_test_options']['sw_probe_absent'] = 'now set';
		$changed = ChangeSetSources::option_hashes( $restore );
		self::assertSame( $same['before'], $changed['before'] );
		self::assertNotSame( $changed['before'], $changed['after'] );

		self::assertSame( [ 'before' => '', 'after' => '' ], ChangeSetSources::option_hashes( 'snap_unknown' ) );
	}
}

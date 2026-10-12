<?php
/**
 * A manual restore never overwrites a newer edit unless the person confirmed the state they saw: the same rule on the
 * Changes path, the Rescue path and the rescue-rollback ability, with the emergency recovery of a failing site kept.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Abilities\Security\RescueRollback as RescueRollbackAbility;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\Rollback\PostRollbackFamily;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\CliExit;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\RecordingRescueCommand;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\PostLedgerWpdb;

/**
 * The chain of the audit: the page is A, a change turned it into B (still armed, never verified, or recorded as
 * an incident that is long past), and a later, healthy edit turned it into C. Rolling B back writes A over C.
 *
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 */
final class ManualRestoreSafetyTest extends RollbackTestCase {

	private const ENTRY_POINTS = [ 'changes', 'rescue', 'ability' ];

	protected function setUp(): void {
		parent::setUp();
		ChangeJournal::reset_for_tests();
		$this->site( fn (): string => str_contains( $this->post_field( 'post_content' ), 'FATAL' ) ? 'broken' : 'healthy' );
	}

	protected function tearDown(): void {
		RescueRollback::set_selection_runner( null );
		parent::tearDown();
	}

	// -- Fixtures -----------------------------------------------------------------------------------

	/**
	 * A is "A body", B is "B body". The change is in the ledger and in the journal under one id.
	 *
	 * @return string The change id.
	 */
	private function chain( string $state = 'armed' ): string {
		$this->make_post( self::POST, [ 'post_title' => 'Fixture page', 'post_content' => 'A body' ] );
		$id       = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$snapshot = Backup::snapshot_post( self::POST, [], false );
		self::assertNotSame( '', $snapshot );
		ChangeJournal::arm(
			[
				'change_set_id' => $id,
				'ability'       => 'stonewright/content-update-page',
				'resource_type' => 'post',
				'resource_key'  => (string) self::POST,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => self::POST, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		$this->edit_post( 'post_content', 'B body' );
		PostAdapter::settle( $id, PostAdapter::capture_after( self::POST ), 'armed' );
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $id, $state );
		}
		return $id;
	}

	/** A later edit that is not a Stonewright change at all: no ledger row, no journal entry. */
	private function external_edit( string $body = 'C body' ): void {
		$this->edit_post( 'post_content', $body );
	}

	/** A later Stonewright change to the same page, recorded in the ledger. */
	private function newer_change( string $body = 'C body' ): string {
		return $this->post_change( fn () => $this->edit_post( 'post_content', $body ) );
	}

	/**
	 * @param array<string, mixed> $extra force_drift, expected_current_sha256, dry_run, confirmation_token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function via( string $entry_point, string $id, array $extra = [] ): array|\WP_Error {
		return match ( $entry_point ) {
			'changes' => ChangeRollback::run( $id, $extra + [ 'by' => 'admin-page', 'actor' => 7, 'human_approved' => true ] ),
			'rescue'  => RescueRollback::run( $id, $extra + [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] ),
			'ability' => ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id ] + array_intersect_key( $extra, array_flip( [ 'force_drift', 'expected_current_sha256', 'confirmation_token', 'dry_run' ] ) ) ),
			default   => throw new \InvalidArgumentException( $entry_point ),
		};
	}

	/** @return iterable<string, array{string}> */
	public static function entry_points(): iterable {
		foreach ( self::ENTRY_POINTS as $point ) {
			yield $point => [ $point ];
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function states_and_entry_points(): iterable {
		foreach ( [ 'armed', 'verified', 'rollback_failed' ] as $state ) {
			foreach ( self::ENTRY_POINTS as $point ) {
				yield $state . ' via ' . $point => [ $state, $point ];
			}
		}
	}

	/** @return array<string, mixed> Everything a refused restore must leave as it was. */
	private function world( string $id ): array {
		return [
			'content'   => $this->post_field( 'post_content' ),
			'ledger'    => ChangeLedger::count(),
			'journal'   => ChangeJournal::get( $id )['state'] ?? '',
			'claim'     => ChangeJournal::get( $id )['claim_at'] ?? 0,
			'snapshots' => array_keys( Backup::list_snapshots( self::POST ) ),
			'row'       => $this->row( $id )['status'],
		];
	}

	private function current_hash( string $id ): string {
		$plan = RescueRollback::run( $id, [ 'dry_run' => true, 'user_id' => 7 ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 64, strlen( (string) $plan['current_sha256'] ) );
		return (string) $plan['current_sha256'];
	}

	// -- F1: the default restore refuses and changes nothing ------------------------------------------

	/** @dataProvider states_and_entry_points */
	public function test_a_default_restore_over_a_later_edit_is_refused_with_the_drift_error_and_changes_nothing( string $state, string $entry_point ): void {
		$id = $this->chain( $state );
		$this->external_edit();
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code(), $error->get_error_message() );
		self::assertTrue( $error->get_error_data()['drift'] );
		self::assertSame( 409, $error->get_error_data()['status'] );
		self::assertSame( 'C body', $this->post_field( 'post_content' ), 'The later edit is still there.' );
		self::assertSame( $before, $this->world( $id ), 'Nothing was written, saved, claimed or settled.' );
	}

	/** @dataProvider entry_points */
	public function test_a_later_change_recorded_in_the_ledger_is_protected_the_same_way( string $entry_point ): void {
		$id = $this->chain( 'armed' );
		$this->newer_change();
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** @dataProvider entry_points */
	public function test_a_verified_change_is_protected_the_same_way_as_the_control( string $entry_point ): void {
		$id = $this->chain( 'verified' );
		$this->external_edit();

		$error = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );
	}

	public function test_a_historic_incident_after_a_healthy_edit_does_not_bypass_the_drift_check(): void {
		$id = $this->chain( 'verified' );
		$this->mark_incident( $id );
		$this->external_edit();
		$before = $this->world( $id );

		foreach ( self::ENTRY_POINTS as $entry_point ) {
			$error = $this->via( $entry_point, $id );
			self::assertInstanceOf( \WP_Error::class, $error, $entry_point );
			self::assertSame( 'stonewright_change_drift', $error->get_error_code(), $entry_point );
		}
		self::assertSame( $before, $this->world( $id ) );
	}

	/** Mark a journal entry as an incident the way a recorded fatal does. */
	private function mark_incident( string $id ): void {
		$store                                 = get_option( ChangeJournal::DB_OPTION, [] );
		$store['entries'][ $id ]['state']      = 'incident';
		$store['entries'][ $id ]['incident']   = [ 'recorded_at' => time(), 'file' => 'wp-content/plugins/demo/demo.php', 'line' => 3, 'type' => 1, 'message_sha256' => str_repeat( 'a', 64 ), 'source' => 'shutdown' ];
		update_option( ChangeJournal::DB_OPTION, $store, false );
		ChangeJournal::reset_for_tests();
		self::assertSame( 'incident', ChangeJournal::get( $id )['state'] );
	}

	public function test_the_plan_says_the_restore_needs_a_confirmation_and_names_the_state_to_confirm(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();

		$rescue  = RescueRollback::run( $id, [ 'dry_run' => true, 'user_id' => 7 ] );
		$changes = ChangeRollback::plan( $id );

		foreach ( [ $rescue, $changes ] as $plan ) {
			self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
			self::assertTrue( $plan['drift'] );
			self::assertTrue( $plan['requires_force'] );
			self::assertSame( 64, strlen( $plan['current_sha256'] ) );
			self::assertNotSame( $plan['expected_sha256'], $plan['current_sha256'] );
		}
		self::assertSame( $changes['current_sha256'], $rescue['current_sha256'], 'Both screens name the same state.' );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );
	}

	public function test_the_plan_of_an_untouched_change_needs_no_confirmation(): void {
		$id = $this->chain( 'armed' );

		$plan = RescueRollback::run( $id, [ 'dry_run' => true, 'user_id' => 7 ] );

		self::assertIsArray( $plan );
		self::assertFalse( $plan['drift'] );
		self::assertFalse( $plan['requires_force'] );
		self::assertTrue( $plan['drift_known'] );
	}

	/** The check the Rescue page and the ability run before they look at a confirmation token. */
	public function test_the_preflight_refuses_a_drifted_restore_and_lets_a_confirmed_one_through(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();

		$refusal = RescueRollback::preflight_refusal( $id, [ 'user_id' => 7 ] );
		self::assertInstanceOf( \WP_Error::class, $refusal );
		self::assertSame( 'stonewright_change_drift', $refusal->get_error_code() );

		$hash = $this->current_hash( $id );
		self::assertNull( RescueRollback::preflight_refusal( $id, [ 'user_id' => 7, 'force_drift' => true, 'expected_current_sha256' => $hash ] ) );
		self::assertSame( 'stonewright_change_changed_since_preview', RescueRollback::preflight_refusal( $id, [ 'user_id' => 7, 'force_drift' => true, 'expected_current_sha256' => str_repeat( '0', 64 ) ] )->get_error_code() );
	}

	// -- An override needs the state it was given for ----------------------------------------------------

	/** @dataProvider states_and_entry_points */
	public function test_a_drift_override_without_the_hash_is_refused_and_changes_nothing( string $state, string $entry_point ): void {
		$id = $this->chain( $state );
		$this->external_edit();
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id, [ 'force_drift' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift_hash_required', $error->get_error_code(), $error->get_error_message() );
		self::assertSame( 409, $error->get_error_data()['status'] );
		self::assertSame( 64, strlen( (string) $error->get_error_data()['current_sha256'] ), 'The refusal names the state to confirm.' );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** @dataProvider states_and_entry_points */
	public function test_a_drift_override_with_a_hash_that_no_longer_matches_is_refused_and_changes_nothing( string $state, string $entry_point ): void {
		$id = $this->chain( $state );
		$this->external_edit();
		$hash = $this->current_hash( $id );
		$this->external_edit( 'D body' );
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id, [ 'force_drift' => true, 'expected_current_sha256' => $hash ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_changed_since_preview', $error->get_error_code() );
		self::assertSame( 'D body', $this->post_field( 'post_content' ) );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** @dataProvider entry_points */
	public function test_a_drift_override_accepts_the_start_of_the_hash_the_pages_print( string $entry_point ): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$hash = substr( $this->current_hash( $id ), 0, 32 );

		$result = $this->via( $entry_point, $id, [ 'force_drift' => true, 'expected_current_sha256' => $hash ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	/** @dataProvider entry_points */
	public function test_force_drift_without_a_hash_is_ignored_when_nothing_drifted( string $entry_point ): void {
		$id = $this->chain( 'verified' );

		$result = $this->via( $entry_point, $id, [ 'force_drift' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	public function test_a_failing_site_does_not_make_an_override_without_the_hash_acceptable(): void {
		$id = $this->chain( 'rollback_failed' );
		$this->external_edit( 'C body FATAL' );
		$before = $this->world( $id );

		$error = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true, 'force_drift' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift_hash_required', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
	}

	public function test_the_refusal_for_a_missing_hash_does_not_spend_the_confirmation_token(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$this->production_safe();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id, 'force_drift' => true ] );

		$error = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id, 'force_drift' => true, 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift_hash_required', $error->get_error_code() );
		// The same token still works for the same call once the edit is gone.
		$this->edit_post( 'post_content', 'B body' );
		$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id, 'force_drift' => true, 'confirmation_token' => $token ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_the_changes_path_refuses_a_missing_hash_before_the_token_is_looked_at(): void {
		$id = $this->chain( 'verified' );
		$this->external_edit();
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'force_drift' => true ] ) );

		$error = ChangeRollback::run( $id, [ 'force_drift' => true, 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift_hash_required', $error->get_error_code() );
		$this->edit_post( 'post_content', 'B body' );
		$result = ChangeRollback::run( $id, [ 'force_drift' => true, 'confirmation_token' => $token ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	// -- The command line ---------------------------------------------------------------------------------

	/** @param array<string, mixed> $assoc */
	private function cli( string $id, array $assoc = [] ): ?CliExit {
		try {
			( new RecordingRescueCommand() )->rollback( [ $id ], $assoc );
		} catch ( CliExit $exit ) {
			return $exit;
		}
		return null;
	}

	public function test_wp_cli_rescue_rollback_confirms_an_override_with_the_flags_and_refuses_it_without(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();

		$drift = $this->cli( $id );
		self::assertNotNull( $drift );
		self::assertStringContainsString( 'stonewright_change_drift', $drift->getMessage() );
		self::assertSame( 1, preg_match( '/--expected-sha256=([a-f0-9]{64})/', $drift->getMessage(), $found ), 'The message names the state to confirm.' );

		$bare = $this->cli( $id, [ 'force-drift' => true ] );
		self::assertNotNull( $bare );
		self::assertStringContainsString( 'stonewright_change_drift_hash_required', $bare->getMessage() );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );

		$stale = $this->cli( $id, [ 'force-drift' => true, 'expected-sha256' => str_repeat( '0', 64 ) ] );
		self::assertNotNull( $stale );
		self::assertStringContainsString( 'stonewright_change_changed_since_preview', $stale->getMessage() );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );

		self::assertNull( $this->cli( $id, [ 'force-drift' => true, 'expected-sha256' => $found[1] ] ) );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertCount( 1, ChangeLedger::children( $id ) );
	}

	public function test_wp_cli_rescue_rollback_cannot_approve_code(): void {
		$path = $this->theme . '/functions.php';
		file_put_contents( $path, "<?php\n// older\n" );
		$write = \Stonewright\WpMcp\Security\ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'functions.php', 'before' => "<?php\n// older\n", 'after' => "<?php\n// newer\n", 'language' => 'php', 'skip_smoke' => true ] );
		self::assertIsArray( $write );
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/theme-file-patch', 'resource_type' => 'theme_file', 'resource_key' => 'functions.php', 'recipe' => [ 'type' => 'theme_backup', 'ref' => (string) $write['backup_ref'] ], 'recipe_detail' => [ 'absolute' => $path ], 'scope' => 'site' ] );
		self::assertIsArray( $entry );
		ChangeJournal::settle( $entry['id'], 'verified' );

		$exit = $this->cli( $entry['id'], [ 'force-drift' => true, 'expected-sha256' => str_repeat( 'a', 64 ) ] );

		self::assertNotNull( $exit );
		self::assertStringContainsString( 'stonewright_rescue_approval_required', $exit->getMessage() );
		self::assertSame( "<?php\n// newer\n", file_get_contents( $path ), 'The file was not touched.' );
	}

	// -- The confirmed restore -----------------------------------------------------------------------

	/** @dataProvider entry_points */
	public function test_a_confirmed_restore_saves_the_current_state_first_and_can_be_redone( string $entry_point ): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$hash      = $this->current_hash( $id );
		$snapshots = count( Backup::list_snapshots( self::POST ) );

		$result = $this->via( $entry_point, $id, [ 'force_drift' => true, 'expected_current_sha256' => $hash ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertSame( $snapshots + 1, count( Backup::list_snapshots( self::POST ) ), 'The state that was replaced was saved before the write.' );
		$saved = array_filter( Backup::list_snapshots( self::POST ), static fn ( array $snapshot ): bool => 'C body' === $snapshot['post_content'] );
		self::assertCount( 1, $saved );

		$children = ChangeLedger::children( $id );
		self::assertCount( 1, $children, 'One rollback row under the change, whichever screen ran it.' );
		self::assertSame( 'rollback', $children[0]['kind'] );
		self::assertSame( 'verified', $children[0]['status'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
		self::assertSame( 'C body', ChangeLedger::read_image( $children[0]['change_id'], 'before' )['post']['post_content'], 'Its before image is the later edit.' );
		if ( 'ability' !== $entry_point ) {
			self::assertSame( $children[0]['change_id'], $result['rollback_change_id'] );
			self::assertTrue( $result['restorable_again'] );
		}

		$redo = ChangeRollback::run( $children[0]['change_id'], [ 'by' => 'admin-page', 'actor' => 7 ] );
		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( 'redo', $redo['kind'] );
		self::assertSame( 'C body', $this->post_field( 'post_content' ), 'The Redo brings the later edit back.' );
	}

	/** @dataProvider entry_points */
	public function test_a_confirmation_for_another_state_is_refused_and_nothing_is_written( string $entry_point ): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$hash = $this->current_hash( $id );
		$this->edit_post( 'post_title', 'Retitled after the preview' );
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id, [ 'force_drift' => true, 'expected_current_sha256' => $hash ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_changed_since_preview', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** @dataProvider entry_points */
	public function test_a_live_change_between_the_preview_and_the_submit_is_refused_even_without_drift( string $entry_point ): void {
		$id   = $this->chain( 'verified' );
		$hash = $this->current_hash( $id );
		$this->external_edit();
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id, [ 'expected_current_sha256' => $hash ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertContains( $error->get_error_code(), [ 'stonewright_change_changed_since_preview', 'stonewright_change_drift' ] );
		self::assertSame( $before, $this->world( $id ) );
	}

	// -- The control and the parity of the two screens -----------------------------------------------

	/** @dataProvider entry_points */
	public function test_a_verified_change_nobody_touched_is_restored_without_confirmation( string $entry_point ): void {
		$id = $this->chain( 'verified' );

		$result = $this->via( $entry_point, $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertCount( 1, ChangeLedger::children( $id ) );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
	}

	public function test_rescue_and_changes_write_the_same_rows_and_the_same_redo(): void {
		$shapes = [];
		foreach ( [ 'rescue', 'changes' ] as $entry_point ) {
			$this->tearDown();
			$this->setUp();
			$id = $this->chain( 'verified' );

			$result = $this->via( $entry_point, $id );

			self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
			$child = $this->row( $result['rollback_change_id'] );
			$shapes[ $entry_point ] = [
				'rows'        => ChangeLedger::count(),
				'kind'        => $child['kind'],
				'status'      => $child['status'],
				'parent'      => $child['parent_id'] === $id,
				'family'      => $child['family'],
				'restorable'  => $child['restorable'],
				'before'      => ChangeLedger::read_image( $child['change_id'], 'before' )['post']['post_content'],
				'after'       => ChangeLedger::read_image( $child['change_id'], 'after' )['post']['post_content'],
				'parent_row'  => $this->row( $id )['status'],
				'journal'     => ChangeJournal::get( $id )['state'],
				'again'       => $result['restorable_again'],
				'content'     => $this->post_field( 'post_content' ),
			];
			$redo = ChangeRollback::run( $child['change_id'], [ 'by' => 'admin-page', 'actor' => 7 ] );
			self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
			$shapes[ $entry_point ]['redo'] = $this->post_field( 'post_content' );
		}

		self::assertSame( $shapes['changes'], $shapes['rescue'] );
		self::assertSame( 'B body', $shapes['rescue']['redo'] );
	}

	public function test_a_rescue_restore_writes_no_second_child_and_no_audit_row_of_the_changes_ability(): void {
		$id = $this->chain( 'verified' );

		$result = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result );
		self::assertCount( 1, ChangeLedger::children( $id ) );
		self::assertSame( [], $this->audit_rows(), 'The page records its own audit row; the engine adds none.' );
	}

	public function test_a_changes_restore_of_an_open_change_still_writes_one_child_and_one_audit_row(): void {
		$id = $this->chain( 'armed' );

		$result = ChangeRollback::run( $id, [ 'by' => 'admin-page', 'actor' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertCount( 1, ChangeLedger::children( $id ), 'The delegation to the rescue path adds no second child.' );
		self::assertCount( 1, $this->audit_rows(), 'And no second audit row.' );
		self::assertSame( 'journal', $result['path'] );
		self::assertSame( ChangeLedger::children( $id )[0]['change_id'], $result['rollback_change_id'] );
	}

	// -- Emergency recovery of a failing site keeps working -------------------------------------------

	/** @dataProvider entry_points */
	public function test_a_failing_site_is_recovered_without_confirmation_even_over_a_later_edit( string $entry_point ): void {
		$id = $this->chain( 'rollback_failed' );
		$this->external_edit( 'C body FATAL' );

		$result = $this->via( $entry_point, $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ), 'The site was failing now, so the recovery did not wait for a confirmation.' );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
		$saved = array_filter( Backup::list_snapshots( self::POST ), static fn ( array $snapshot ): bool => 'C body FATAL' === $snapshot['post_content'] );
		self::assertCount( 1, $saved, 'The failing state was saved too.' );
	}

	/** @dataProvider entry_points */
	public function test_an_incident_with_a_failing_site_and_nothing_newer_is_recovered_as_before( string $entry_point ): void {
		$this->site( fn (): string => 'B body' === $this->post_field( 'post_content' ) ? 'broken' : 'healthy' );
		$id = $this->chain( 'rollback_failed' );

		$result = $this->via( $entry_point, $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	public function test_an_armed_change_is_not_an_emergency_even_when_the_site_is_failing_for_another_reason(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit( 'C body FATAL' );
		$before = $this->world( $id );

		$error = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code(), 'Unverified is not an incident.' );
		self::assertSame( $before, $this->world( $id ) );
	}

	public function test_an_emergency_recovery_is_never_put_back(): void {
		$id = $this->chain( 'rollback_failed' );
		$this->external_edit( 'C body FATAL' );
		// The cure itself leaves the site failing: the incident stays the cure's reason, as it always did.
		$this->site( static fn (): string => 'broken' );

		$result = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertSame( 'still_failing', $result['site_status'] );
	}

	// -- The ledger is down ----------------------------------------------------------------------------

	private function ledger_down(): void {
		$down = new class() extends PostLedgerWpdb {
			public bool $down = false;

			public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
				$this->guard( (string) $query );
				return parent::get_row( $query, $output, $y );
			}

			public function get_results( $query = null, $output = 'OBJECT' ) {
				$this->guard( (string) $query );
				return parent::get_results( $query, $output );
			}

			public function insert( $table, $data, $format = null ) {
				$this->guard( (string) $table );
				return parent::insert( $table, $data, $format );
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->guard( (string) $table );
				return parent::update( $table, $data, $where, $format, $where_format );
			}

			private function guard( string $target ): void {
				if ( $this->down && str_contains( $target, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'ledger down' );
				}
			}
		};
		$down->tables = $this->db->tables;
		$down->unique = $this->db->unique;
		$down->down   = true;
		$GLOBALS['wpdb'] = $down;
	}

	/** @return iterable<string, array{string}> The screens that do not need the ledger: the Changes screen reads it first. */
	public static function rescue_entry_points(): iterable {
		yield 'rescue' => [ 'rescue' ];
		yield 'ability' => [ 'ability' ];
	}

	/** @dataProvider rescue_entry_points */
	public function test_the_emergency_recovery_of_a_failing_site_works_when_the_ledger_is_down( string $entry_point ): void {
		$this->site( fn (): string => 'B body' === $this->post_field( 'post_content' ) ? 'broken' : 'healthy' );
		$id = $this->chain( 'rollback_failed' );
		$this->ledger_down();

		$result = $this->via( $entry_point, $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
		self::assertSame( '', $result['rollback_change_id'], 'No history row exists to link, and none is invented.' );
		self::assertFalse( $result['restorable_again'], 'There is no Redo without the image of what was replaced.' );
		self::assertSame( 'ledger_unavailable', $result['history'] );
	}

	public function test_a_normal_restore_without_a_ledger_still_saves_the_current_state_and_says_there_is_no_redo(): void {
		$id = $this->chain( 'verified' );
		$this->ledger_down();
		$snapshots = count( Backup::list_snapshots( self::POST ) );

		$result = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
		self::assertSame( $snapshots + 1, count( Backup::list_snapshots( self::POST ) ), 'The state that was replaced is saved.' );
		self::assertSame( '', $result['rollback_change_id'] );
		self::assertFalse( $result['restorable_again'] );
		self::assertSame( 'ledger_unavailable', $result['history'] );
	}

	public function test_without_a_ledger_a_newer_change_of_the_journal_still_needs_a_confirmation(): void {
		$id = $this->chain( 'verified' );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => (string) self::POST, 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );
		$this->ledger_down();

		$error = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		self::assertSame( 'B body', $this->post_field( 'post_content' ) );
	}

	// -- Things that cannot be checked or restored -----------------------------------------------------

	/** A snapshot that is gone cannot be restored from: nothing is written, and the failure is the recipe's, as it always was. */
	public function test_a_restore_whose_snapshot_is_gone_writes_nothing_and_fails_as_a_rollback_that_could_not_run(): void {
		foreach ( [ 'rescue', 'changes' ] as $entry_point ) {
			$this->tearDown();
			$this->setUp();
			$id = $this->chain( 'armed' );
			unset( $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_stonewright_backups'] );

			$error = $this->via( $entry_point, $id );

			self::assertInstanceOf( \WP_Error::class, $error, $entry_point );
			self::assertSame( 'stonewright_rescue_rollback_failed', $error->get_error_code(), $entry_point );
			self::assertSame( 'B body', $this->post_field( 'post_content' ), $entry_point . ': nothing was overwritten.' );
			self::assertSame( [], ChangeLedger::children( $id ), $entry_point . ': nothing was recorded as undone.' );
			self::assertNotSame( 'rolled_back_by', $this->row( $id )['status'], $entry_point );
		}
	}

	public function test_a_later_edit_is_protected_before_a_missing_snapshot_is_even_looked_at(): void {
		$id = $this->chain( 'armed' );
		unset( $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_stonewright_backups'] );
		$this->external_edit();
		$before = $this->world( $id );

		$error = $this->via( 'rescue', $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** A post handler whose live state cannot be read. */
	private function unreadable_posts(): void {
		RollbackFamilies::register(
			new class() implements RollbackFamilyHandler {
				private PostRollbackFamily $posts;

				public function __construct() {
					$this->posts = new PostRollbackFamily();
				}

				public function families(): array {
					return $this->posts->families();
				}

				public function live_image( array $row ): string|array|\WP_Error|null {
					return new \WP_Error( 'stonewright_change_live_unreadable', 'unreadable' );
				}

				public function restore( array $row, string|array|null $image, array $options ): array {
					return $this->posts->restore( $row, $image, $options );
				}

				public function describe( array $row, string|array|null $image ): string {
					return $this->posts->describe( $row, $image );
				}

				public function records_own_row( array $row ): bool {
					return false;
				}

				public function requires_human( array $row ): bool {
					return false;
				}
			}
		);
	}

	/** @dataProvider entry_points */
	public function test_a_live_state_that_cannot_be_read_stops_a_normal_restore( string $entry_point ): void {
		$id = $this->chain( 'armed' );
		$this->unreadable_posts();
		$before = $this->world( $id );

		$error = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_live_unreadable', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
	}

	/** @dataProvider entry_points */
	public function test_a_live_state_that_cannot_be_read_does_not_stop_the_recovery_of_a_failing_site( string $entry_point ): void {
		$id = $this->chain( 'rollback_failed' );
		$this->edit_post( 'post_content', 'B body FATAL' );
		$this->unreadable_posts();

		$result = $this->via( $entry_point, $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	// -- Double submit ---------------------------------------------------------------------------------

	/** @dataProvider entry_points */
	public function test_a_second_submit_after_the_restore_writes_nothing_more( string $entry_point ): void {
		$id = $this->chain( 'verified' );
		$first = $this->via( $entry_point, $id );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );
		$after = $this->world( $id );

		$second = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( $after, $this->world( $id ) );
		self::assertCount( 1, ChangeLedger::children( $id ) );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	public function test_a_second_submit_while_the_first_runs_is_refused_and_the_restore_runs_once(): void {
		$id = $this->chain( 'armed' );
		self::assertNotNull( ChangeJournal::claim( $id, 'admin-page' ) );
		$before = $this->world( $id );

		$error = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_rescue_in_progress', $error->get_error_code() );
		self::assertSame( 'B body', $this->post_field( 'post_content' ) );
		self::assertSame( $before['snapshots'], $this->world( $id )['snapshots'] );
	}

	public function test_a_confirmed_restore_that_lands_after_another_one_is_refused_for_the_state_it_finds(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$hash = $this->current_hash( $id );
		$first = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true, 'force_drift' => true, 'expected_current_sha256' => $hash ] );
		self::assertIsArray( $first );
		$this->external_edit( 'D body' );

		$second = RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true, 'force_drift' => true, 'expected_current_sha256' => $hash ] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'D body', $this->post_field( 'post_content' ), 'The same confirmation does not apply twice.' );
	}

	// -- A restore that breaks the site is taken back ---------------------------------------------------

	/** @dataProvider entry_points */
	public function test_an_unverified_change_whose_restore_breaks_the_site_is_put_back( string $entry_point ): void {
		$this->make_post( self::POST, [ 'post_title' => 'Fixture page', 'post_content' => 'A FATAL body' ] );
		$id       = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$snapshot = Backup::snapshot_post( self::POST, [], false );
		ChangeJournal::arm( [ 'change_set_id' => $id, 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => (string) self::POST, 'recipe' => [ 'type' => 'post_snapshot', 'ref' => $snapshot ], 'recipe_detail' => [ 'post_id' => self::POST, 'snapshot_id' => $snapshot ], 'scope' => 'post' ] );
		$this->edit_post( 'post_content', 'B body' );
		PostAdapter::settle( $id, PostAdapter::capture_after( self::POST ), 'armed' );

		$error = $this->via( $entry_point, $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_rescue_undo_reverted', $error->get_error_code() );
		self::assertSame( 'B body', $this->post_field( 'post_content' ), 'The state that worked is back.' );
		self::assertCount( 0, ChangeLedger::children( $id ), 'Nothing was undone, so there is nothing to redo.' );
	}

	// -- Zero effects and no token spent before a refusal ------------------------------------------------

	public function test_a_drift_refusal_does_not_spend_the_confirmation_token_of_the_ability(): void {
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$this->production_safe();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id ] );
		$before = $this->world( $id );

		$error = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id, 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );

		// The later edit is gone again, so the same token is still good for the plain restore.
		$this->edit_post( 'post_content', 'B body' );
		$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id, 'confirmation_token' => $token ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_a_drift_refusal_does_not_spend_the_confirmation_token_of_the_changes_path_for_a_failing_site_flag(): void {
		$this->site( static fn (): string => 'healthy' );
		$id = $this->chain( 'rollback_failed' );
		$this->external_edit();
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );

		$error = ChangeRollback::run( $id, [ 'confirmation_token' => $token, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_drift', $error->get_error_code() );
		$this->edit_post( 'post_content', 'B body' );
		$result = ChangeRollback::run( $id, [ 'confirmation_token' => $token, 'human_approved' => true ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_an_access_refusal_changes_nothing_and_spends_no_token(): void {
		$id = $this->chain( 'armed' );
		$this->production_safe();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );
		$this->as_user_without_manage_options();
		$before = $this->world( $id );

		$error = ChangeRollback::run( $id, [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_forbidden', $error->get_error_code() );
		self::assertSame( $before, $this->world( $id ) );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => true;
		$result = ChangeRollback::run( $id, [ 'confirmation_token' => $token ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
	}

	public function test_a_refused_restore_takes_no_probe_for_a_change_that_is_not_an_incident(): void {
		$requests = 0;
		$this->site(
			function () use ( &$requests ): string {
				++$requests;
				return 'healthy';
			}
		);
		$id = $this->chain( 'armed' );
		$this->external_edit();
		$requests = 0;

		RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertSame( 0, $requests, 'The refusal came before any request to the site.' );
	}
}

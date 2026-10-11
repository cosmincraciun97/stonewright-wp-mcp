<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use Stonewright\WpMcp\Cli\ChangesCommand;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\Abilities\Security\ChangeHistoryTestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\CliExit;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\RecordingChangesCommand;

/**
 * wp stonewright changes list|diff|rollback: what it asks of the caller, what it hands to the rollback engine, and how it ends.
 *
 * @covers \Stonewright\WpMcp\Cli\ChangesCommand
 * @covers \Stonewright\WpMcp\Support\Diff\DiffText
 */
final class ChangesCommandTest extends ChangeHistoryTestCase {

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $runs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->runs                     = [];
		ChangesCommand::$rollback_runner = null;
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN' );
	}

	protected function tearDown(): void {
		ChangesCommand::$rollback_runner = null;
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN' );
		parent::tearDown();
	}

	/** Record what the command hands to the engine, and answer as a successful rollback. */
	private function spy_on_the_engine( mixed $answer = null ): void {
		ChangesCommand::$rollback_runner = function ( string $id, array $options ) use ( $answer ): mixed {
			$this->runs[] = [ $id, $options ];
			return $answer ?? [ 'ok' => true, 'change_id' => $id, 'kind' => 'rollback', 'rollback_status' => 'succeeded', 'rollback_change_id' => 'cs-' . str_repeat( 'c', 24 ), 'site_status' => 'healthy', 'state' => 'verified' ];
		};
	}

	/**
	 * @param array<string, mixed> $assoc
	 */
	private function run_list( array $assoc = [] ): RecordingChangesCommand {
		$command = new RecordingChangesCommand();
		$command->list_( [], $assoc );
		return $command;
	}

	/**
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc
	 */
	private function run_diff( array $args, array $assoc = [] ): RecordingChangesCommand {
		$command = new RecordingChangesCommand();
		$command->diff( $args, $assoc );
		return $command;
	}

	/**
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc
	 */
	private function run_rollback( array $args, array $assoc = [] ): RecordingChangesCommand {
		$command = new RecordingChangesCommand();
		$command->rollback( $args, $assoc );
		return $command;
	}

	private function failure( callable $run ): CliExit {
		try {
			$run();
		} catch ( CliExit $exit ) {
			return $exit;
		}
		self::fail( 'The command was expected to fail.' );
	}

	private function production_safe_mode(): void {
		$this->production_safe();
	}

	// -----------------------------------------------------------------------
	// Who may run it.
	// -----------------------------------------------------------------------

	public function test_every_subcommand_needs_an_administrator(): void {
		$id   = $this->page_change( 31, 'Original body', 'Changed body' );
		$runs = [ fn () => $this->run_list(), fn () => $this->run_diff( [ $id ] ), fn () => $this->run_rollback( [ $id ], [ 'yes' => true ] ) ];

		$GLOBALS['stonewright_test_current_user_id'] = 0;
		foreach ( $runs as $run ) {
			self::assertStringContainsString( '--user', $this->failure( $run )->getMessage() );
		}

		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$this->as_user_without_manage_options();
		foreach ( $runs as $run ) {
			self::assertStringContainsString( 'manage options', $this->failure( $run )->getMessage() );
		}
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	// -----------------------------------------------------------------------
	// list.
	// -----------------------------------------------------------------------

	public function test_list_prints_one_short_row_per_change(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body', 'Home page' );

		$command = $this->run_list();

		self::assertCount( 1, $command->tables );
		self::assertSame( 'table', $command->tables[0]['format'] );
		self::assertSame( [ 'change_id', 'time', 'kind', 'family', 'resource', 'ability', 'status', 'restorable', 'summary' ], $command->tables[0]['fields'] );
		$row = $command->tables[0]['rows'][0];
		self::assertSame( [ $id, 'change', 'post', 'Home page', 'stonewright/content-update-page', 'verified', 'yes' ], [ $row['change_id'], $row['kind'], $row['family'], $row['resource'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringNotContainsString( 'Original body', $command->printed() );
	}

	public function test_list_says_so_when_there_is_nothing_to_list(): void {
		$command = $this->run_list();

		self::assertSame( [], $command->tables );
		self::assertSame( [ 'No changes match.' ], $command->lines );
	}

	public function test_list_applies_the_filters_and_the_format(): void {
		$this->page_change( 31, 'a', 'b' );
		$this->theme_change();

		$only_code = $this->run_list( [ 'family' => 'theme_file', 'format' => 'json', 'restorable' => true, 'status' => 'verified', 'kind' => 'change', 'actor' => '7', 'per-page' => '5', 'page' => '1' ] );

		self::assertSame( 'json', $only_code->tables[0]['format'] );
		self::assertSame( [ 'theme_file' ], array_column( $only_code->tables[0]['rows'], 'family' ) );
		self::assertSame( [], $this->run_list( [ 'restorable' => 'false' ] )->tables );
	}

	public function test_list_refuses_a_value_that_is_not_allowed_and_a_format_that_does_not_exist(): void {
		$exit = $this->failure( fn () => $this->run_list( [ 'family' => 'nonsense' ] ) );
		self::assertStringContainsString( 'family', $exit->getMessage() );
		self::assertSame( 1, $exit->getCode() );

		$exit = $this->failure( fn () => $this->run_list( [ 'format' => 'xml' ] ) );
		self::assertStringContainsString( 'format', $exit->getMessage() );
		self::assertStringContainsString( 'per_page', $this->failure( fn () => $this->run_list( [ 'per-page' => '500' ] ) )->getMessage() );
	}

	// -----------------------------------------------------------------------
	// diff.
	// -----------------------------------------------------------------------

	public function test_diff_prints_the_change_its_lines_and_the_plan(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body', 'Home page' );

		$out = implode( "\n", $this->run_diff( [ $id ] )->lines );

		self::assertStringContainsString( $id, $out );
		self::assertStringContainsString( 'Home page', $out );
		self::assertStringContainsString( '-Original body', $out );
		self::assertStringContainsString( '+Changed body', $out );
		self::assertStringContainsString( 'Undo: available', $out );
		self::assertStringContainsString( 'Drift: no', $out );
		self::assertStringContainsString( 'Needs an administrator: no', $out );
	}

	public function test_diff_says_when_the_item_changed_since_and_what_changed_it(): void {
		$first = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->edit_post( 'post_content', 'Edited by hand' );
		$second = $this->post_change( fn () => $this->edit_post( 'post_content', 'Changed again' ) );

		$out = implode( "\n", $this->run_diff( [ $first ] )->lines );

		self::assertStringContainsString( 'Drift: yes', $out );
		self::assertStringContainsString( 'Newer changes: ' . $second, $out );
	}

	public function test_diff_of_a_change_to_code_points_at_the_changes_page(): void {
		$id = $this->theme_change();

		$out = implode( "\n", $this->run_diff( [ $id ] )->lines );

		self::assertStringContainsString( '+// version two', $out );
		self::assertStringContainsString( 'Needs an administrator: yes', $out );
		self::assertStringContainsString( 'stonewright-changes', $out );
	}

	public function test_diff_in_json_has_the_shape_of_the_ability_answer(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$command = $this->run_diff( [ $id ], [ 'format' => 'json' ] );

		$data = json_decode( implode( '', $command->lines ), true );
		self::assertIsArray( $data );
		self::assertSame( [ true, $id, 'ok', true ], [ $data['ok'], $data['change']['change_id'], $data['diff']['status'], $data['plan']['available'] ] );
	}

	public function test_diff_never_prints_a_credential_or_a_stored_image(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/settings-update', 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'example_mailer', 'before' => [ 'host' => 'a.example.test', 'password' => 'sentinel-OLD-12345678' ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'host' => 'b.example.test', 'password' => 'sentinel-NEW-87654321' ] ] );

		$out = $this->run_diff( [ (string) $row['change_id'] ] )->printed() . implode( '', $this->run_diff( [ (string) $row['change_id'] ], [ 'format' => 'json' ] )->lines );

		self::assertStringNotContainsString( 'sentinel-', $out );
		self::assertStringContainsString( 'b.example.test', $out );
		self::assertDoesNotMatchRegularExpression( '/\b[a-f0-9]{64}\b/', $out );
		self::assertStringContainsString( 'Not restorable', $out );
	}

	public function test_diff_caps_the_lines_it_prints(): void {
		$old = implode( "\n", array_map( static fn ( int $n ): string => 'line ' . $n, range( 1, 800 ) ) );
		$new = implode( "\n", array_map( static fn ( int $n ): string => 'LINE ' . $n, range( 1, 800 ) ) );
		$id  = $this->page_change( 31, $old, $new );

		$out = $this->run_diff( [ $id ], [ 'max-lines' => '40' ] );

		self::assertLessThan( 80, count( $out->lines ) );
		self::assertStringContainsString( 'Only part of the diff is shown', implode( "\n", $out->lines ) );
	}

	public function test_diff_refuses_an_id_that_is_not_a_change_or_is_not_known(): void {
		self::assertStringContainsString( 'change id', $this->failure( fn () => $this->run_diff( [ '../x' ] ) )->getMessage() );
		self::assertStringContainsString( 'No change', $this->failure( fn () => $this->run_diff( [ 'cs-' . str_repeat( 'a', 24 ) ] ) )->getMessage() );
		self::assertStringContainsString( 'change id', $this->failure( fn () => $this->run_diff( [] ) )->getMessage() );
		self::assertStringContainsString( 'max_lines', $this->failure( fn () => $this->run_diff( [ 'cs-' . str_repeat( 'a', 24 ) ], [ 'max-lines' => '3' ] ) )->getMessage() );
	}

	// -----------------------------------------------------------------------
	// rollback.
	// -----------------------------------------------------------------------

	public function test_a_dry_run_prints_the_plan_asks_nothing_and_changes_nothing(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$command = $this->run_rollback( [ $id ], [ 'dry-run' => true ] );

		self::assertSame( [], $command->questions );
		self::assertStringContainsString( 'Dry run', implode( "\n", $command->lines ) );
		self::assertStringContainsString( 'Drift: no', implode( "\n", $command->lines ) );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_a_run_asks_first_unless_yes_is_given_and_a_no_stops_it(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$declined          = new RecordingChangesCommand();
		$declined->decline = true;

		try {
			$declined->rollback( [ $id ], [] );
			self::fail( 'A no ends the command.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 'Aborted', $exit->getMessage() );
		}
		self::assertCount( 1, $declined->questions );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );

		$yes = $this->run_rollback( [ $id ], [ 'yes' => true ] );

		self::assertSame( [], $yes->questions );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
		self::assertStringContainsString( 'Rolled back ' . $id, implode( "\n", $yes->successes ) );
	}

	public function test_the_engine_gets_the_options_the_command_was_given_and_never_an_approval(): void {
		$this->spy_on_the_engine();
		$id = 'cs-' . str_repeat( 'a', 24 );

		$this->run_rollback( [ $id ], [ 'yes' => true, 'force-drift' => true, 'permanent' => true, 'expected-sha256' => str_repeat( 'AB', 16 ), 'confirmation-token' => 'swc_token' ] );

		self::assertCount( 1, $this->runs );
		[ $run_id, $options ] = $this->runs[0];
		self::assertSame( $id, $run_id );
		self::assertSame( [ 'wp-cli', 7, true, true, false, str_repeat( 'ab', 16 ), 'swc_token' ], [ $options['by'], $options['actor'], $options['force_drift'], $options['permanent'], $options['dry_run'], $options['expected_current_sha256'], $options['confirmation_token'] ] );
		self::assertArrayNotHasKey( 'human_approved', $options, 'The command line is not an administrator pressing Undo.' );
	}

	public function test_a_dry_run_is_handed_to_the_engine_as_a_dry_run(): void {
		$this->spy_on_the_engine( [ 'ok' => true, 'dry_run' => true, 'change_id' => 'cs-' . str_repeat( 'a', 24 ), 'kind' => 'rollback', 'would_apply' => 'Writes it back.', 'drift' => false, 'newer_changes' => [], 'warnings' => [], 'approval_required' => false, 'confirmation_required' => false, 'restorable' => true, 'diff' => [ 'sections' => [], 'status' => 'ok', 'changed' => true ] ] );

		$this->run_rollback( [ 'cs-' . str_repeat( 'a', 24 ) ], [ 'dry-run' => true ] );

		self::assertTrue( $this->runs[0][1]['dry_run'] );
	}

	public function test_the_undo_of_code_gets_the_approval_answer_and_the_command_points_at_the_page(): void {
		$id = $this->theme_change();

		$exit = $this->failure( fn () => $this->run_rollback( [ $id ], [ 'yes' => true ] ) );

		self::assertSame( 1, $exit->getCode() );
		self::assertStringContainsString( 'administrator', $exit->getMessage() );
		self::assertStringContainsString( 'stonewright-changes', $exit->getMessage() );
		self::assertStringContainsString( $id, $exit->getMessage() );
		self::assertSame( self::V2, (string) file_get_contents( $this->theme . '/functions.php' ), 'The file was not touched.' );
	}

	public function test_drift_is_refused_and_force_drift_overwrites_it(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->edit_post( 'post_content', 'Edited by hand' );

		$exit = $this->failure( fn () => $this->run_rollback( [ $id ], [ 'yes' => true ] ) );
		self::assertStringContainsString( 'stonewright_change_drift', $exit->getMessage() );
		self::assertSame( 'Edited by hand', $this->post_field( 'post_content' ) );

		$this->run_rollback( [ $id ], [ 'yes' => true, 'force-drift' => true ] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );
	}

	public function test_a_site_that_still_fails_is_reported_as_a_failure(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->site( static fn (): string => 'broken' );

		$exit = $this->failure( fn () => $this->run_rollback( [ $id ], [ 'yes' => true ] ) );

		self::assertSame( 1, $exit->getCode() );
	}

	public function test_in_production_safe_mode_the_run_needs_a_token_and_says_how_to_get_one(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe_mode();

		$exit = $this->failure( fn () => $this->run_rollback( [ $id ], [ 'yes' => true ] ) );

		self::assertSame( ChangesCommand::EXIT_APPROVAL, $exit->getCode() );
		self::assertStringContainsString( '--issue-token', $exit->getMessage() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_issue_token_prints_a_token_for_exactly_this_change_and_these_options_and_rolls_nothing_back(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe_mode();

		$plain = $this->run_rollback( [ $id ], [ 'issue-token' => true ] );
		$json  = $this->run_rollback( [ $id ], [ 'issue-token' => true, 'force-drift' => true, 'format' => 'json' ] );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertMatchesRegularExpression( '/^swc_/', $plain->lines[0] );
		self::assertTrue( ConfirmationToken::verify( $plain->lines[0], ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) ) );
		$data = json_decode( $json->lines[0], true );
		self::assertSame( 300, $data['expires_in'] );
		self::assertTrue( ConfirmationToken::verify( $data['confirmation_token'], ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'force_drift' => true ] ) ) );
	}

	public function test_a_token_given_as_an_option_or_in_the_environment_is_verified_once_by_the_engine(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe_mode();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );

		$this->run_rollback( [ $id ], [ 'yes' => true, 'confirmation-token' => $token ] );
		self::assertSame( 'Original body', $this->post_field( 'post_content' ) );

		$second = $this->page_change( 32, 'Original two', 'Changed two' );
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN=' . ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $second, [] ) ) );
		$this->run_rollback( [ $second ], [ 'yes' => true ] );
		self::assertSame( 'Original two', $this->post_field( 'post_content', 32 ) );
	}

	public function test_a_token_for_another_change_is_refused_with_the_token_exit_code(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );
		$this->production_safe_mode();
		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( 'cs-' . str_repeat( 'a', 24 ), [] ) );

		$exit = $this->failure( fn () => $this->run_rollback( [ $id ], [ 'yes' => true, 'confirmation-token' => $token ] ) );

		self::assertSame( ChangesCommand::EXIT_APPROVAL, $exit->getCode() );
		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
	}

	public function test_the_redo_of_a_rollback_is_the_same_command_on_the_rollback_row(): void {
		$id   = $this->page_change( 31, 'Original body', 'Changed body' );
		$undo = ChangeRollback::run( $id );
		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );

		$command = $this->run_rollback( [ $undo['rollback_change_id'] ], [ 'yes' => true ] );

		self::assertSame( 'Changed body', $this->post_field( 'post_content' ) );
		self::assertStringContainsString( 'Redone', implode( "\n", $command->successes ) );
	}

	public function test_the_json_format_prints_the_engine_answer(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$command = $this->run_rollback( [ $id ], [ 'yes' => true, 'format' => 'json' ] );

		$data = json_decode( implode( '', $command->lines ), true );
		self::assertSame( [ true, 'rollback', 'succeeded' ], [ $data['ok'], $data['kind'], $data['rollback_status'] ] );
	}

	public function test_an_id_that_is_not_a_change_id_is_refused_before_anything_runs(): void {
		$this->spy_on_the_engine();

		self::assertStringContainsString( 'change id', $this->failure( fn () => $this->run_rollback( [ '../x' ], [ 'yes' => true ] ) )->getMessage() );
		self::assertStringContainsString( 'expected-sha256', $this->failure( fn () => $this->run_rollback( [ 'cs-' . str_repeat( 'a', 24 ) ], [ 'yes' => true, 'expected-sha256' => 'nope' ] ) )->getMessage() );
		self::assertSame( [], $this->runs );
	}

	public function test_the_engine_writes_the_audit_row_with_the_way_wp_cli(): void {
		$id = $this->page_change( 31, 'Original body', 'Changed body' );

		$this->run_rollback( [ $id ], [ 'yes' => true ] );

		$rows = $this->audit_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'wp-cli', $this->audit_args( $rows[0] )['by'] );
	}
}

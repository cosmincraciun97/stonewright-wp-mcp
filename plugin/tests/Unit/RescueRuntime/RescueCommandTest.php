<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Cli\RescueCommand;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\CliExit;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\RecordingRescueCommand;

/**
 * wp stonewright rescue status|rollback <incident>: what it asks of the caller, what it hands
 * to the rollback, and how it ends.
 *
 * @covers \Stonewright\WpMcp\Cli\RescueCommand
 */
final class RescueCommandTest extends TestCase {

	private const INCIDENT = 'cs-0123456789abcdef01234567';

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $rollbacks = [];

	/** @var list<array{0: string, 1: mixed, 2: string, 3: string}> */
	private array $audits = [];

	/** @var array<string, mixed>|\WP_Error */
	private mixed $rollback_result = [];

	/** @var array<string, mixed> */
	private array $saved_options = [];

	protected function setUp(): void {
		$this->saved_options = $GLOBALS['stonewright_test_options'] ?? [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$this->rollbacks       = [];
		$this->audits          = [];
		$this->rollback_result = [ 'ok' => true, 'incident_id' => self::INCIDENT, 'rollback_status' => 'succeeded', 'state' => 'rolled_back', 'site_status' => 'healthy' ];
		RescueCommand::$rollback_runner = function ( string $id, array $options ): mixed {
			$this->rollbacks[] = [ $id, $options ];
			return $this->rollback_result;
		};
		RescueCommand::$audit_runner = function ( string $id, mixed $result, string $by, string $action ): void {
			$this->audits[] = [ $id, $result, $by, $action ];
		};
		RescueCommand::$incident_source = static fn (): array => [
			[
				'id'            => self::INCIDENT,
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'state'         => 'incident',
				'armed_at'      => 1_800_000_000,
				'incident'      => [ 'recorded_at' => 1_800_000_090, 'file' => 'wp-content/themes/site-a/functions.php', 'line' => 12, 'type' => 1, 'message_sha256' => str_repeat( 'a', 64 ), 'source' => 'shutdown' ],
			],
		];
	}

	protected function tearDown(): void {
		RescueCommand::$rollback_runner = null;
		RescueCommand::$audit_runner    = null;
		RescueCommand::$incident_source = null;
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN' );
		$GLOBALS['stonewright_test_options']         = $this->saved_options;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
	}

	/** @param list<string> $args @param array<string, mixed> $assoc */
	private function run_rollback( array $args, array $assoc = [] ): RecordingRescueCommand {
		$command = new RecordingRescueCommand();
		$command->rollback( $args, $assoc );
		return $command;
	}

	private static function production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
	}

	// -----------------------------------------------------------------------
	// status.
	// -----------------------------------------------------------------------

	public function test_status_lists_the_open_incidents(): void {
		$command = new RecordingRescueCommand();

		$command->status( [], [] );

		self::assertCount( 1, $command->tables );
		self::assertSame( 'table', $command->tables[0]['format'] );
		self::assertSame( [ 'id', 'ability', 'resource', 'state', 'recorded_at', 'file' ], $command->tables[0]['fields'] );
		self::assertSame(
			[
				'id'          => self::INCIDENT,
				'ability'     => 'stonewright/theme-file-patch',
				'resource'    => 'functions.php',
				'state'       => 'incident',
				'recorded_at' => gmdate( 'Y-m-d\TH:i:s\Z', 1_800_000_090 ),
				'file'        => 'wp-content/themes/site-a/functions.php',
			],
			$command->tables[0]['rows'][0]
		);
	}

	public function test_status_passes_the_requested_format_through(): void {
		$command = new RecordingRescueCommand();

		$command->status( [], [ 'format' => 'json' ] );

		self::assertSame( 'json', $command->tables[0]['format'] );
	}

	public function test_status_says_so_when_nothing_is_open(): void {
		RescueCommand::$incident_source = static fn (): array => [];
		$command = new RecordingRescueCommand();

		$command->status( [], [] );

		self::assertSame( [ 'No open rescue incidents.' ], $command->lines );
		self::assertSame( [], $command->tables );
	}

	public function test_status_with_a_machine_format_prints_an_empty_list_when_nothing_is_open(): void {
		RescueCommand::$incident_source = static fn (): array => [];
		$command = new RecordingRescueCommand();

		$command->status( [], [ 'format' => 'json' ] );

		self::assertSame( [], $command->tables[0]['rows'] );
	}

	public function test_an_unknown_format_is_refused(): void {
		$this->expectException( CliExit::class );
		( new RecordingRescueCommand() )->status( [], [ 'format' => 'xml' ] );
	}

	public function test_status_reports_a_journal_that_cannot_be_read(): void {
		RescueCommand::$incident_source = static function (): never {
			throw new \RuntimeException( 'journal unreadable' );
		};

		try {
			( new RecordingRescueCommand() )->status( [], [] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
			self::assertStringNotContainsString( 'journal unreadable', $exit->getMessage() );
		}
	}

	// -----------------------------------------------------------------------
	// Who may run it.
	// -----------------------------------------------------------------------

	/** @return array<string, array{0: int, 1: array<string, bool>}> */
	public static function callers_who_may_not_run_it(): array {
		return [
			'no user was given'          => [ 0, [ 'manage_options' => true ] ],
			'a user who is not an admin' => [ 8, [ 'read' => true ] ],
		];
	}

	/**
	 * @param array<string, bool> $caps
	 * @dataProvider callers_who_may_not_run_it
	 */
	public function test_both_subcommands_need_an_administrator( int $user_id, array $caps ): void {
		$GLOBALS['stonewright_test_current_user_id'] = $user_id;
		$GLOBALS['stonewright_test_user_caps']       = $caps;

		foreach ( [ 'status', 'rollback' ] as $subcommand ) {
			$command = new RecordingRescueCommand();
			try {
				'status' === $subcommand ? $command->status( [], [] ) : $command->rollback( [ self::INCIDENT ], [] );
				self::fail( $subcommand . ' ran without an administrator' );
			} catch ( CliExit $exit ) {
				self::assertSame( 1, $exit->getCode() );
				self::assertStringContainsString( '--user', $exit->getMessage() );
			}
		}
		self::assertSame( [], $this->rollbacks );
	}

	/** @return array<string, array{0: list<string>}> */
	public static function bad_incident_ids(): array {
		return [
			'no id'           => [ [] ],
			'an empty id'     => [ [ '' ] ],
			'a path'          => [ [ '../../etc/passwd' ] ],
			'a space'         => [ [ 'cs-1 2' ] ],
			'a flag'          => [ [ '--require=evil.php' ] ],
			'a shell command' => [ [ 'cs-1;rm -rf /' ] ],
			'a very long id'  => [ [ str_repeat( 'a', 97 ) ] ],
			'a leading dash'  => [ [ '-cs-1' ] ],
		];
	}

	/**
	 * @param list<string> $args
	 * @dataProvider bad_incident_ids
	 */
	public function test_an_incident_id_that_is_not_an_id_is_refused_before_anything_runs( array $args ): void {
		try {
			$this->run_rollback( $args );
			self::fail( 'The id must be refused.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
		}
		self::assertSame( [], $this->rollbacks );
	}

	// -----------------------------------------------------------------------
	// rollback.
	// -----------------------------------------------------------------------

	public function test_rollback_runs_the_rollback_of_the_incident_as_the_current_user_and_audits_it(): void {
		$command = $this->run_rollback( [ self::INCIDENT ] );

		self::assertSame( [ [ self::INCIDENT, [ 'by' => 'wp-cli', 'user_id' => 7 ] ] ], $this->rollbacks );
		self::assertSame( [ [ self::INCIDENT, $this->rollback_result, 'wp-cli', 'rollback' ] ], $this->audits );
		self::assertCount( 1, $command->successes );
		self::assertStringContainsString( self::INCIDENT, $command->successes[0] );
		self::assertStringContainsString( 'loads', $command->successes[0] );
	}

	public function test_a_rollback_that_leaves_the_site_failing_ends_with_exit_code_one(): void {
		$this->rollback_result = [ 'ok' => true, 'incident_id' => self::INCIDENT, 'rollback_status' => 'succeeded', 'state' => 'rolled_back', 'site_status' => 'still_failing' ];

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
			self::assertStringContainsString( 'still fails', $exit->getMessage() );
		}
		self::assertCount( 1, $this->audits, 'the rollback itself was audited' );
	}

	public function test_a_rollback_the_health_check_could_not_confirm_is_reported_with_a_warning(): void {
		$this->rollback_result = [ 'ok' => true, 'incident_id' => self::INCIDENT, 'rollback_status' => 'succeeded', 'state' => 'rolled_back', 'site_status' => 'unknown' ];

		$command = $this->run_rollback( [ self::INCIDENT ] );

		self::assertCount( 1, $command->warnings );
		self::assertStringContainsString( 'not confirmed', $command->warnings[0] );
		self::assertCount( 1, $command->successes );
	}

	public function test_a_failed_rollback_ends_with_exit_code_one_and_names_the_error(): void {
		$this->rollback_result = new \WP_Error( 'stonewright_rescue_rollback_failed', 'The rollback did not complete.', [ 'rollback_status' => 'failed' ] );

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
			self::assertStringContainsString( 'stonewright_rescue_rollback_failed', $exit->getMessage() );
			self::assertStringContainsString( 'The rollback did not complete.', $exit->getMessage() );
		}
		self::assertCount( 1, $this->audits );
		self::assertInstanceOf( \WP_Error::class, $this->audits[0][1], 'a failure is audited as well' );
	}

	public function test_an_unknown_incident_ends_with_exit_code_one(): void {
		$this->rollback_result = new \WP_Error( 'stonewright_rescue_incident_not_found', 'No rescue incident or armed change has that id.', [ 'status' => 404 ] );

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
			self::assertStringContainsString( 'stonewright_rescue_incident_not_found', $exit->getMessage() );
		}
	}

	public function test_a_rollback_that_returns_something_else_is_reported(): void {
		$this->rollback_result = 'not an array';

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
		}
	}

	public function test_an_exception_from_the_rollback_is_reported_not_thrown(): void {
		RescueCommand::$rollback_runner = static function (): never {
			throw new \RuntimeException( 'database has gone away' );
		};

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 1, $exit->getCode() );
			self::assertStringNotContainsString( 'database has gone away', $exit->getMessage() );
		}
	}

	public function test_json_output_is_the_result_of_the_rollback(): void {
		$command = $this->run_rollback( [ self::INCIDENT ], [ 'format' => 'json' ] );

		self::assertSame( [ json_encode( $this->rollback_result ) ], $command->lines );
		self::assertSame( [], $command->successes );
	}

	// -----------------------------------------------------------------------
	// Production-safe mode: the confirmation token.
	// -----------------------------------------------------------------------

	public function test_outside_production_safe_mode_no_token_is_needed(): void {
		$this->run_rollback( [ self::INCIDENT ] );

		self::assertCount( 1, $this->rollbacks );
	}

	public function test_in_production_safe_mode_a_rollback_without_a_token_asks_for_approval_and_runs_nothing(): void {
		self::production_safe();

		try {
			$this->run_rollback( [ self::INCIDENT ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 2, $exit->getCode() );
			self::assertStringContainsString( '--issue-token', $exit->getMessage() );
			self::assertStringContainsString( '--confirmation-token', $exit->getMessage() );
		}
		self::assertSame( [], $this->rollbacks );
		self::assertSame( [], $this->audits );
	}

	public function test_in_production_safe_mode_a_token_for_this_rollback_lets_it_run_once(): void {
		self::production_safe();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ], 300 );

		$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $token ] );
		self::assertCount( 1, $this->rollbacks );

		try {
			$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $token ] );
			self::fail( 'A token is single use.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 2, $exit->getCode(), 'a used token needs a new approval' );
		}
		self::assertCount( 1, $this->rollbacks );
	}

	/** @return array<string, array{0: string}> */
	public static function tokens_that_do_not_apply(): array {
		return [
			'garbage'                      => [ 'garbage' ],
			'a token for another incident' => [ 'other-incident' ],
			'a token for another ability'  => [ 'other-ability' ],
		];
	}

	/** @dataProvider tokens_that_do_not_apply */
	public function test_in_production_safe_mode_a_token_that_does_not_apply_asks_for_approval( string $kind ): void {
		self::production_safe();
		$token = match ( $kind ) {
			'other-incident' => ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => 'cs-ffffffffffffffffffffffff' ], 300 ),
			'other-ability'  => ConfirmationToken::issue( 'stonewright/other-ability', [ 'incident_id' => self::INCIDENT ], 300 ),
			default          => 'not-a-token',
		};

		try {
			$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $token ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 2, $exit->getCode() );
		}
		self::assertSame( [], $this->rollbacks );
	}

	public function test_a_token_issued_for_another_user_does_not_apply(): void {
		self::production_safe();
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ], 300 );
		$GLOBALS['stonewright_test_current_user_id'] = 7;

		try {
			$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $token ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 2, $exit->getCode() );
		}
	}

	public function test_issue_token_prints_a_token_for_exactly_this_rollback_and_changes_nothing(): void {
		$command = $this->run_rollback( [ self::INCIDENT ], [ 'issue-token' => true ] );

		self::assertSame( [], $this->rollbacks, 'the rollback did not run' );
		self::assertCount( 1, $command->lines );
		self::assertStringStartsWith( 'swc_', $command->lines[0] );
		self::assertTrue(
			ConfirmationToken::verify( $command->lines[0], 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ] ),
			'the token verifies for the rollback ability and its arguments'
		);
	}

	public function test_a_token_for_one_incident_does_not_verify_for_another(): void {
		$command = $this->run_rollback( [ self::INCIDENT ], [ 'issue-token' => true ] );

		self::assertFalse(
			ConfirmationToken::verify( $command->lines[0], 'stonewright/rescue-rollback', [ 'incident_id' => 'cs-ffffffffffffffffffffffff' ] )
		);
	}

	public function test_issue_token_with_the_json_format_prints_the_token_and_its_lifetime(): void {
		$command = $this->run_rollback( [ self::INCIDENT ], [ 'issue-token' => true, 'format' => 'json' ] );

		$decoded = json_decode( $command->lines[0], true );
		self::assertStringStartsWith( 'swc_', $decoded['confirmation_token'] );
		self::assertSame( 300, $decoded['expires_in'] );
	}

	public function test_the_token_is_never_printed_by_a_rollback(): void {
		self::production_safe();
		$token   = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ], 300 );
		$command = $this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $token ] );

		self::assertStringNotContainsString( $token, $command->printed() );
	}

	public function test_the_companion_passes_the_token_in_the_environment_and_the_option_wins(): void {
		self::production_safe();
		$env_token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ], 300 );
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN=' . $env_token );

		$command = $this->run_rollback( [ self::INCIDENT ] );

		self::assertCount( 1, $this->rollbacks, 'the variable was enough' );
		self::assertStringNotContainsString( $env_token, $command->printed() );

		// A token in the option is used before the variable.
		$option_token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => self::INCIDENT ], 300 );
		$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => $option_token ] );
		self::assertCount( 2, $this->rollbacks );
	}

	public function test_without_the_variable_and_without_the_option_there_is_no_token(): void {
		self::production_safe();
		putenv( 'STONEWRIGHT_CONFIRMATION_TOKEN' );

		$this->expectException( CliExit::class );
		$this->run_rollback( [ self::INCIDENT ] );
	}

	public function test_the_command_needs_no_theme_and_no_plugin_other_than_stonewright(): void {
		// It runs with every plugin but Stonewright skipped and with the theme skipped, so its code may use
		// WordPress core and Stonewright classes only.
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Cli/RescueCommand.php' );

		self::assertDoesNotMatchRegularExpression( '/\b(wp_get_theme|get_template|get_stylesheet|get_theme_mod|get_plugins|is_plugin_active|Elementor|WooCommerce|WC_)\b/', $source );
		self::assertDoesNotMatchRegularExpression( '/\b(add_theme_support|register_nav_menu|wp_enqueue_|get_header|get_footer)\b/', $source );
	}

	public function test_a_boolean_flag_with_a_value_is_not_read_as_a_token(): void {
		self::production_safe();

		try {
			$this->run_rollback( [ self::INCIDENT ], [ 'confirmation-token' => true ] );
			self::fail( 'The command must stop.' );
		} catch ( CliExit $exit ) {
			self::assertSame( 2, $exit->getCode() );
		}
	}
}

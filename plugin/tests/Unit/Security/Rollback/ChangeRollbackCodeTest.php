<?php
/**
 * The rollback engine against the code families: a person is needed, and then a run restores and a redo restores the after image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\CodeRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 */
final class ChangeRollbackCodeTest extends RollbackTestCase {

	private const NAME = 'rollback-test-demo.php';

	private const V1 = "<?php\n// version one\nadd_action( 'init', '__return_true' );\n";

	private const V2 = "<?php\n// version two\nadd_action( 'init', '__return_true' );\n";

	private const GAP = [
		'reason'        => 'No native control owns this fixture CSS.',
		'methods_tried' => [ 'typed_api' ],
	];

	/** @var array<string, array<string, mixed>> */
	private array $wpcode = [];

	protected function setUp(): void {
		parent::setUp();
		$this->clean_sandbox();
		$this->wpcode = [ '12' => [ 'code' => self::V1, 'title' => 'Demo snippet', 'language' => 'php', 'active' => true ] ];
		ProviderRegistry::set_for_tests( [ 'wpcode' => new WpCodeProvider( $this->wpcode_backend() ) ] );
		RollbackRecipes::set_provider_resolver( null );
	}

	protected function tearDown(): void {
		ProviderRegistry::reset_for_tests();
		RollbackRecipes::set_provider_resolver( null );
		$this->clean_sandbox();
		parent::tearDown();
	}

	private function clean_sandbox(): void {
		foreach ( [ SandboxFiles::draft_dir() . '/rollback-test-*', SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . 'rollback-test-*' ] as $pattern ) {
			foreach ( glob( $pattern ) ?: [] as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	private function ok( array|\WP_Error $result ): array {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		return $result;
	}

	// ---- one change per code family ----------------------------------------------------------------------

	/** @return string The id of a theme file change from "one" to "two". */
	private function theme_change(): string {
		$path = $this->theme . '/functions.php';
		file_put_contents( $path, self::V1 );
		$result = ThemeWriteTransaction::apply(
			[
				'absolute' => $path,
				'relative' => 'functions.php',
				'before'   => self::V1,
				'after'    => self::V2,
				'language' => 'php',
			]
		);
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];
		$this->forget_journal();
		return $id;
	}

	private function snippet_change(): string {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );
		$dry = $provider->dry_run( [ 'target_id' => '12', 'code' => self::V2, 'language' => 'php' ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::issue( [ 'path' => 'wpcode/snippet/12', 'after_sha256' => $dry['after_sha256'], 'language' => 'php' ] );
		self::assertIsArray( $grant );
		$applied = $provider->apply( [ 'target_id' => '12', 'code' => self::V2, 'language' => 'php', 'custom_code_grant' => $grant['token'], 'expected_before_sha256' => $dry['before_sha256'] ] );
		self::assertIsArray( $applied, $applied instanceof \WP_Error ? $applied->get_error_message() : '' );
		$id = (string) $this->only_row_of( 'custom_code' )['change_id'];
		$this->forget_journal();
		return $id;
	}

	private function sandbox_change(): string {
		SandboxFiles::write( self::NAME, self::V1 );
		SandboxFiles::write( self::NAME, self::V2 );
		$rows = array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => 'sandbox' === $row['family'] ) );
		self::assertCount( 2, $rows );
		$this->forget_journal();
		return (string) $rows[1]['change_id'];
	}

	private function css_change(): string {
		$GLOBALS['stonewright_test_custom_css'] = ".a{color:red;}\n";
		$ability                                 = new ThemeCustomCss();
		$dry                                     = $ability->execute( [ 'action' => 'update', 'css' => ".a{color:blue;}\n", 'dry_run' => true, 'native_gap' => self::GAP ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::approve_proposal( (string) $dry['proposal_id'] );
		self::assertIsArray( $grant );
		$applied = $ability->execute( [ 'action' => 'update', 'css' => ".a{color:blue;}\n", 'native_gap' => self::GAP, 'custom_code_grant' => $grant['token'] ] );
		self::assertIsArray( $applied, $applied instanceof \WP_Error ? $applied->get_error_message() : '' );
		$id = (string) $this->only_row_of( 'custom_code' )['change_id'];
		$this->forget_journal();
		return $id;
	}

	/**
	 * Each code family: how to change it, what its live state reads, and the two states.
	 *
	 * @return array<string, array{0:string,1:string,2:string}> method, before, after
	 */
	public static function families(): array {
		return [
			'theme file'    => [ 'theme_change', self::V1, self::V2 ],
			'snippet'       => [ 'snippet_change', self::V1, self::V2 ],
			'sandbox draft' => [ 'sandbox_change', self::V1, self::V2 ],
			'customizer css' => [ 'css_change', ".a{color:red;}\n", ".a{color:blue;}\n" ],
		];
	}

	private function live( string $method ): string {
		return match ( $method ) {
			'theme_change'   => (string) file_get_contents( $this->theme . '/functions.php' ),
			'snippet_change' => (string) $this->wpcode['12']['code'],
			'sandbox_change' => (string) SandboxFiles::read( self::NAME ),
			default          => (string) $GLOBALS['stonewright_test_custom_css'],
		};
	}

	// ---- the gate: an agent is refused, an administrator is not ------------------------------------------

	/**
	 * @dataProvider families
	 */
	public function test_an_agent_or_cli_call_is_refused_with_the_approval_answer_and_nothing_is_written( string $method, string $before, string $after ): void {
		$id = $this->{$method}();

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'by' => 'ability' ] ), 'stonewright_rescue_approval_required' );

		$data = $error->get_error_data();
		self::assertTrue( $data['approval_required'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertSame( $id, $data['change_set_id'] );
		self::assertNotSame( '', $data['approval_url'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( $after, $this->live( $method ), 'The code is as it was.' );
		self::assertSame( [], array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => 'change' !== $row['kind'] ) ), 'No rollback row was written.' );
	}

	/**
	 * @dataProvider families
	 */
	public function test_the_dry_run_of_code_says_that_a_person_is_needed_without_refusing( string $method, string $before, string $after ): void {
		$id = $this->{$method}();

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertTrue( $plan['approval_required'] );
		self::assertNotSame( '', $plan['approval_url'] );
		self::assertTrue( $plan['diff']['changed'] );
		self::assertSame( $after, $this->live( $method ) );
	}

	public function test_the_approval_is_asked_for_before_a_confirmation_token_that_would_not_change_it(): void {
		$id = $this->theme_change();
		$this->production_safe();

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_rescue_approval_required' );
	}

	// ---- the page: human_approved restores, and a redo restores the after image -------------------------

	/**
	 * @dataProvider families
	 */
	public function test_an_approved_run_restores_the_before_state_and_a_redo_restores_the_after_state( string $method, string $before, string $after ): void {
		$id = $this->{$method}();

		$undo = $this->ok( ChangeRollback::run( $id, [ 'human_approved' => true, 'by' => 'admin-page' ] ) );

		self::assertSame( $before, $this->live( $method ) );
		self::assertSame( 'ledger', $undo['path'] );
		$rollback = $this->row( $undo['rollback_change_id'] );
		self::assertSame( 'rollback', $rollback['kind'] );
		self::assertSame( $id, $rollback['parent_id'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
		self::assertContains( $rollback['status'], [ 'verified', 'probe_unavailable' ] );

		$this->assert_refused( ChangeRollback::run( $undo['rollback_change_id'] ), 'stonewright_rescue_approval_required' );
		self::assertSame( $before, $this->live( $method ), 'A redo needs a person too.' );

		$redo = $this->ok( ChangeRollback::run( $undo['rollback_change_id'], [ 'human_approved' => true ] ) );

		self::assertSame( $after, $this->live( $method ) );
		self::assertSame( 'redo', $this->row( $redo['rollback_change_id'] )['kind'] );
		self::assertSame( $undo['rollback_change_id'], $this->row( $redo['rollback_change_id'] )['parent_id'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
	}

	/**
	 * @dataProvider families
	 */
	public function test_code_that_was_edited_since_is_drift_even_for_an_administrator( string $method, string $before, string $after ): void {
		$id = $this->{$method}();
		match ( $method ) {
			'theme_change'   => file_put_contents( $this->theme . '/functions.php', "<?php\n// hand edit\n" ),
			'snippet_change' => $this->wpcode['12']['code'] = "<?php\n// hand edit\n",
			'sandbox_change' => SandboxFiles::write( self::NAME, "<?php\n// hand edit\n" ),
			default          => $GLOBALS['stonewright_test_custom_css'] = ".a{color:green;}\n",
		};
		if ( 'sandbox_change' === $method ) {
			$this->forget_journal();
		}

		$this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_change_drift' );

		self::assertNotSame( $before, $this->live( $method ) );
	}

	public function test_a_broken_file_edited_in_since_is_replaced_by_the_approved_one_when_the_person_forces_it(): void {
		$id = $this->theme_change();
		file_put_contents( $this->theme . '/functions.php', "<?php\nobfuscated = array(1);\n" );

		$this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_change_drift' );
		$done = $this->ok( ChangeRollback::run( $id, [ 'human_approved' => true, 'force_drift' => true ] ) );

		self::assertTrue( $done['drift_forced'] );
		self::assertSame( self::V1, $this->live( 'theme_change' ) );
	}

	public function test_a_failed_probe_after_a_code_restore_puts_the_code_back(): void {
		$id = $this->sandbox_change();
		// Version one of the file is the one that breaks the site.
		$this->site( static fn (): string => str_contains( (string) SandboxFiles::read( self::NAME ), 'version one' ) ? 'broken' : 'healthy' );

		$error = ChangeRollback::run( $id, [ 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_reverted', $error->get_error_code() );
		self::assertSame( self::V2, $this->live( 'sandbox_change' ) );
	}

	public function test_a_verified_code_change_that_the_journal_still_knows_is_taken_back_when_the_probe_fails_after_the_restore(): void {
		$path = $this->theme . '/functions.php';
		file_put_contents( $path, self::V1 );
		ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'functions.php', 'before' => self::V1, 'after' => self::V2, 'language' => 'php' ] );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];
		$entry = ChangeJournal::get( $id );
		self::assertNotNull( $entry, 'The write armed the journal.' );
		if ( 'verified' !== $entry['state'] ) {
			ChangeJournal::settle( $id, 'verified' );
		}
		self::assertTrue( RollbackRecipes::available( ChangeJournal::get( $id ) ?? [] ), 'The journal has a recipe that can still run.' );
		// Version one of the file is the one that breaks the site.
		$this->site( fn (): string => str_contains( (string) file_get_contents( $this->theme . '/functions.php' ), 'version one' ) ? 'broken' : 'healthy' );

		$error = ChangeRollback::run( $id, [ 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_reverted', $error->get_error_code(), 'The code transaction takes the restore back itself, and the answer says so.' );
		self::assertSame( self::V2, (string) file_get_contents( $path ), 'The file that worked is back.' );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
	}

	/**
	 * A theme file restore breaks the site: the theme file transaction probes it, takes the restore back and records the
	 * attempt. The engine answers as it does for its own revert, and writes nothing more.
	 */
	public function test_a_theme_file_restore_that_the_transaction_took_back_answers_reverted_with_the_probe_evidence(): void {
		$id   = $this->theme_change();
		$path = $this->theme . '/functions.php';
		$this->site( fn (): string => str_contains( (string) file_get_contents( $this->theme . '/functions.php' ), 'version one' ) ? 'broken' : 'healthy' );
		$rows_before = $this->ledger_count();

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true, 'by' => 'admin-page' ] ), 'stonewright_change_rollback_reverted' );

		$data = $error->get_error_data();
		self::assertSame( self::V2, (string) file_get_contents( $path ), 'The change that worked is back.' );
		self::assertSame( 'reverted', $data['rollback_status'] );
		self::assertTrue( $data['reverted'] );
		self::assertSame( 'still_failing', $data['site_status'] );
		self::assertSame( 'healthy', $data['site_after_revert'], 'The site loads again after the restore was taken back.' );
		self::assertIsArray( $data['probe'], 'The evidence of the failing probe.' );
		self::assertArrayHasKey( 'status', $data['probe'] );
		self::assertSame( 'verified', $this->row( $id )['status'], 'The change is still in effect.' );

		// One row for the attempt, the one the transaction wrote, and nothing else.
		self::assertSame( $rows_before + 1, $this->ledger_count(), 'Only the row the transaction wrote is new.' );
		$attempt = $this->row( $data['rollback_change_id'] );
		self::assertSame( 'rollback', $attempt['kind'] );
		self::assertSame( $id, $attempt['parent_id'] );
		self::assertSame( 'rolled_back', $attempt['status'], 'The row follows the journal entry of the same id.' );
		self::assertSame( [], array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => 'redo' === $row['kind'] ) ), 'The restore is not taken back a second time.' );
		self::assertSame( 'rolled_back', ChangeJournal::get( $attempt['change_id'] )['state'] ?? 'missing', 'The journal and the ledger agree on the attempt.' );

		// The fatal of the attempt is not an open incident, and nothing is left on.
		self::assertSame( [], ChangeJournal::open_incidents() );
		self::assertSame( '', get_option( ChangeJournal::OPEN_OPTION, '' ) );
	}

	public function test_a_theme_file_redo_that_breaks_the_site_is_answered_and_recorded_the_same_way(): void {
		$id   = $this->theme_change();
		$path = $this->theme . '/functions.php';
		$undo = $this->ok( ChangeRollback::run( $id, [ 'human_approved' => true ] ) );
		self::assertSame( self::V1, (string) file_get_contents( $path ) );
		$this->site( fn (): string => str_contains( (string) file_get_contents( $this->theme . '/functions.php' ), 'version two' ) ? 'broken' : 'healthy' );

		$error = $this->assert_refused( ChangeRollback::run( $undo['rollback_change_id'], [ 'human_approved' => true ] ), 'stonewright_change_rollback_reverted' );

		self::assertSame( self::V1, (string) file_get_contents( $path ), 'The state before the redo is back.' );
		$attempt = $this->row( $error->get_error_data()['rollback_change_id'] );
		self::assertSame( 'redo', $attempt['kind'] );
		self::assertSame( $undo['rollback_change_id'], $attempt['parent_id'] );
		self::assertSame( 'rolled_back', $attempt['status'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'], 'The change stays undone.' );
		self::assertSame( [], ChangeJournal::open_incidents() );
	}

	public function test_a_theme_file_restore_that_failed_for_another_reason_still_answers_failed(): void {
		$path = $this->theme . '/functions.php';
		$bad  = "<?php\nfunction broken( {\n";
		file_put_contents( $path, $bad );
		$result = ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'functions.php', 'before' => $bad, 'after' => self::V2, 'language' => 'php', 'skip_smoke' => true ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];
		$this->forget_journal();

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_change_rollback_failed' );

		self::assertSame( 'failed', $error->get_error_data()['rollback_status'] );
		self::assertNotSame( 'reverted', $error->get_error_data()['rollback_status'] );
		self::assertSame( self::V2, (string) file_get_contents( $path ), 'The file was not touched.' );
	}

	/**
	 * The other code families have no transaction of their own that probes: the engine probes and takes the restore back, and
	 * writes the rollback row as taken back and a redo row for the revert.
	 *
	 * @dataProvider engine_probed_families
	 */
	public function test_an_engine_revert_of_a_code_family_answers_reverted_and_keeps_both_rows( string $method, string $before ): void {
		$id = $this->{$method}();
		$this->site( fn (): string => $this->live( $method ) === $before ? 'broken' : 'healthy' );

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_change_rollback_reverted' );

		$data = $error->get_error_data();
		self::assertNotSame( $before, $this->live( $method ), 'The change that worked is back.' );
		self::assertSame( 'verified', $this->row( $id )['status'] );
		self::assertSame( 'rolled_back_by', $this->row( $data['rollback_change_id'] )['status'] );
		$children = ChangeLedger::children( $data['rollback_change_id'] );
		self::assertCount( 1, $children );
		self::assertSame( 'redo', $children[0]['kind'] );
		self::assertSame( $data['revert_change_id'], $children[0]['change_id'] );
	}

	/** @return array<string, array{0:string,1:string}> */
	public static function engine_probed_families(): array {
		return [
			'snippet'       => [ 'snippet_change', self::V1 ],
			'sandbox draft' => [ 'sandbox_change', self::V1 ],
			'customizer css' => [ 'css_change', ".a{color:red;}\n" ],
		];
	}

	public function test_in_production_safe_mode_code_needs_the_token_and_the_person(): void {
		$id = $this->theme_change();
		$this->production_safe();

		$this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_confirmation_required' );

		$token = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );
		$this->ok( ChangeRollback::run( $id, [ 'human_approved' => true, 'confirmation_token' => $token ] ) );
		self::assertSame( self::V1, $this->live( 'theme_change' ) );
	}

	public function test_a_credential_file_row_is_never_restorable_even_for_a_person(): void {
		$path = $this->theme . '/wp-config.php';
		file_put_contents( $path, "<?php\n// synthetic\n" );
		ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'wp-config.php', 'before' => "<?php\n// synthetic\n", 'after' => "<?php\n// synthetic 2\n", 'language' => 'php', 'skip_smoke' => true ] );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];

		$error = $this->assert_refused( ChangeRollback::run( $id, [ 'human_approved' => true ] ), 'stonewright_change_not_restorable' );

		self::assertSame( 'secret_file', $error->get_error_data()['reason'] );
	}

	public function test_a_journal_entry_for_a_code_change_does_not_let_an_agent_around_the_gate(): void {
		$path = $this->theme . '/functions.php';
		file_put_contents( $path, self::V1 );
		ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'functions.php', 'before' => self::V1, 'after' => self::V2, 'language' => 'php' ] );
		$id = (string) $this->only_row_of( 'theme_file' )['change_id'];
		self::assertNotNull( ChangeJournal::get( $id ), 'The write armed the journal.' );

		$error = $this->assert_refused( ChangeRollback::run( $id ), 'stonewright_rescue_approval_required' );

		self::assertSame( self::V2, $this->live( 'theme_change' ) );
		self::assertSame( $id, $error->get_error_data()['change_set_id'] );
		self::assertSame( 1, ChangeLedger::count() );
	}

	// ---- backends -------------------------------------------------------------------------------------------

	/** @return callable */
	private function wpcode_backend(): callable {
		return function ( string $op, array $args ) {
			switch ( $op ) {
				case 'discover':
					return [ 'active' => true, 'version' => '2.2.0' ];
				case 'read':
					$id = (string) ( $args['id'] ?? '' );
					return isset( $this->wpcode[ $id ] ) ? array_merge( [ 'id' => $id ], $this->wpcode[ $id ] ) : new \WP_Error( 'stonewright_wpcode_not_found', 'missing', [ 'status' => 404 ] );
				case 'list_active':
					$items = [];
					foreach ( $this->wpcode as $id => $row ) {
						if ( ! empty( $row['active'] ) && 'php' === $row['language'] ) {
							$items[] = [ 'id' => (string) $id, 'title' => $row['title'], 'language' => 'php', 'active' => true, 'code' => $row['code'] ];
						}
					}
					return [ 'ok' => true, 'count' => count( $items ), 'items' => $items ];
				case 'assemble_runtime':
					$parts = [];
					foreach ( (array) ( $args['snippets'] ?? [] ) as $snippet ) {
						$parts[] = (string) ( $snippet['code'] ?? '' );
					}
					$payload = implode( "\n", $parts );
					return [ 'ok' => true, 'count' => count( $parts ), 'sha256' => hash( 'sha256', $payload ), 'payload' => $payload ];
				case 'lint_runtime':
					return [ 'ok' => true, 'sha256' => hash( 'sha256', (string) ( $args['payload'] ?? '' ) ) ];
				case 'native_save':
					$this->wpcode[ (string) $args['id'] ]['code'] = (string) $args['code'];
					return true;
				case 'rebuild_cache':
					return true;
				case 'inspect_cache':
					return [ 'ok' => true, 'member' => true, 'count' => 1 ];
			}
			return null;
		};
	}
}

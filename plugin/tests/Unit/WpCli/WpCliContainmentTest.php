<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\WpCli;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Abilities\WpCli\BatchRun;
use Stonewright\WpMcp\Abilities\WpCli\Discover;
use Stonewright\WpMcp\Abilities\WpCli\JobStart;
use Stonewright\WpMcp\Abilities\WpCli\JobStatus;
use Stonewright\WpMcp\Abilities\WpCli\Run;
use Stonewright\WpMcp\Abilities\WpCli\Status;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Who may run PHP and WP-CLI commands, and what the WP-CLI abilities refuse before the companion is called.
 *
 * @covers \Stonewright\WpMcp\Security\Permissions::can_run_code
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Run
 * @covers \Stonewright\WpMcp\Abilities\WpCli\BatchRun
 * @covers \Stonewright\WpMcp\Abilities\WpCli\JobStart
 */
final class WpCliContainmentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']      = true;
		$GLOBALS['stonewright_test_user_caps']           = [ 'manage_options' => true, 'edit_plugins' => true, 'read' => true ];
		$GLOBALS['stonewright_test_is_multisite']        = false;
		$GLOBALS['stonewright_test_is_super_admin']      = false;
		$GLOBALS['stonewright_test_companion_responses'] = [];
		$GLOBALS['stonewright_test_companion_requests']  = [];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_options']             = [
			'stonewright_companion_url'   => 'http://127.0.0.1:8765',
			'stonewright_companion_token' => 'test-token',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_logged_in']      = false;
		$GLOBALS['stonewright_test_user_caps']           = [];
		$GLOBALS['stonewright_test_is_multisite']        = false;
		$GLOBALS['stonewright_test_is_super_admin']      = false;
		$GLOBALS['stonewright_test_companion_responses'] = [];
		$GLOBALS['stonewright_test_companion_requests']  = [];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_options']             = [];
	}

	/**
	 * @return array<string, array{0: \Stonewright\WpMcp\Abilities\AbilityKernel}>
	 */
	public static function code_running_abilities(): array {
		return [
			'php-execute'      => [ new PhpExecute() ],
			'wp-cli-run'       => [ new Run() ],
			'wp-cli-batch-run' => [ new BatchRun() ],
			'wp-cli-job-start' => [ new JobStart() ],
		];
	}

	/**
	 * @dataProvider code_running_abilities
	 */
	public function test_code_running_abilities_need_the_sandbox_capability( \Stonewright\WpMcp\Abilities\AbilityKernel $ability ): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'read' => true ];
		self::assertFalse( $ability->permission_callback( [] ), 'manage_options alone is not enough' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_plugins' => true, 'read' => true ];
		self::assertFalse( $ability->permission_callback( [] ), 'edit_plugins alone is not enough' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_plugins' => true, 'read' => true ];
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	/**
	 * @dataProvider code_running_abilities
	 */
	public function test_code_running_abilities_need_a_super_admin_on_multisite( \Stonewright\WpMcp\Abilities\AbilityKernel $ability ): void {
		$GLOBALS['stonewright_test_is_multisite']   = true;
		$GLOBALS['stonewright_test_is_super_admin'] = false;
		self::assertFalse( $ability->permission_callback( [] ) );
		self::assertFalse( Permissions::can_run_code() );

		$GLOBALS['stonewright_test_is_super_admin'] = true;
		self::assertTrue( $ability->permission_callback( [] ) );
		self::assertTrue( Permissions::can_run_code() );
	}

	public function test_read_only_wp_cli_abilities_keep_the_manage_options_gate(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_is_multisite'] = true;

		foreach ( [ new Status(), new Discover(), new JobStatus() ] as $ability ) {
			self::assertTrue( $ability->permission_callback( [] ), $ability->name() );
		}
	}

	public function test_php_execute_description_does_not_claim_a_guarantee_the_scan_cannot_give(): void {
		$description = ( new PhpExecute() )->description();

		self::assertStringNotContainsString( 'Never writes', $description );
		self::assertStringContainsString( 'best-effort', strtolower( $description ) );
		self::assertStringContainsString( 'edit_plugins', $description );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function refused_commands(): array {
		return [
			'run: plugin install'             => [ Run::class, [ 'command' => [ 'plugin', 'install', 'x', '--activate' ] ] ],
			'run: aliased db query'           => [ Run::class, [ 'command' => [ '@prod', '--path=/srv', 'db', 'query', 'SELECT 1' ] ] ],
			'run: quoted user create'         => [ Run::class, [ 'command' => [ '"user"', "'create'", 'x', 'x@example.test' ] ] ],
			'run: config'                     => [ Run::class, [ 'command' => [ 'config', 'get', 'DB_PASSWORD' ] ] ],
			'batch: second command is a write' => [ BatchRun::class, [ 'commands' => [ [ 'post', 'list' ], [ 'post', 'create', '--post_title=x' ] ] ] ],
			'batch: plugin install'           => [ BatchRun::class, [ 'commands' => [ [ 'plugin', 'install', 'x' ] ] ] ],
			'job: user create'                => [ JobStart::class, [ 'command' => [ 'user', 'create', 'x', 'x@example.test' ] ] ],
			'job: batch with db export'       => [ JobStart::class, [ 'commands' => [ [ 'post', 'list' ], [ 'db', 'export' ] ] ] ],
		];
	}

	/**
	 * @dataProvider refused_commands
	 * @param class-string<\Stonewright\WpMcp\Abilities\AbilityKernel> $class
	 * @param array<string, mixed> $args
	 */
	public function test_refused_commands_never_reach_the_companion( string $class, array $args ): void {
		$result = ( new $class() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wp_cli_command_blocked', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_companion_requests'] );
	}

	public function test_job_start_still_forwards_a_write_that_is_not_denied(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/job-start'] = [ 'ok' => true, 'job_id' => 'wpcli_1', 'status' => 'running' ];

		$result = ( new JobStart() )->execute( [ 'commands' => [ [ 'post', 'create', '--post_title=x' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( 'wpcli_1', $result['job_id'] );
	}

	public function test_run_still_forwards_a_content_write(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/run'] = [ 'ok' => true, 'stdout' => '42', 'stderr' => '', 'exit_code' => 0 ];

		$result = ( new Run() )->execute( [ 'command' => [ 'post', 'create', '--post_title=x' ] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_file_writing_commands_are_refused_when_file_mods_are_disallowed(): void {
		define( 'DISALLOW_FILE_MODS', true );
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true, 'edit_plugins' => true, 'read' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_companion_url' => 'http://127.0.0.1:8765', 'stonewright_companion_token' => 'test-token' ];
		$GLOBALS['stonewright_test_companion_requests'] = [];

		foreach ( [
			[ new Run(), [ 'command' => [ 'i18n', 'make-pot', '.' ] ] ],
			[ new Run(), [ 'command' => [ 'rewrite', 'flush', '--hard' ] ] ],
			[ new JobStart(), [ 'command' => [ 'export', '--dir=/tmp' ] ] ],
			[ new JobStart(), [ 'commands' => [ [ 'post', 'list' ], [ 'maintenance-mode', 'activate' ] ] ] ],
		] as [ $ability, $args ] ) {
			$result = $ability->execute( $args );
			self::assertInstanceOf( \WP_Error::class, $result, $ability->name() );
			self::assertSame( 'stonewright_wp_cli_file_mods_disabled', $result->get_error_code(), $ability->name() );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_companion_requests'] );

		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/run'] = [ 'ok' => true, 'stdout' => '[]', 'stderr' => '', 'exit_code' => 0 ];
		$allowed = ( new Run() )->execute( [ 'command' => [ 'post', 'list' ] ] );
		self::assertIsArray( $allowed );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_php_execute_refuses_code_installers_when_file_mods_are_disallowed(): void {
		define( 'DISALLOW_FILE_MODS', true );
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true, 'edit_plugins' => true, 'read' => true ];
		$GLOBALS['stonewright_test_options']        = [];

		foreach ( [
			'$u = new Plugin_Upgrader(); return $u;',
			'delete_plugins( [ "x/x.php" ] ); return 1;',
			'return unzip_file( "a.zip", "/tmp/x" );',
			'return download_url( "https://example.test/a.zip" );',
		] as $code ) {
			$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );
			self::assertInstanceOf( \WP_Error::class, $result, $code );
			self::assertSame( 'stonewright_php_file_mods_disabled', $result->get_error_code(), $code );
		}

		$ok = ( new PhpExecute() )->execute( [ 'code' => 'return 2 + 2;', 'read_only' => true ] );
		self::assertIsArray( $ok );
		self::assertSame( 4, $ok['result'] );
	}

	public function test_php_execute_allows_code_installer_names_when_file_mods_are_allowed(): void {
		$result = ( new PhpExecute() )->execute( [ 'code' => 'return class_exists( "Plugin_Upgrader" ) ? 1 : 0;', 'read_only' => true ] );

		self::assertIsArray( $result );
	}
}

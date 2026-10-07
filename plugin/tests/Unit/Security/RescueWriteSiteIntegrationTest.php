<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\CustomCode\ProviderOps as CustomCodeProviderOps;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginActivate;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginDeactivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxToggle;
use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\ProviderSupport;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * The risky write paths are armed, probed and rolled back through one small call each.
 *
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 * @covers \Stonewright\WpMcp\Security\Backup
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 */
final class RescueWriteSiteIntegrationTest extends TestCase {

	private string $base;

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	/** @var callable():string */
	private $site_state;

	/** @var mixed */
	private $original_stylesheet_dir;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/sw-integ-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->base . '/uploads', 0700, true );
		mkdir( $this->base . '/themes/site-a', 0700, true );
		$this->original_stylesheet_dir                   = $GLOBALS['stonewright_test_stylesheet_directory'] ?? null;
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->base . '/themes/site-a';
		$GLOBALS['stonewright_test_upload_dir']          = [ 'basedir' => $this->base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']             = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']               = [];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_filters']             = [];
		$GLOBALS['stonewright_test_wpdb_inserts']        = [];
		$GLOBALS['stonewright_test_current_user_id']     = 7;
		$GLOBALS['stonewright_test_user_caps_by_id']     = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->requests = [];
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		if ( null === $this->original_stylesheet_dir ) {
			unset( $GLOBALS['stonewright_test_stylesheet_directory'] );
		} else {
			$GLOBALS['stonewright_test_stylesheet_directory'] = $this->original_stylesheet_dir;
		}
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		self::remove_tree( $this->base );
	}

	/** @param callable():string $state */
	private function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				return match ( ( $this->site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ],
					'unavailable' => new \WP_Error( 'http_request_failed', 'cURL error 7' ),
					'timeout'     => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
					'gateway'     => [ 'response' => [ 'code' => 503 ], 'body' => 'Service Unavailable', 'headers' => [] ],
					default       => [
						'response' => [ 'code' => 200 ],
						'body'     => str_contains( $url, 'wp-json' ) ? '{"namespaces":["wp/v2"]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ),
						'headers'  => [],
					],
				};
			}
		);
	}

	private function post( int $id, string $content ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Fixture',
			'post_content' => $content,
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'fixture',
			'meta'         => [],
		];
	}

	/** An ability the way every Stonewright write is built: through the kernel's audit wrapper. */
	private function ability( callable $body ): AbilityKernel {
		return new class( $body ) extends AbilityKernel {
			/** @var callable */
			private $body;

			public function __construct( callable $body ) {
				$this->body = $body;
			}

			public function name(): string {
				return 'stonewright/rescue-test-write';
			}

			public function label(): string {
				return 'Rescue test write';
			}

			public function description(): string {
				return 'Test double.';
			}

			public function category(): string {
				return 'content';
			}

			public function execute( array $args ): array|\WP_Error {
				return $this->audit_write( $args, $this->body );
			}
		};
	}

	/** @return list<array<string, mixed>> */
	private function audit_rows(): array {
		return array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-test-write' === ( $row['ability_name'] ?? '' )
			)
		);
	}

	// -- AbilityKernel + Backup funnels ----------------------------------------

	public function test_a_post_snapshot_inside_an_ability_call_arms_the_write(): void {
		$this->post( 31, 'original body' );
		$ability = $this->ability(
			function ( array $args ): array {
				Backup::snapshot_post( 31 );
				return [ 'ok' => true ];
			}
		);

		$ability->execute( [] );

		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'stonewright/rescue-test-write', $entry['ability'] );
		self::assertSame( 'post', $entry['resource_type'] );
		self::assertSame( '31', $entry['resource_key'] );
		self::assertSame( 'no_change', $entry['note'], 'Nothing changed, so nothing was probed afterwards.' );
		self::assertCount( 1, $this->requests, 'Only the baseline, taken when the write was armed.' );
	}

	public function test_a_snapshot_taken_outside_an_ability_call_is_not_journaled(): void {
		$this->post( 31, 'original body' );

		Backup::snapshot_post( 31 );
		Backup::snapshot_options( [ 'blogname' ] );

		self::assertSame( [], ChangeJournal::recent() );
	}

	public function test_a_write_that_breaks_the_site_is_rolled_back_and_the_audit_row_tells_the_truth(): void {
		$this->post( 31, 'original body' );
		$this->site( static fn (): string => 'changed body' === $GLOBALS['stonewright_test_posts'][31]->post_content ? 'broken' : 'healthy' );
		$ability = $this->ability(
			function ( array $args ): array {
				Backup::snapshot_post( 31 );
				$GLOBALS['stonewright_test_posts'][31]->post_content = 'changed body';
				return [ 'ok' => true ];
			}
		);

		$result = $ability->execute( [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );

		$rows = $this->audit_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'], 'The audit row records the final outcome, not the write that was undone.' );
		self::assertSame( 'succeeded', $rows[0]['rollback_status'] );
		self::assertSame( 'failed', $rows[0]['verification_status'] );
		self::assertSame( $result->get_error_data()['incident_id'], $rows[0]['change_set_id'] );
	}

	public function test_a_healthy_write_through_the_kernel_returns_its_result_unchanged(): void {
		$this->post( 31, 'original body' );
		$ability = $this->ability(
			function ( array $args ): array {
				Backup::snapshot_post( 31 );
				$GLOBALS['stonewright_test_posts'][31]->post_content = 'changed body';
				return [ 'ok' => true, 'post_id' => 31 ];
			}
		);

		self::assertSame( [ 'ok' => true, 'post_id' => 31 ], $ability->execute( [] ) );
		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'] );
	}

	public function test_an_ability_that_throws_still_closes_its_frame(): void {
		$this->post( 31, 'original body' );
		$thrower = $this->ability(
			function ( array $args ): array {
				Backup::snapshot_post( 31 );
				throw new \RuntimeException( 'boom' );
			}
		);

		$result = $thrower->execute( [] );
		self::assertInstanceOf( \WP_Error::class, $result );

		// A later call is not confused by a frame left open.
		$later = $this->ability( static fn ( array $args ): array => [ 'ok' => true ] );
		self::assertSame( [ 'ok' => true ], $later->execute( [] ) );
	}

	public function test_option_snapshots_inside_an_ability_call_arm_an_option_write(): void {
		update_option( 'blogname', 'Before', false );
		$this->site( static fn (): string => 'After' === get_option( 'blogname' ) ? 'broken' : 'healthy' );
		$ability = $this->ability(
			function ( array $args ): array {
				Backup::snapshot_options( [ 'blogname' ] );
				update_option( 'blogname', 'After', false );
				return [ 'ok' => true ];
			}
		);

		$result = $ability->execute( [] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'Before', get_option( 'blogname' ) );
	}

	// -- Theme file transaction --------------------------------------------------

	/** @return array<string, mixed> */
	private function theme_plan( string $before, string $after, array $extra = [] ): array {
		$path = $this->base . '/themes/site-a/functions.php';
		file_put_contents( $path, $before );
		return array_merge(
			[
				'absolute' => $path,
				'relative' => 'functions.php',
				'before'   => $before,
				'after'    => $after,
				'language' => 'php',
			],
			$extra
		);
	}

	public function test_a_theme_write_that_breaks_the_site_is_restored_and_keeps_its_error_contract(): void {
		$path = $this->base . '/themes/site-a/functions.php';
		$this->site( static fn (): string => str_contains( (string) file_get_contents( $path ), 'broken' ) ? 'broken' : 'healthy' );

		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// broken\n" ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_write_smoke_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertSame( 'failed', $data['smoke_summary']['status'] );
		self::assertSame( 'passed', $data['post_rollback_smoke']['status'] );
		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/', $data['incident_id'] );
		self::assertSame( "<?php\n// ok\n", file_get_contents( $path ), 'The original bytes are back.' );

		$entry = ChangeJournal::get( $data['incident_id'] );
		self::assertSame( 'theme_file', $entry['resource_type'] );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertSame( 'theme_backup', $entry['recipe']['type'] );
		self::assertStringStartsWith( 'sw-theme-backup-', $entry['recipe']['ref'] );
	}

	public function test_a_healthy_theme_write_is_verified_and_reports_its_probe(): void {
		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );

		self::assertIsArray( $result );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'passed', $result['smoke_summary']['status'] );
		self::assertSame( 'passed', $result['site_probe'] );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'verified', $entry['state'] );
		self::assertSame( 'passed', $entry['probe']['status'] );
		self::assertSame( 'passed', $entry['baseline']['status'] );
		self::assertCount( 6, $this->requests, 'PHP changes probe the home page, wp-admin and REST, before the write and after it.' );
	}

	public function test_a_non_php_theme_write_probes_only_the_home_page(): void {
		$path = $this->base . '/themes/site-a/style.css';
		file_put_contents( $path, 'a { color: red; }' );

		ThemeWriteTransaction::apply(
			[
				'absolute' => $path,
				'relative' => 'style.css',
				'before'   => 'a { color: red; }',
				'after'    => 'a { color: blue; }',
				'language' => 'css',
			]
		);

		self::assertCount( 2, $this->requests, 'The home page, before and after.' );
	}

	public function test_an_unavailable_probe_leaves_the_theme_entry_armed_and_never_claims_health(): void {
		$this->site( static fn (): string => 'unavailable' );

		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );

		self::assertIsArray( $result );
		self::assertSame( 'skipped', $result['smoke_summary']['status'] );
		self::assertSame( 'unavailable', $result['site_probe'], 'The result says the site was not checked.' );
		self::assertSame( 'unverified', $result['verification_status'], 'Only a passing check after the write makes it verified.' );
		self::assertFalse( $result['effect_verified'] );
		self::assertTrue( $result['probe_unavailable'] );
		self::assertSame( 'armed', ChangeJournal::recent()[0]['state'], 'The entry is not marked healthy without evidence.' );
		self::assertSame( "<?php\n// ok\n// better\n", file_get_contents( $this->base . '/themes/site-a/functions.php' ), 'And the write is not undone on no evidence.' );
	}

	/** @dataProvider unreachableAfterTheWriteProvider */
	public function test_a_theme_write_that_leaves_a_reachable_site_unreachable_is_restored( string $after_the_write ): void {
		$path = $this->base . '/themes/site-a/functions.php';
		$this->site( static fn (): string => str_contains( (string) file_get_contents( $path ), 'hangs' ) ? $after_the_write : 'healthy' );

		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// hangs\n" ) );

		self::assertInstanceOf( \WP_Error::class, $result, 'A change that hangs or crashes PHP-FPM is not a verified write.' );
		self::assertSame( 'stonewright_theme_write_smoke_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertStringStartsWith( 'degraded_', $data['probe']['legs'][0]['reason'] );
		self::assertSame( "<?php\n// ok\n", file_get_contents( $path ) );
		self::assertSame( 'rolled_back', ChangeJournal::get( $data['incident_id'] )['state'] );
	}

	/** @return array<string, array{string}> */
	public static function unreachableAfterTheWriteProvider(): array {
		return [
			'a refused connection' => [ 'unavailable' ],
			'a timeout'            => [ 'timeout' ],
			'a gateway error'      => [ 'gateway' ],
		];
	}

	public function test_a_write_that_changes_nothing_needs_no_site_check_and_is_verified(): void {
		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n" ) );

		self::assertIsArray( $result );
		self::assertFalse( $result['changed'] );
		self::assertSame( [], $this->requests );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertTrue( $result['effect_verified'] );
	}

	public function test_a_write_the_journal_cannot_describe_is_not_made_unchecked(): void {
		$path = $this->base . '/themes/site-a/functions.php';
		$this->site( static fn (): string => str_contains( (string) file_get_contents( $path ), 'broken' ) ? 'broken' : 'healthy' );
		// A third-party ability whose name the journal cannot hold: arm_standalone() has nothing to return.
		RescueGuard::enter( 'My Plugin/Odd.Name' );

		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// broken\n" ) );
		RescueGuard::leave( $result );

		self::assertInstanceOf( \WP_Error::class, $result, 'It is probed all the same, and a failing site is not left with the change.' );
		self::assertSame( 'stonewright_theme_write_smoke_failed', $result->get_error_code() );
		self::assertSame( "<?php\n// ok\n", file_get_contents( $path ) );
		self::assertSame( [], ChangeJournal::recent(), 'Nothing of it is in the journal.' );
		self::assertSame( '', $result->get_error_data()['incident_id'] );
	}

	public function test_a_healthy_write_the_journal_cannot_describe_is_verified_by_its_own_check(): void {
		RescueGuard::enter( 'My Plugin/Odd.Name' );

		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// better\n" ) );
		RescueGuard::leave( $result );

		self::assertIsArray( $result );
		self::assertSame( 'passed', $result['site_probe'] );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertCount( 6, $this->requests );
	}
	public function test_a_file_the_write_creates_can_be_taken_away_again(): void {
		$path = $this->base . '/themes/site-a/inc/new.php';
		mkdir( dirname( $path ), 0700, true );
		$this->site( static fn (): string => is_file( $path ) ? 'broken' : 'healthy' );

		$result = ThemeWriteTransaction::apply(
			[
				'absolute' => $path,
				'relative' => 'inc/new.php',
				'before'   => '',
				'after'    => "<?php\n// new\n",
				'language' => 'php',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertFileDoesNotExist( $path );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertStringStartsWith( 'absent:', $entry['recipe']['ref'] );
	}

	public function test_skipping_the_smoke_leaves_the_entry_armed(): void {
		$result = ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// better\n", [ 'skip_smoke' => true ] ) );

		self::assertIsArray( $result );
		self::assertSame( [], $this->requests, 'No baseline either.' );
		self::assertSame( 'armed', ChangeJournal::recent()[0]['state'] );
		self::assertSame( 'skipped', $result['site_probe'] );
		self::assertSame( 'unverified', $result['verification_status'] );
		self::assertFalse( $result['effect_verified'] );
	}

	public function test_the_journal_entry_names_the_file_the_write_touched(): void {
		ThemeWriteTransaction::apply( $this->theme_plan( "<?php\n// ok\n", "<?php\n// ok\n// better\n", [ 'skip_smoke' => true ] ) );

		$raw = $this->journal_file();
		self::assertSame( 'theme_file', $raw['entries'][0]['resource_type'] );
		self::assertSame( 'functions.php', $raw['entries'][0]['resource_key'] );
		// The test theme lives outside ABSPATH, so no path can be expressed relative to it.
		self::assertSame( [], $raw['entries'][0]['paths'] );
	}

	// -- Plugin, sandbox and custom-code write sites ------------------------------

	public function test_activating_a_plugin_that_breaks_the_site_is_undone(): void {
		$this->site( static fn (): string => isset( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] ) ? 'broken' : 'healthy' );

		$result = ( new PluginActivate() )->execute( [ 'plugin' => 'hello/hello.php' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertArrayNotHasKey( 'hello/hello.php', $GLOBALS['stonewright_test_active_plugins'] );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'stonewright/plugin-activate', $entry['ability'] );
		self::assertSame( 'plugin', $entry['resource_type'] );
		self::assertSame( 'hello/hello.php', $entry['resource_key'] );
	}

	public function test_a_plugin_activation_that_leaves_the_site_healthy_is_verified(): void {
		$result = ( new PluginActivate() )->execute( [ 'plugin' => 'hello/hello.php' ] );

		self::assertSame( [ 'plugin' => 'hello/hello.php', 'active' => false ], $result, 'The ability result is untouched (the stub reports the plugin inactive).' );
		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'] );
	}

	public function test_deactivating_a_plugin_arms_the_write_with_its_previous_state(): void {
		( new PluginDeactivate() )->execute( [ 'plugin' => 'hello/hello.php' ] );

		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'stonewright/plugin-deactivate', $entry['ability'] );
		self::assertSame( 'plugin_state', $entry['recipe']['type'] );
		self::assertArrayHasKey( 'was_active', $entry['recipe_detail'] );
	}

	public function test_activating_a_sandbox_file_that_breaks_the_site_disables_it_again(): void {
		$name = 'rescue-integration.php';
		SandboxFiles::write( $name, "<?php\n// harmless\n" );
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		$this->site( static fn (): string => file_exists( $twin ) ? 'broken' : 'healthy' );

		$result = ( new SandboxActivate() )->execute( [ 'name' => $name ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertFileDoesNotExist( $twin );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'sandbox', $entry['resource_type'] );
		self::assertSame( $name, $entry['resource_key'] );
		foreach ( [ $twin . '.disabled', SandboxFiles::stored_path( $name ) ] as $file ) {
			@unlink( $file );
		}
	}

	public function test_re_enabling_a_sandbox_file_is_armed_but_disabling_one_is_not(): void {
		$name = 'rescue-toggle.php';
		SandboxFiles::write( $name, "<?php\n// harmless\n" );
		SandboxFiles::activate( $name );
		ChangeJournal::reset_for_tests();

		( new SandboxToggle() )->execute( [ 'name' => $name, 'action' => 'disable' ] );
		self::assertSame( [], ChangeJournal::recent(), 'Switching a file off cannot break the site.' );

		( new SandboxToggle() )->execute( [ 'name' => $name, 'action' => 'enable' ] );
		self::assertSame( 'sandbox', ChangeJournal::recent()[0]['resource_type'] );

		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		foreach ( [ $twin, $twin . '.disabled', SandboxFiles::stored_path( $name ) ] as $file ) {
			@unlink( $file );
		}
	}

	public function test_a_custom_code_snippet_save_that_breaks_the_site_is_rolled_back_by_its_provider(): void {
		$provider = new class() implements ProviderInterface {
			public int $rollbacks = 0;

			public function id(): string {
				return 'wpcode';
			}

			public function label(): string {
				return 'Fake';
			}

			public function discover(): array {
				return [];
			}

			public function list( array $args = [] ) {
				return [];
			}

			public function read( string $target_id ) {
				return [];
			}

			public function dry_run( array $args ) {
				return [];
			}

			public function apply( array $args ) {
				$snapshot = ProviderSupport::snapshot_record( 'wpcode', (string) $args['target_id'], 'wpcode/snippet/12', 'before' );
				return [ 'ok' => true, 'applied' => true, 'snapshot_id' => $snapshot['snapshot_id'] ];
			}

			public function verify( array $args ) {
				return [];
			}

			public function rollback( array $args ) {
				++$this->rollbacks;
				return [ 'ok' => true, 'effect_verified' => true ];
			}
		};
		ProviderRegistry::set_for_tests( [ 'wpcode' => $provider ] );
		$this->site( static fn (): string => 0 === $provider->rollbacks ? 'broken' : 'healthy' );

		$result = ( new CustomCodeProviderOps() )->execute( [ 'action' => 'apply', 'provider' => 'wpcode', 'target_id' => '12', 'code' => '<?php // x' ] );
		ProviderRegistry::reset_for_tests();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 1, $provider->rollbacks );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'custom_code', $entry['resource_type'] );
		self::assertSame( 'wpcode', $entry['recipe_detail']['provider'] );
		self::assertNotSame( '', $entry['recipe_detail']['snapshot_id'] );
	}

	public function test_theme_file_and_css_providers_are_not_armed_twice(): void {
		$provider = new class() implements ProviderInterface {
			public function id(): string {
				return 'customizer-css';
			}

			public function label(): string {
				return 'Fake';
			}

			public function discover(): array {
				return [];
			}

			public function list( array $args = [] ) {
				return [];
			}

			public function read( string $target_id ) {
				return [];
			}

			public function dry_run( array $args ) {
				return [];
			}

			public function apply( array $args ) {
				return [ 'ok' => true ];
			}

			public function verify( array $args ) {
				return [];
			}

			public function rollback( array $args ) {
				return [];
			}
		};
		ProviderRegistry::set_for_tests( [ 'customizer-css' => $provider ] );

		( new CustomCodeProviderOps() )->execute( [ 'action' => 'apply', 'provider' => 'customizer-css', 'target_id' => 'x', 'code' => 'a{}' ] );
		ProviderRegistry::reset_for_tests();

		self::assertSame( [], ChangeJournal::recent(), 'CSS cannot fatal, and the theme-file provider journals itself through the theme transaction.' );
	}

	/** @return array<string, mixed> */
	private function journal_file(): array {
		$name = (string) get_option( ChangeJournal::FILE_OPTION, '' );
		return json_decode( (string) file_get_contents( $this->base . '/uploads/stonewright-state/' . $name ), true );
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}

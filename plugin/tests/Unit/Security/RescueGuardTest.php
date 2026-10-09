<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * Arm, probe, roll back: what happens around a risky write inside one ability call.
 *
 * The fake site answers from the real state of the world (a post body, an active plugin, an
 * option), so a rollback that really restores that state is what makes it healthy again.
 *
 * @covers \Stonewright\WpMcp\Security\RescueGuard
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 */
final class RescueGuardTest extends TestCase {

	private string $uploads;

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	/** @var callable():string */
	private $site_state;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-guard-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_active_plugins']  = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->requests = [];
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		AgentNotices::reset_for_tests();
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RollbackRecipes::set_provider_resolver( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		self::remove_tree( $this->uploads );
	}

	/**
	 * @param callable():string $state healthy, broken or unavailable, evaluated for every request.
	 */
	private function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				return match ( ( $this->site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"><p>There has been a critical error on this website.</p></body>', 'headers' => [] ],
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

	/** Arms a post write the way Backup::snapshot_post does, then changes the post. */
	private function armed_post_change( int $id = 31 ): string {
		$this->post( $id, 'original body' );
		$snapshot = Backup::snapshot_post( $id );
		RescueGuard::arm_post_write( $id, $snapshot );
		$GLOBALS['stonewright_test_posts'][ $id ]->post_content = 'changed body';
		return $snapshot;
	}

	private static function post_is_changed( int $id = 31 ): string {
		return 'changed body' === ( $GLOBALS['stonewright_test_posts'][ $id ]->post_content ?? '' ) ? 'broken' : 'healthy';
	}

	public function test_nothing_is_armed_outside_an_ability_call(): void {
		$this->post( 31, 'body' );

		self::assertNull( RescueGuard::arm_post_write( 31, Backup::snapshot_post( 31 ) ) );
		self::assertSame( [], ChangeJournal::recent() );
		self::assertSame( [ 'ok' => true ], RescueGuard::leave( [ 'ok' => true ] ) );
	}

	public function test_a_healthy_post_write_is_verified_and_the_result_is_untouched(): void {
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true, 'applied' => 1 ] );

		self::assertSame( [ 'ok' => true, 'applied' => 1 ], $result );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'verified', $entry['state'] );
		self::assertSame( 'passed', $entry['probe']['status'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $entry['ability'] );
		self::assertSame( 'post', $entry['resource_type'] );
		self::assertSame( '31', $entry['resource_key'] );
		self::assertSame( 'post_snapshot', $entry['recipe']['type'] );
		self::assertSame( 7, $entry['actor'] );
	}

	public function test_a_post_write_probes_the_post_page_and_nothing_else(): void {
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		RescueGuard::leave( [ 'ok' => true ] );

		self::assertCount( 2, $this->requests, 'One baseline before the write, one probe after it.' );
		foreach ( $this->requests as $request ) {
			self::assertStringContainsString( '?p=31', $request['url'] );
		}
	}

	public function test_a_failing_probe_rolls_the_post_back_and_returns_a_structured_error(): void {
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true, 'applied' => 1 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/', $data['incident_id'] );
		self::assertSame( $data['incident_id'], $data['change_set_id'] );
		self::assertSame( 'healthy', $data['site_status'] );
		self::assertSame( 'failed', $data['probe']['status'] );
		self::assertSame( 'critical_error_page', $data['probe']['legs'][0]['reason'] );
		self::assertSame( 500, $data['status'] );
		self::assertFalse( $data['retryable'] );

		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'The post is back as it was.' );
		$entry = ChangeJournal::get( $data['incident_id'] );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertSame( 'succeeded', $entry['rollback']['status'] );
		self::assertSame( 'auto', $entry['rollback']['by'] );
		self::assertSame( 'healthy', $entry['rollback']['site'] );
		self::assertNull( ChangeJournal::banner() );
		self::assertCount( 3, $this->requests, 'The baseline, the probe that failed, and the probe after the rollback.' );
	}

	public function test_the_error_message_carries_the_evidence_for_clients_that_only_read_the_message(): void {
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		$message = $result->get_error_message();
		self::assertLessThan( 900, strlen( $message ) );
		self::assertStringContainsString( 'rolled this change back', $message );
		self::assertSame( 1, preg_match( '/(\{"rollback_status".*\})\s*$/', $message, $match ), 'The message ends with compact JSON.' );
		$json = json_decode( $match[1], true );
		self::assertSame( 'succeeded', $json['rollback_status'] );
		self::assertSame( $result->get_error_data()['incident_id'], $json['incident_id'] );
		self::assertSame( 'critical_error_page', $json['probe']['legs'][0]['reason'] );
		self::assertStringNotContainsString( 'example.test', $message );
	}

	public function test_the_code_of_a_rolled_back_write_survives_the_message_only_view_of_an_mcp_client(): void {
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();
		$result = RescueGuard::leave( [ 'ok' => true ] );

		$message = \Stonewright\WpMcp\Support\ErrorEnvelope::with_agent_visible_payload( $result )->get_error_message();

		$data = $result->get_error_data();
		self::assertStringContainsString( '"code":"stonewright_rescue_write_rolled_back"', $message );
		self::assertStringContainsString( '"site_status":"healthy"', $message );
		self::assertStringContainsString( '"change_set_id":"' . $data['change_set_id'] . '"', $message );
		self::assertStringNotContainsString( 'example.test', $message );
		self::assertStringNotContainsString( 'resource_type', $message );
	}

	public function test_a_site_that_still_fails_after_the_rollback_is_reported_as_such(): void {
		$this->site( static fn (): string => 'broken' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'still_failing', $data['site_status'] );
		self::assertStringContainsString( 'still fails', $result->get_error_message() );
		self::assertTrue( ChangeJournal::get( $data['incident_id'] )['residual'] );
	}

	public function test_a_rollback_that_cannot_run_leaves_an_open_incident_and_says_so(): void {
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();
		// Lose the snapshot the recipe needs.
		delete_post_meta( 31, '_stonewright_backups' );

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( 'stonewright_rescue_rollback_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'failed', $data['rollback_status'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $data['incident_id'] )['state'] );
		self::assertSame( $data['incident_id'], ChangeJournal::banner()['id'] );
		self::assertStringContainsString( 'stonewright-rescue-rollback', $result->get_error_message() );
	}

	public function test_an_unavailable_probe_keeps_the_entry_armed_and_leaves_a_notice_instead_of_a_verdict(): void {
		$this->site( static fn (): string => 'unavailable' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result, 'The write is not undone on no evidence.' );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'armed', $entry['state'] );
		self::assertSame( 'unavailable', $entry['probe']['status'] );
		$notices = AgentNotices::fields()['notices'] ?? [];
		self::assertCount( 1, $notices );
		self::assertStringContainsString( 'not verified', $notices[0] );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_the_unavailable_notice_is_cleared_as_soon_as_a_probe_passes(): void {
		$state = 'unavailable';
		$this->site( static function () use ( &$state ): string {
			return $state;
		} );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();
		RescueGuard::leave( [ 'ok' => true ] );
		self::assertCount( 1, AgentNotices::fields()['notices'] ?? [], 'The host could not call itself.' );

		$state = 'healthy';
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change( 32 );
		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result );
		$states = array_column( ChangeJournal::recent(), 'state', 'resource_key' );
		self::assertSame( 'verified', $states['32'] );
		self::assertArrayNotHasKey( 'notices', AgentNotices::fields(), 'The notice does not outlive the recovery.' );
	}

	public function test_a_probe_that_fails_or_cannot_run_keeps_the_unavailable_notice(): void {
		$this->site( static fn (): string => 'unavailable' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();
		RescueGuard::leave( [ 'ok' => true ] );

		$this->site( static fn (): string => 'timeout' );
		HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertCount( 1, AgentNotices::fields()['notices'] ?? [] );
	}

	public function test_a_call_that_changed_nothing_is_not_probed(): void {
		$this->post( 31, 'body' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		RescueGuard::arm_post_write( 31, Backup::snapshot_post( 31 ) );

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result );
		self::assertCount( 1, $this->requests, 'Only the baseline, taken when the write was armed. No change, nothing to verify afterwards.' );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'verified', $entry['state'] );
		self::assertSame( 'no_change', $entry['note'] );
	}

	public function test_a_failed_call_that_changed_nothing_is_not_probed(): void {
		RescueGuard::enter( 'stonewright/theme-chrome-update' );
		RescueGuard::arm_option_write( [ 'blogname' ], Backup::snapshot_options( [ 'blogname' ] ) );
		$error = new \WP_Error( 'stonewright_invalid', 'nope' );

		$result = RescueGuard::leave( $error );

		self::assertSame( $error, $result );
		self::assertCount( 1, $this->requests, 'Only the home page baseline; nothing was checked afterwards.' );
	}

	public function test_two_writes_in_one_call_share_one_probe(): void {
		RescueGuard::enter( 'stonewright/design-apply-to-post' );
		$this->armed_post_change( 31 );
		$this->post( 32, 'second original' );
		RescueGuard::arm_post_write( 32, Backup::snapshot_post( 32 ) );
		$GLOBALS['stonewright_test_posts'][32]->post_content = 'second changed';

		RescueGuard::leave( [ 'ok' => true ] );

		self::assertCount( 2, $this->requests, 'One baseline (the first post) and one verdict that covers both writes.' );
		self::assertCount( 2, array_filter( ChangeJournal::recent(), static fn ( array $e ): bool => 'verified' === $e['state'] ) );
	}

	public function test_writing_the_same_post_twice_in_one_call_keeps_the_first_snapshot(): void {
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->post( 31, 'original body' );
		$first = Backup::snapshot_post( 31 );
		RescueGuard::arm_post_write( 31, $first );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'intermediate';
		RescueGuard::arm_post_write( 31, Backup::snapshot_post( 31 ) );

		self::assertCount( 1, ChangeJournal::recent() );
		self::assertSame( $first, ChangeJournal::recent()[0]['recipe']['ref'] );
		RescueGuard::leave( [ 'ok' => true ] );
	}

	public function test_site_level_writes_probe_the_whole_site(): void {
		RescueGuard::enter( 'stonewright/plugin-activate' );
		RescueGuard::arm_plugin_write( 'hello/hello.php', false );

		RescueGuard::leave( [ 'plugin' => 'hello/hello.php', 'active' => true ] );

		$urls = array_column( $this->requests, 'url' );
		self::assertCount( 6, $urls, 'Three legs before the write and three after it.' );
		self::assertNotEmpty( array_filter( $urls, static fn ( string $u ): bool => str_contains( $u, 'wp-json' ) ) );
		self::assertNotEmpty( array_filter( $urls, static fn ( string $u ): bool => str_contains( $u, 'stonewright-rescue' ) ) );
		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'] );
	}

	public function test_a_plugin_activation_that_breaks_the_site_is_deactivated_again(): void {
		$this->site( static fn (): string => isset( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] ) ? 'broken' : 'healthy' );
		RescueGuard::enter( 'stonewright/plugin-activate' );
		RescueGuard::arm_plugin_write( 'hello/hello.php', false );
		$GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] = true;

		$result = RescueGuard::leave( [ 'plugin' => 'hello/hello.php', 'active' => true ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertArrayNotHasKey( 'hello/hello.php', $GLOBALS['stonewright_test_active_plugins'] );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'plugin', $entry['resource_type'] );
		self::assertSame( 'plugin_state', $entry['recipe']['type'] );
		self::assertFalse( $entry['recipe_detail']['was_active'] );
	}

	public function test_a_plugin_deactivation_that_breaks_the_site_is_activated_again(): void {
		$this->site( static fn (): string => isset( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] ) ? 'healthy' : 'broken' );
		RescueGuard::enter( 'stonewright/plugin-deactivate' );
		RescueGuard::arm_plugin_write( 'hello/hello.php', true );

		$result = RescueGuard::leave( [ 'plugin' => 'hello/hello.php', 'active' => false ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertTrue( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] );
	}

	public function test_option_writes_are_probed_and_restored(): void {
		$this->site( static fn (): string => 'After' === get_option( 'blogname' ) ? 'broken' : 'healthy' );
		update_option( 'blogname', 'Before', false );
		RescueGuard::enter( 'stonewright/theme-chrome-update' );
		RescueGuard::arm_option_write( [ 'blogname' ], Backup::snapshot_options( [ 'blogname' ] ) );
		update_option( 'blogname', 'After', false );

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'Before', get_option( 'blogname' ) );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'option', $entry['resource_type'] );
		self::assertSame( 'option_restore', $entry['recipe']['type'] );
		self::assertSame( 'blogname', $entry['resource_key'] );
		self::assertCount( 3, $this->requests, 'Option writes are probed on the home page: the baseline, the failing probe, and the check after the rollback.' );
	}

	public function test_a_sandbox_activation_that_breaks_the_site_is_disabled_again(): void {
		$name = 'rescue-guard-test.php';
		SandboxFiles::write( $name, "<?php\n// harmless\n" );
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		$this->site( static fn (): string => file_exists( $twin ) ? 'broken' : 'healthy' );
		RescueGuard::enter( 'stonewright/sandbox-activate' );
		RescueGuard::arm_sandbox_write( $name );
		SandboxFiles::activate( $name );

		$result = RescueGuard::leave( [ 'ok' => true, 'name' => $name ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertFileDoesNotExist( $twin );
		foreach ( [ $twin . '.disabled', SandboxFiles::stored_path( $name ) ] as $file ) {
			@unlink( $file );
		}
	}

	public function test_a_custom_code_snippet_save_that_breaks_the_site_is_rolled_back_through_its_provider(): void {
		$calls = [];
		RollbackRecipes::set_provider_resolver(
			static function ( string $id ) use ( &$calls ): object {
				return new class( $calls ) {
					public function __construct( private array &$calls ) {}

					/** @return array<string, mixed> */
					public function rollback( array $args ): array {
						$this->calls[] = $args;
						return [ 'ok' => true, 'effect_verified' => true ];
					}
				};
			}
		);
		$this->site( static function () use ( &$calls ): string {
			return [] === $calls ? 'broken' : 'healthy';
		} );
		RescueGuard::enter( 'stonewright/custom-code-provider' );
		RescueGuard::arm_custom_code_write( 'wpcode', '12' );
		RescueGuard::note_provider_snapshot( 'wpcode', '12', 'snap-77' );

		$result = RescueGuard::leave( [ 'ok' => true, 'applied' => true ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( [ [ 'snapshot_id' => 'snap-77', 'target_id' => '12' ] ], $calls );
		$entry = ChangeJournal::get( $result->get_error_data()['incident_id'] );
		self::assertSame( 'custom_code', $entry['resource_type'] );
		self::assertSame( 'none', $entry['recipe']['type'], 'The shared file contract has no provider recipe type.' );
		self::assertSame( 'wpcode', $entry['recipe_detail']['provider'] );
	}

	public function test_a_write_that_settles_itself_is_not_the_calls_business(): void {
		RescueGuard::enter( 'stonewright/theme-file-patch' );
		$entry = RescueGuard::arm_standalone(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ],
				'scope'         => 'site',
			]
		);

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertNotNull( $entry );
		self::assertSame( [ 'ok' => true ], $result );
		self::assertCount( 3, $this->requests, 'Only the baseline, taken when it was armed; the write settles itself.' );
		self::assertSame( 'armed', ChangeJournal::get( $entry['id'] )['state'] );
	}

	public function test_an_entry_can_be_verified_from_memory_when_the_journal_could_not_keep_it(): void {
		$this->site( static fn (): string => 'broken' );
		$rolled = 0;
		$entry  = [
			'id'            => 'cs-memory',
			'ability'       => 'stonewright/theme-file-patch',
			'resource_type' => 'theme_file',
			'resource_key'  => 'functions.php',
			'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ],
			'recipe_detail' => [],
			'scope'         => 'light',
			'actor'         => 7,
			'state'         => 'armed',
		];

		$verdict = RescueGuard::verify_or_rollback(
			$entry,
			[
				'rollback' => static function () use ( &$rolled ): array {
					++$rolled;
					return [ 'status' => 'succeeded', 'recipe' => 'theme_backup', 'detail' => '' ];
				},
			]
		);

		self::assertSame( 1, $rolled, 'The in-process rollback ran although the journal has no such entry.' );
		self::assertSame( 'rolled_back', $verdict['status'] );
		self::assertSame( 'still_failing', $verdict['site_status'] );
	}

	public function test_a_nested_call_settles_only_its_own_entries(): void {
		RescueGuard::enter( 'stonewright/outer' );
		$this->armed_post_change( 31 );
		RescueGuard::enter( 'stonewright/inner' );
		$this->post( 32, 'inner original' );
		RescueGuard::arm_post_write( 32, Backup::snapshot_post( 32 ) );
		$GLOBALS['stonewright_test_posts'][32]->post_content = 'inner changed';

		RescueGuard::leave( [ 'ok' => true ] );

		$states = [];
		foreach ( ChangeJournal::recent() as $entry ) {
			$states[ $entry['resource_key'] ] = $entry['state'];
		}
		self::assertSame( 'verified', $states['32'] );
		self::assertSame( 'armed', $states['31'], 'The outer call has not finished.' );

		RescueGuard::leave( [ 'ok' => true ] );
		$states = [];
		foreach ( ChangeJournal::recent() as $entry ) {
			$states[ $entry['resource_key'] ] = $entry['state'];
		}
		self::assertSame( 'verified', $states['31'] );
	}

	public function test_when_the_call_failed_and_the_site_broke_the_original_error_is_kept_as_context(): void {
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( new \WP_Error( 'stonewright_partial', 'Partly applied.' ) );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'stonewright_partial', $result->get_error_data()['original_error_code'] );
	}

	public function test_a_journal_that_cannot_be_written_never_blocks_the_protection(): void {
		file_put_contents( $this->uploads . '/stonewright-state', 'a file where the directory should be' );
		$this->site( static fn (): string => self::post_is_changed() );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_nothing_the_guard_does_can_throw_into_the_ability(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_probe_enabled'] = static function (): bool {
			throw new \RuntimeException( 'a hostile filter' );
		};
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result );
	}

	// -- The baseline: what the site did before the write --------------------------------------

	public function test_the_site_is_probed_before_the_write_and_the_result_is_kept_on_the_entry(): void {
		$seen = [];
		$this->site(
			static function () use ( &$seen ): string {
				$seen[] = self::post_is_changed();
				return 'healthy';
			}
		);
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		self::assertSame( [ 'healthy' ], $seen, 'One request went out when the write was armed, before the post changed.' );
		RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'healthy', 'broken' ], $seen, 'And one after the write.' );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'passed', $entry['baseline']['status'] );
		self::assertSame( [ 'post' ], array_column( $entry['baseline']['legs'], 'leg' ) );
	}

	/** @dataProvider unreachableAfterTheWriteProvider */
	public function test_a_site_that_cannot_be_reached_after_a_write_but_could_before_it_is_rolled_back( string $after_the_write ): void {
		$this->site( static fn (): string => 'broken' === self::post_is_changed() ? $after_the_write : 'healthy' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true, 'applied' => 1 ] );

		self::assertInstanceOf( \WP_Error::class, $result, 'A change that hangs or crashes the server must not be kept for want of an answer.' );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'healthy', $data['site_status'] );
		self::assertSame( 'failed', $data['probe']['status'] );
		self::assertStringStartsWith( 'degraded_', $data['probe']['legs'][0]['reason'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
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

	/**
	 * A site that answers 'timeout' to the first request after the write and 'healthy' to the rest.
	 *
	 * @return callable():string
	 */
	private function slow_first_render( int &$asked_after_write, string $first_reply = 'timeout', string $then = 'healthy' ): callable {
		return static function () use ( &$asked_after_write, $first_reply, $then ): string {
			if ( 'broken' !== self::post_is_changed() ) {
				return 'healthy';
			}
			return 1 === ++$asked_after_write ? $first_reply : $then;
		};
	}

	public function test_a_post_leg_that_passed_before_and_is_slow_to_answer_after_the_write_is_asked_once_more_and_kept(): void {
		$asked = 0;
		$this->site( $this->slow_first_render( $asked ) );
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true, 'applied' => 1 ] );

		self::assertSame( [ 'ok' => true, 'applied' => 1 ], $result, 'A first render that is slow is not a crash: the healthy write stays.' );
		self::assertSame( 2, $asked, 'One request, then one more.' );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'verified', $entry['state'] );
		self::assertSame( 'passed', $entry['probe']['status'] );
		self::assertTrue( $entry['probe']['legs'][0]['retried'] );
		self::assertSame( 'http_request_failed', $entry['probe']['legs'][0]['first_reason'] );
		self::assertSame( 15, end( $this->requests )['args']['timeout'] );
	}

	public function test_a_post_leg_that_gets_no_answer_twice_is_rolled_back_as_before(): void {
		$asked = 0;
		$this->site( $this->slow_first_render( $asked, 'timeout', 'timeout' ) );
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 2, $asked, 'One more attempt, no more.' );
		self::assertSame( 'failed', $data['probe']['status'] );
		self::assertStringStartsWith( 'degraded_', $data['probe']['legs'][0]['reason'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'healthy', $data['site_status'] );
	}

	public function test_a_server_error_after_the_write_rolls_it_back_at_once_with_one_request(): void {
		$asked = 0;
		$this->site( $this->slow_first_render( $asked, 'broken', 'healthy' ) );
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( 1, $asked, 'A crash is not asked about twice.' );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_leg_that_did_not_pass_before_and_gets_no_answer_is_neither_asked_again_nor_rolled_back(): void {
		$this->site( static fn (): string => 'timeout' );
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$this->armed_post_change();
		$before = count( $this->requests );

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result );
		self::assertSame( 1, count( $this->requests ) - $before, 'Just the one request after the write.' );
		self::assertSame( 'armed', ChangeJournal::recent()[0]['state'] );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_draft_page_that_is_slow_to_render_is_asked_again_with_a_fresh_token(): void {
		$asked = 0;
		$this->site( $this->slow_first_render( $asked ) );
		$this->post( 31, 'original body' );
		$GLOBALS['stonewright_test_posts'][31]->post_status = 'draft';
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$snapshot = Backup::snapshot_post( 31 );
		RescueGuard::arm_post_write( 31, $snapshot );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'changed body';

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result );
		$after = array_slice( $this->requests, -2 );
		self::assertCount( 2, $after );
		$first  = (string) ( $after[0]['args']['headers'][ \Stonewright\WpMcp\Security\ProbeToken::HEADER ] ?? '' );
		$second = (string) ( $after[1]['args']['headers'][ \Stonewright\WpMcp\Security\ProbeToken::HEADER ] ?? '' );
		self::assertNotSame( '', $first );
		self::assertNotSame( '', $second );
		self::assertNotSame( $first, $second );
		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'] );
	}

	public function test_a_probe_in_quick_mode_is_not_asked_again(): void {
		$GLOBALS['stonewright_test_transients'][ HealthProbe::COOLDOWN_KEY ] = 1;
		$asked = 0;
		$this->site( $this->slow_first_render( $asked, 'timeout', 'timeout' ) );
		RescueGuard::enter( 'stonewright/elementor-build-tree' );
		$this->armed_post_change();
		$GLOBALS['stonewright_test_transients'][ HealthProbe::COOLDOWN_KEY ] = 1;

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 1, $asked, 'A host that cannot call itself is not made to wait twice.' );
	}

	public function test_a_site_that_still_cannot_be_reached_after_the_rollback_is_reported_as_failing(): void {
		$this->site( static fn (): string => [] === $GLOBALS['stonewright_test_phase'] ? 'healthy' : 'gateway' );
		$GLOBALS['stonewright_test_phase'] = [];
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();
		$GLOBALS['stonewright_test_phase'] = [ 'after' ];

		$result = RescueGuard::leave( [ 'ok' => true ] );
		unset( $GLOBALS['stonewright_test_phase'] );

		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'still_failing', $data['site_status'], 'It answered before the write and does not now: that is failing, not unknown.' );
		self::assertTrue( ChangeJournal::get( $data['incident_id'] )['residual'] );
	}

	public function test_when_the_site_could_not_be_reached_before_the_write_either_the_entry_stays_armed(): void {
		$this->site( static fn (): string => 'gateway' );
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result, 'No baseline, no verdict: the write is not undone on no evidence.' );
		$entry = ChangeJournal::recent()[0];
		self::assertSame( 'armed', $entry['state'] );
		self::assertSame( 'unavailable', $entry['baseline']['status'] );
		self::assertSame( 'unavailable', $entry['probe']['status'] );
		self::assertStringContainsString( 'not verified', AgentNotices::fields()['notices'][0] );
		self::assertSame( 'changed body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_site_that_answers_before_and_after_is_verified(): void {
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$this->armed_post_change();

		RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( 'verified', ChangeJournal::recent()[0]['state'] );
	}

	public function test_the_baseline_is_taken_once_per_leg_in_one_call_and_only_for_the_first_post(): void {
		RescueGuard::enter( 'stonewright/design-apply-to-post' );
		$this->armed_post_change( 31 );
		$this->post( 32, 'second original' );
		RescueGuard::arm_post_write( 32, Backup::snapshot_post( 32 ) );

		self::assertCount( 1, $this->requests, 'The probe checks the first post, so only the first post needs a baseline.' );
		self::assertStringContainsString( '?p=31', $this->requests[0]['url'] );
		$second = array_values( array_filter( ChangeJournal::recent(), static fn ( array $e ): bool => '32' === $e['resource_key'] ) )[0];
		self::assertTrue( null === $second['baseline'] || [] === $second['baseline']['legs'], 'The second post has no baseline of its own.' );

		Backup::snapshot_options( [ 'blogname' ] );
		RescueGuard::arm_option_write( [ 'blogname' ], Backup::snapshot_options( [ 'blogname' ] ) );
		RescueGuard::arm_option_write( [ 'blogdescription' ], Backup::snapshot_options( [ 'blogdescription' ] ) );
		self::assertCount( 2, $this->requests, 'Two option writes share one home page baseline.' );
		RescueGuard::leave( [ 'ok' => true ] );
	}

	public function test_the_post_baseline_is_never_used_for_a_different_post(): void {
		$this->site( static fn (): string => 'second changed' === ( $GLOBALS['stonewright_test_posts'][32]->post_content ?? '' ) ? 'gateway' : 'healthy' );
		RescueGuard::enter( 'stonewright/design-apply-to-post' );
		// The first post is armed but not changed; it is the one whose page the baseline asked for.
		$this->post( 31, 'body' );
		RescueGuard::arm_post_write( 31, Backup::snapshot_post( 31 ) );
		$this->post( 32, 'second original' );
		RescueGuard::arm_post_write( 32, Backup::snapshot_post( 32 ) );
		$GLOBALS['stonewright_test_posts'][32]->post_content = 'second changed';

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ 'ok' => true ], $result, 'Nothing is known about how post 32 answered before, so no verdict is made.' );
		$states = [];
		foreach ( ChangeJournal::recent() as $entry ) {
			$states[ $entry['resource_key'] ] = $entry['state'];
		}
		ksort( $states );
		self::assertSame( [ 31 => 'verified', 32 => 'armed' ], $states );
	}

	public function test_a_site_scope_write_takes_a_baseline_of_every_leg_it_will_check(): void {
		RescueGuard::enter( 'stonewright/plugin-activate' );

		RescueGuard::arm_plugin_write( 'hello/hello.php', false );

		self::assertCount( 3, $this->requests );
		self::assertSame( [ 'home', 'admin', 'rest' ], array_column( ChangeJournal::recent()[0]['baseline']['legs'], 'leg' ) );
		RescueGuard::leave( [ 'ok' => true ] );
	}

	public function test_a_write_that_settles_itself_takes_its_baseline_when_it_is_armed(): void {
		$spec = [
			'ability'       => 'stonewright/theme-file-patch',
			'resource_type' => 'theme_file',
			'resource_key'  => 'functions.php',
			'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ],
			'scope'         => 'light',
		];

		$entry = RescueGuard::arm_standalone( $spec );

		self::assertSame( 'passed', $entry['baseline']['status'] );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'passed', ChangeJournal::get( $entry['id'] )['baseline']['status'], 'It is kept in the journal too.' );

		$this->requests = [];
		$quiet = RescueGuard::arm_standalone( $spec, [ 'baseline' => false ] );
		self::assertNull( $quiet['baseline'] );
		self::assertSame( [], $this->requests );
	}

	public function test_a_custom_url_is_part_of_the_baseline_when_the_write_asks_for_it(): void {
		$entry = RescueGuard::arm_standalone(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ],
				'scope'         => 'light',
			],
			[ 'url' => 'https://example.test/landing/' ]
		);

		self::assertSame( [ 'home', 'custom' ], array_column( $entry['baseline']['legs'], 'leg' ) );
		self::assertStringStartsWith( 'https://example.test/landing/', $this->requests[1]['url'] );
	}

	public function test_an_entry_the_journal_cannot_describe_can_still_be_baselined_and_judged_from_memory(): void {
		$entry = RescueGuard::memory_entry(
			[
				'ability'       => 'My Plugin/Odd.Name',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ],
				'scope'         => 'light',
			]
		);

		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/', $entry['id'] );
		self::assertFalse( $entry['persisted'] );
		self::assertSame( 'passed', $entry['baseline']['status'] );
		self::assertNull( ChangeJournal::get( $entry['id'] ), 'It is not in the journal.' );

		$this->site( static fn (): string => 'gateway' );
		$rolled  = 0;
		$verdict = RescueGuard::verify_or_rollback(
			$entry,
			[
				'rollback' => static function () use ( &$rolled ): array {
					++$rolled;
					return [ 'status' => 'succeeded', 'recipe' => 'theme_backup', 'detail' => '' ];
				},
			]
		);

		self::assertSame( 1, $rolled, 'Reachable before, unreachable after: undone.' );
		self::assertSame( 'rolled_back', $verdict['status'] );
		self::assertSame( '', $verdict['incident_id'], 'There is no journal entry to point at.' );
	}

	public function test_a_hostile_filter_cannot_break_arming_through_the_baseline(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_probe_enabled'] = static function (): bool {
			throw new \RuntimeException( 'a hostile filter' );
		};
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );

		$this->armed_post_change();

		self::assertCount( 1, ChangeJournal::recent(), 'The write is still armed.' );
		self::assertSame( [ 'ok' => true ], RescueGuard::leave( [ 'ok' => true ] ) );
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

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\RescueRollback as RescueRollbackAbility;
use Stonewright\WpMcp\CustomCode\ProviderSupport;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * The undo of a verified change keeps the way back: it saves what the recipe will overwrite, probes the
 * site before and after, and puts the saved state back when the undo made a healthy site fail.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 */
final class RescueUndoRevertTest extends TestCase {

	private string $base;

	private string $theme;

	/** @var list<string> Sandbox file names to clean up. */
	private array $sandbox_names = [];

	/** @var mixed */
	private $original_stylesheet_dir;

	/** @var callable():string */
	private $site_state;

	/** @var int Requests the probe transport has answered. */
	private int $requests = 0;

	/** @var object|null The fake custom-code provider of the test. */
	private ?object $provider = null;

	protected function setUp(): void {
		$this->base  = sys_get_temp_dir() . '/sw-undo-' . bin2hex( random_bytes( 5 ) );
		$this->theme = $this->base . '/themes/site-a';
		mkdir( $this->theme, 0700, true );
		mkdir( $this->base . '/uploads', 0700, true );
		$this->original_stylesheet_dir                    = $GLOBALS['stonewright_test_stylesheet_directory'] ?? null;
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme;
		$GLOBALS['stonewright_test_upload_dir']           = [ 'basedir' => $this->base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_active_plugins']       = [];
		$GLOBALS['stonewright_test_plugin_state_visible'] = true;
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_caps_by_id']      = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		ChangeJournal::reset_for_tests();
		$this->requests = 0;
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RollbackRecipes::set_provider_resolver( null );
		RescueRollback::set_selection_runner( null );
		ChangeJournal::reset_for_tests();
		if ( null === $this->original_stylesheet_dir ) {
			unset( $GLOBALS['stonewright_test_stylesheet_directory'] );
		} else {
			$GLOBALS['stonewright_test_stylesheet_directory'] = $this->original_stylesheet_dir;
		}
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_active_plugins'] = [];
		unset( $GLOBALS['stonewright_test_plugin_state_visible'] );
		foreach ( $this->sandbox_names as $name ) {
			$this->cleanup_sandbox( $name );
		}
		self::remove_tree( $this->base );
	}

	/** @param callable():string $state 'healthy', 'broken' or 'unavailable'. */
	private function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				++$this->requests;
				return match ( ( $this->site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ],
					'unavailable' => new \WP_Error( 'http_request_failed', 'cURL error 7' ),
					default       => [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ],
				};
			}
		);
	}

	/** Probes the site has answered since the test began, whole probes of a scenario's legs. */
	private function probes( array $scenario ): int {
		return (int) ( $this->requests / $scenario['legs'] );
	}

	/**
	 * A verified change whose undo can be run, one per family of recipe.
	 *
	 * 'observe' tells the state of what the undo changes; 'before' is that state now, 'after' the state the
	 * recipe puts it in. 'captures' counts what a capture stores (snapshots, backups), 'delta' how many
	 * one capture adds.
	 *
	 * @return array{id:string,observe:callable():string,before:string,after:string,captures:callable():int,delta:int,legs:int}
	 */
	private function scenario( string $family, string $state = 'verified' ): array {
		$entry = match ( $family ) {
			'theme_file' => $this->theme_change(),
			'custom_code' => $this->custom_code_change(),
			'sandbox'    => $this->sandbox_change(),
			'plugin'     => $this->plugin_change(),
			'option'     => $this->option_change(),
			'post'       => $this->post_change(),
			default      => throw new \InvalidArgumentException( $family ),
		};
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $entry['id'], $state );
		}
		$entry['legs'] = count( HealthProbe::legs_for( (string) ( ChangeJournal::get( $entry['id'] )['scope'] ?? 'site' ) ) );
		return $entry;
	}

	/** The site is broken exactly while the undone state is in place. */
	private function breaks_when_undone( array $scenario ): void {
		$this->site( static fn (): string => ( $scenario['observe'] )() === $scenario['after'] ? 'broken' : 'healthy' );
	}

	/** @return array<string, mixed> */
	private function theme_change(): array {
		$path   = $this->theme . '/functions.php';
		$older  = "<?php\n// older version\n";
		$newer  = "<?php\n// newer version\n";
		file_put_contents( $path, $older );
		$write = ThemeWriteTransaction::apply( [ 'absolute' => $path, 'relative' => 'functions.php', 'before' => $older, 'after' => $newer, 'language' => 'php', 'skip_smoke' => true ] );
		self::assertIsArray( $write );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => (string) $write['backup_ref'] ],
				'recipe_detail' => [ 'absolute' => $path ],
				'scope'         => 'site',
			]
		);
		return [
			'id'       => (string) $entry['id'],
			'observe'  => static fn (): string => (string) file_get_contents( $path ),
			'before'   => $newer,
			'after'    => $older,
			'captures' => static fn (): int => count( (array) get_option( 'stonewright_theme_backup_index', [] ) ),
			'delta'    => 1,
		];
	}

	/** @return array<string, mixed> */
	private function post_change(): array {
		$GLOBALS['stonewright_test_posts'][31] = (object) [
			'ID' => 31, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture', 'post_content' => 'older body',
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'fixture', 'meta' => [],
		];
		$snapshot = Backup::snapshot_post( 31 );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'newer body';
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/content-update-page',
				'resource_type' => 'post',
				'resource_key'  => '31',
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => 31, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		return [
			'id'       => (string) $entry['id'],
			'observe'  => static fn (): string => (string) ( $GLOBALS['stonewright_test_posts'][31]->post_content ?? '' ),
			'before'   => 'newer body',
			'after'    => 'older body',
			'captures' => static fn (): int => count( Backup::list_snapshots( 31 ) ),
			'delta'    => 1,
		];
	}

	/** @return array<string, mixed> */
	private function option_change(): array {
		update_option( 'blogname', 'Older name', false );
		$restore = Backup::snapshot_options( [ 'blogname' ] );
		update_option( 'blogname', 'Newer name', false );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/settings-update',
				'resource_type' => 'option',
				'resource_key'  => 'blogname',
				'recipe'        => [ 'type' => 'option_restore', 'ref' => $restore ],
				'recipe_detail' => [ 'restore_id' => $restore ],
				'scope'         => 'light',
			]
		);
		return [
			'id'       => (string) $entry['id'],
			'observe'  => static fn (): string => (string) get_option( 'blogname' ),
			'before'   => 'Newer name',
			'after'    => 'Older name',
			'captures' => static fn (): int => count( (array) get_option( Backup::OPTION_SNAPSHOTS, [] ) ),
			'delta'    => 1,
		];
	}

	/** @return array<string, mixed> */
	private function plugin_change(): array {
		$GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] = true;
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/plugin-activate',
				'resource_type' => 'plugin',
				'resource_key'  => 'hello/hello.php',
				'recipe'        => [ 'type' => 'plugin_state', 'ref' => 'hello/hello.php' ],
				'recipe_detail' => [ 'plugin' => 'hello/hello.php', 'was_active' => false ],
				'scope'         => 'site',
			]
		);
		return [
			'id'       => (string) $entry['id'],
			'observe'  => static fn (): string => isset( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] ) ? 'active' : 'inactive',
			'before'   => 'active',
			'after'    => 'inactive',
			'captures' => static fn (): int => 0,
			'delta'    => 0,
		];
	}

	/** @return array<string, mixed> */
	private function sandbox_change(): array {
		$name = 'undo-revert-' . bin2hex( random_bytes( 3 ) ) . '.php';
		$this->sandbox_names[] = $name;
		self::assertTrue( SandboxFiles::write( $name, "<?php\n// harmless\n" ) );
		self::assertTrue( SandboxFiles::activate( $name ) );
		$twin  = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/sandbox-activate',
				'resource_type' => 'sandbox',
				'resource_key'  => $name,
				'recipe'        => [ 'type' => 'sandbox_file', 'ref' => $name ],
				'recipe_detail' => [ 'name' => $name ],
				'scope'         => 'site',
			]
		);
		$observe = static function () use ( $twin ): string {
			clearstatcache();
			if ( is_file( $twin ) ) {
				return 'on:' . hash( 'sha256', (string) file_get_contents( $twin ) );
			}
			return is_file( $twin . '.disabled' ) ? 'disabled' : 'absent';
		};
		return [
			'id'       => (string) $entry['id'],
			'observe'  => $observe,
			'before'   => $observe(),
			'after'    => 'disabled',
			'captures' => static fn (): int => 0,
			'delta'    => 0,
			'twin'     => $twin,
		];
	}

	/** @return array<string, mixed> */
	private function custom_code_change(): array {
		$provider = new class() {
			public string $code = 'newer snippet';
			public int $reads = 0;
			public int $rollbacks = 0;
			public bool $read_fails = false;
			public bool $second_rollback_fails = false;

			/** @return array<string, mixed>|\WP_Error */
			public function read( string $target_id ) {
				++$this->reads;
				if ( $this->read_fails ) {
					return new \WP_Error( 'stonewright_wpcode_unavailable', 'unavailable' );
				}
				return [ 'ok' => true, 'id' => $target_id, 'path' => 'wpcode/snippet/' . $target_id, 'active' => true, 'code' => $this->code ];
			}

			/** @return array<string, mixed>|\WP_Error */
			public function rollback( array $args ) {
				++$this->rollbacks;
				if ( $this->second_rollback_fails && $this->rollbacks > 1 ) {
					return new \WP_Error( 'stonewright_wpcode_save_failed', 'save failed' );
				}
				$snap = ProviderSupport::load_snapshot( (string) $args['snapshot_id'] );
				if ( null === $snap ) {
					return new \WP_Error( 'stonewright_wpcode_snapshot_missing', 'missing' );
				}
				$this->code = (string) $snap['body'];
				return [ 'ok' => true, 'rolled_back' => true, 'effect_verified' => true ];
			}
		};
		$this->provider = $provider;
		RollbackRecipes::set_provider_resolver( static fn ( string $id ): ?object => 'wpcode' === $id ? $provider : null );
		$snapshot = ProviderSupport::snapshot_record( 'wpcode', '9', 'wpcode/snippet/9', 'older snippet', [ 'active' => true ] );
		$entry    = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/custom-code-provider',
				'resource_type' => 'custom_code',
				'resource_key'  => 'wpcode:9',
				'recipe'        => [ 'type' => 'none', 'ref' => $snapshot['snapshot_id'] ],
				'recipe_detail' => [ 'provider' => 'wpcode', 'snapshot_id' => $snapshot['snapshot_id'], 'target_id' => '9' ],
				'scope'         => 'site',
			]
		);
		return [
			'id'       => (string) $entry['id'],
			'observe'  => static fn (): string => $provider->code,
			'before'   => 'newer snippet',
			'after'    => 'older snippet',
			'captures' => static fn (): int => count( array_filter( array_keys( (array) $GLOBALS['stonewright_test_transients'] ), static fn ( $key ): bool => str_starts_with( (string) $key, 'sw_cc_snap_' ) ) ),
			'delta'    => 1,
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	private function undo( string $id ): array|\WP_Error {
		return RescueRollback::run( $id, [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );
	}

	/** @return iterable<string, array{string}> */
	public static function families(): iterable {
		yield 'theme file'    => [ 'theme_file' ];
		yield 'custom code'   => [ 'custom_code' ];
		yield 'sandbox file'  => [ 'sandbox' ];
		yield 'plugin state'  => [ 'plugin' ];
		yield 'option'        => [ 'option' ];
		yield 'post snapshot' => [ 'post' ];
	}

	// -- The undo that would break the site is put back ---------------------------------------------

	/** @dataProvider families */
	public function test_an_undo_that_makes_the_site_fail_is_put_back_and_the_change_stays_verified( string $family ): void {
		$scenario = $this->scenario( $family );
		$this->breaks_when_undone( $scenario );
		$captures = ( $scenario['captures'] )();

		$result = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( $scenario['before'], ( $scenario['observe'] )(), 'The state from before the undo is back.' );
		self::assertSame( $captures + $scenario['delta'], ( $scenario['captures'] )(), 'The current state was saved before the recipe ran.' );
		$data = $result->get_error_data();
		self::assertSame( 'reverted', $data['rollback_status'] );
		self::assertSame( 'reverted', $data['undo_guard'] );
		self::assertSame( 'healthy', $data['site_status'], 'The site loads again.' );
		self::assertSame( 'failed', $data['probe']['status'], 'The evidence is the probe that failed after the undo.' );
		self::assertSame( $scenario['id'], $data['incident_id'] );
		self::assertStringContainsString( 'put back', $result->get_error_message() );
		self::assertStringContainsString( '"status":"failed"', $result->get_error_message() );
		$entry = ChangeJournal::get( $scenario['id'] );
		self::assertSame( 'verified', $entry['state'], 'The change is still in effect, so it stays verified.' );
		self::assertSame( 'undo_reverted', $entry['note'] );
		self::assertSame( 0, $entry['claim_at'], 'The claim is released.' );
		self::assertSame( 'reverted', $entry['rollback']['status'] );
		self::assertNull( ChangeJournal::banner(), 'No incident is opened for an undo that was put back.' );
		self::assertSame( 3, $this->probes( $scenario ), 'Baseline, after the undo, after the put-back.' );
	}

	/** @dataProvider families */
	public function test_an_undo_that_leaves_the_site_healthy_is_kept( string $family ): void {
		$scenario = $this->scenario( $family );

		$result = $this->undo( $scenario['id'] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'rolled_back', $result['state'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'kept', $result['undo_guard'] );
		self::assertSame( $scenario['after'], ( $scenario['observe'] )() );
		$entry = ChangeJournal::get( $scenario['id'] );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertSame( 'undone_after_verified', $entry['note'] );
		self::assertSame( 2, $this->probes( $scenario ), 'Baseline and after the undo.' );
	}

	// -- No way back, no undo ----------------------------------------------------------------------

	/** @return iterable<string, array{string, callable(self, array):void}> */
	public static function capture_failures(): iterable {
		yield 'theme file backup cannot be written' => [ 'theme_file', static function ( self $test, array $scenario ): void {
			$test->block_theme_backups();
		} ];
		yield 'post cannot be read' => [ 'post', static function ( self $test, array $scenario ): void {
			unset( $GLOBALS['stonewright_test_posts'][31] );
		} ];
		yield 'option restore point is gone' => [ 'option', static function ( self $test, array $scenario ): void {
			update_option( Backup::OPTION_SNAPSHOTS, [], false );
		} ];
		yield 'provider cannot be read' => [ 'custom_code', static function ( self $test, array $scenario ): void {
			$test->provider()->read_fails = true;
		} ];
		yield 'sandbox copy cannot be read' => [ 'sandbox', static function ( self $test, array $scenario ): void {
			unlink( $scenario['twin'] );
			mkdir( $scenario['twin'] );
		} ];
		yield 'plugin file name is not usable' => [ 'plugin', static function ( self $test, array $scenario ): void {
			$test->rewrite_recipe_ref( $scenario['id'], '../evil.php' );
		} ];
	}

	/** A file where the backup folder should be: no backup can be written. */
	public function block_theme_backups(): void {
		self::remove_tree( $this->base . '/uploads/stonewright-theme-backups' );
		file_put_contents( $this->base . '/uploads/stonewright-theme-backups', 'not a folder' );
	}

	public function provider(): object {
		return (object) $this->provider;
	}

	/** Point the recipe of a plugin entry at a name that is not a plugin file. For the capture failure case. */
	public function rewrite_recipe_ref( string $id, string $ref ): void {
		ChangeJournal::attach_recipe( $id, [ 'type' => 'plugin_state', 'ref' => $ref ] );
	}

	/** @dataProvider capture_failures */
	public function test_when_the_current_state_cannot_be_saved_the_undo_is_refused_before_anything_changes( string $family, callable $break ): void {
		$scenario = $this->scenario( $family );
		$break( $this, $scenario );
		$before   = ( $scenario['observe'] )();
		$captures = ( $scenario['captures'] )();
		$this->requests = 0;

		$result = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_capture_failed', $result->get_error_code() );
		self::assertStringContainsString( 'Nothing was changed', $result->get_error_message() );
		self::assertSame( $before, ( $scenario['observe'] )(), 'Nothing was changed.' );
		self::assertSame( $captures, ( $scenario['captures'] )() );
		self::assertSame( 0, $this->requests, 'The site is not probed for an undo that was refused.' );
		$entry = ChangeJournal::get( $scenario['id'] );
		self::assertSame( 'verified', $entry['state'] );
		self::assertSame( 0, $entry['claim_at'], 'The claim is released.' );
		self::assertNull( $entry['rollback'] );
		if ( 'custom_code' === $family ) {
			self::assertSame( 0, $this->provider()->rollbacks, 'The provider was never asked to roll back.' );
		}
	}

	// -- Open changes keep today's behaviour --------------------------------------------------------

	/** @dataProvider families */
	public function test_an_incident_rollback_saves_nothing_probes_once_and_is_never_put_back( string $family ): void {
		foreach ( [ 'rollback_failed', 'armed' ] as $state ) {
			ChangeJournal::reset_for_tests();
			$this->requests = 0;
			$scenario       = $this->scenario( $family, $state );
			$this->breaks_when_undone( $scenario );
			$captures = ( $scenario['captures'] )();
			$this->requests = 0;

			$result = $this->undo( $scenario['id'] );

			self::assertIsArray( $result, $state );
			self::assertTrue( $result['ok'], $state );
			self::assertSame( 'rolled_back', $result['state'], $state );
			self::assertSame( 'still_failing', $result['site_status'], $state );
			self::assertArrayNotHasKey( 'undo_guard', $result, $state );
			self::assertSame( $scenario['after'], ( $scenario['observe'] )(), $state . ': the recipe stays applied; the rollback is the cure.' );
			self::assertSame( $captures, ( $scenario['captures'] )(), $state . ': nothing is saved first.' );
			self::assertSame( 1, $this->probes( $scenario ), $state . ': one probe, after the recipe.' );
		}
	}

	/** @dataProvider families */
	public function test_when_the_site_already_fails_before_the_undo_the_rollback_is_kept_and_says_so( string $family ): void {
		$scenario = $this->scenario( $family );
		$this->site( static fn (): string => 'broken' );

		$result = $this->undo( $scenario['id'] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'rolled_back', $result['state'] );
		self::assertSame( 'still_failing', $result['site_status'] );
		self::assertSame( 'site_already_failing', $result['undo_guard'] );
		self::assertSame( $scenario['after'], ( $scenario['observe'] )(), 'The cure is not put back.' );
		self::assertStringContainsString( 'already failing', implode( ' ', $result['warnings'] ) );
		self::assertSame( 2, $this->probes( $scenario ) );
	}

	public function test_when_no_probe_can_run_the_undo_is_kept_and_reported_as_unchecked(): void {
		$scenario = $this->scenario( 'theme_file' );
		$this->site( static fn (): string => 'unavailable' );

		$result = $this->undo( $scenario['id'] );

		self::assertIsArray( $result );
		self::assertSame( 'unchecked', $result['undo_guard'] );
		self::assertSame( 'unknown', $result['site_status'] );
		self::assertSame( $scenario['after'], ( $scenario['observe'] )() );
		self::assertSame( 'rolled_back', ChangeJournal::get( $scenario['id'] )['state'] );
	}

	// -- Putting back can fail too ------------------------------------------------------------------

	public function test_when_the_saved_state_cannot_be_put_back_the_change_becomes_an_open_incident(): void {
		$scenario = $this->scenario( 'custom_code' );
		$this->breaks_when_undone( $scenario );
		$this->provider()->second_rollback_fails = true;

		$result = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_revert_failed', $result->get_error_code() );
		self::assertSame( 'failed', $result->get_error_data()['rollback_status'] );
		$entry = ChangeJournal::get( $scenario['id'] );
		self::assertSame( 'rollback_failed', $entry['state'], 'The site is failing and nothing put it back: this is an incident.' );
		self::assertSame( $scenario['id'], ChangeJournal::banner()['id'] );
		self::assertSame( 0, $entry['claim_at'] );
	}

	public function test_a_site_that_still_fails_after_the_put_back_is_reported_as_such(): void {
		$scenario = $this->scenario( 'theme_file' );
		// The baseline passes, everything after fails: a fault that is not the undo's.
		$calls = 0;
		$this->site( static function () use ( &$calls ): string {
			++$calls;
			return $calls <= 3 ? 'healthy' : 'broken';
		} );

		$result = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( 'still_failing', $result->get_error_data()['site_status'] );
		self::assertStringContainsString( 'still fails', $result->get_error_message() );
		self::assertSame( $scenario['before'], ( $scenario['observe'] )() );
		self::assertSame( 'verified', ChangeJournal::get( $scenario['id'] )['state'] );
	}

	// -- The gates that were there stay -------------------------------------------------------------

	public function test_the_claim_still_stops_a_second_run_before_anything_is_saved(): void {
		$scenario = $this->scenario( 'theme_file' );
		self::assertNotNull( ChangeJournal::claim( $scenario['id'], 'admin-page' ) );
		$captures       = ( $scenario['captures'] )();
		$this->requests = 0;

		$second = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_rescue_in_progress', $second->get_error_code() );
		self::assertSame( $captures, ( $scenario['captures'] )() );
		self::assertSame( 0, $this->requests );
		self::assertSame( $scenario['before'], ( $scenario['observe'] )() );
	}

	public function test_an_agent_still_cannot_undo_verified_code_and_nothing_is_saved_or_probed(): void {
		foreach ( [ 'theme_file', 'custom_code', 'sandbox' ] as $family ) {
			ChangeJournal::reset_for_tests();
			$scenario       = $this->scenario( $family );
			$captures       = ( $scenario['captures'] )();
			$this->requests = 0;

			$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $scenario['id'] ] );

			self::assertInstanceOf( \WP_Error::class, $result, $family );
			self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code(), $family );
			self::assertSame( $captures, ( $scenario['captures'] )(), $family );
			self::assertSame( 0, $this->requests, $family );
			self::assertSame( $scenario['before'], ( $scenario['observe'] )(), $family );
		}
	}

	public function test_production_safe_mode_still_asks_for_the_token_before_anything_is_saved(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$scenario       = $this->scenario( 'post' );
		$captures       = ( $scenario['captures'] )();
		$this->requests = 0;

		$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $scenario['id'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( $captures, ( $scenario['captures'] )() );
		self::assertSame( 0, $this->requests );
	}

	public function test_the_newer_change_warning_still_comes_with_the_result(): void {
		$scenario = $this->scenario( 'post' );
		$newer    = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );

		$result = $this->undo( $scenario['id'] );

		self::assertIsArray( $result );
		self::assertStringContainsString( 'newer change', $result['warnings'][0] );
	}

	public function test_a_dry_run_changes_and_saves_nothing(): void {
		$scenario = $this->scenario( 'theme_file' );
		$captures = ( $scenario['captures'] )();
		$this->requests = 0;

		$result = RescueRollback::run( $scenario['id'], [ 'by' => 'ability', 'dry_run' => true, 'human_approved' => true ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( $captures, ( $scenario['captures'] )() );
		self::assertSame( 0, $this->requests );
	}

	public function test_the_ability_audits_an_undo_that_was_put_back_as_an_error_with_its_rollback_status(): void {
		$scenario = $this->scenario( 'post' );
		$this->breaks_when_undone( $scenario );

		$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $scenario['id'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows, 'One receipt.' );
		self::assertSame( 'reverted', $rows[0]['rollback_status'] );
		self::assertSame( $scenario['id'], $rows[0]['change_set_id'] );
	}

	/** An undo that was put back leaves the change as it was, so it can be asked for again. */
	public function test_after_a_put_back_the_undo_can_be_tried_again(): void {
		$scenario = $this->scenario( 'theme_file' );
		$this->breaks_when_undone( $scenario );
		$first = $this->undo( $scenario['id'] );
		self::assertInstanceOf( \WP_Error::class, $first );
		$this->site( static fn (): string => 'healthy' );

		$second = $this->undo( $scenario['id'] );

		self::assertIsArray( $second );
		self::assertTrue( $second['ok'] );
		self::assertSame( $scenario['after'], ( $scenario['observe'] )() );
		self::assertSame( 'rolled_back', ChangeJournal::get( $scenario['id'] )['state'] );
	}

	// -- Details of what is saved -------------------------------------------------------------------

	public function test_the_saved_theme_file_survives_a_file_that_did_not_exist_and_one_that_is_empty(): void {
		$path = $this->theme . '/inc/extra.php';
		mkdir( dirname( $path ), 0700, true );
		file_put_contents( $path, "<?php\n// created by the change\n" );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'inc/extra.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'absent:inc/extra.php' ],
				'recipe_detail' => [ 'absolute' => $path ],
				'scope'         => 'site',
			]
		);
		ChangeJournal::settle( $entry['id'], 'verified' );
		$this->site( static fn (): string => is_file( $path ) ? 'healthy' : 'broken' );

		$result = $this->undo( $entry['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( "<?php\n// created by the change\n", file_get_contents( $path ), 'The file the change created is back after its removal broke the site.' );

		$empty = $this->theme . '/blank.css';
		file_put_contents( $empty, 'a { color: red; }' );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'blank.css',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'empty:blank.css' ],
				'recipe_detail' => [ 'absolute' => $empty ],
				'scope'         => 'light',
			]
		);
		ChangeJournal::settle( $entry['id'], 'verified' );
		$this->site( static fn (): string => '' === (string) file_get_contents( $empty ) ? 'broken' : 'healthy' );

		$result = $this->undo( $entry['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( 'a { color: red; }', file_get_contents( $empty ) );
	}

	public function test_a_post_undo_cannot_fatal_the_site_but_follows_the_same_path(): void {
		$scenario = $this->scenario( 'post' );
		// A page that renders badly is the worst a post can do; the post leg is what fails.
		$this->breaks_when_undone( $scenario );

		$result = $this->undo( $scenario['id'] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( 'newer body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertCount( 2, Backup::list_snapshots( 31 ), 'The saved state sits beside the snapshot the recipe used.' );
	}

	public function test_the_snapshot_the_recipe_needs_survives_a_full_snapshot_history(): void {
		$scenario = $this->scenario( 'post' );
		// A history at its limit: saving one more would drop the oldest, which is the recipe's.
		for ( $i = 0; $i < 9; $i++ ) {
			Backup::snapshot_post( 31 );
		}
		self::assertCount( 10, Backup::list_snapshots( 31 ) );

		$result = $this->undo( $scenario['id'] );

		self::assertIsArray( $result );
		self::assertSame( 'older body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_saving_the_current_state_does_not_arm_another_change(): void {
		$scenario = $this->scenario( 'post' );
		$before   = count( ChangeJournal::recent( 50 ) );

		$this->undo( $scenario['id'] );

		self::assertSame( $before, count( ChangeJournal::recent( 50 ) ), 'The saved state is a way back, not a new change.' );
	}

	private function cleanup_sandbox( string $name ): void {
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		foreach ( [ SandboxFiles::stored_path( $name ), $twin, $twin . '.disabled' ] as $file ) {
			if ( is_dir( $file ) ) {
				@rmdir( $file );
			} elseif ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		foreach ( glob( SandboxFiles::draft_dir() . '/' . pathinfo( $name, PATHINFO_FILENAME ) . '.*.bak' ) ?: [] as $backup ) {
			@unlink( $backup );
		}
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

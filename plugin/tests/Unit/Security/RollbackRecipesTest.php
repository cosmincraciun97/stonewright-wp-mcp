<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * Every rollback recipe the journal can name, run against the same stores the writes used.
 *
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 */
final class RollbackRecipesTest extends TestCase {

	private string $theme;

	private string $mu;

	/** @var mixed */
	private $original_stylesheet_dir;

	protected function setUp(): void {
		$base                  = sys_get_temp_dir() . '/sw-recipes-' . bin2hex( random_bytes( 5 ) );
		$this->theme           = $base . '/themes/site-a';
		$this->mu              = $base . '/mu-plugins';
		mkdir( $this->theme, 0700, true );
		mkdir( $this->mu, 0700, true );
		$this->original_stylesheet_dir = $GLOBALS['stonewright_test_stylesheet_directory'] ?? null;
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme;
		$GLOBALS['stonewright_test_options']      = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']        = [];
		$GLOBALS['stonewright_test_active_plugins'] = [];
		$GLOBALS['stonewright_test_upload_dir']   = [ 'basedir' => $base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		mkdir( $base . '/uploads', 0700, true );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
	}

	protected function tearDown(): void {
		if ( null === $this->original_stylesheet_dir ) {
			unset( $GLOBALS['stonewright_test_stylesheet_directory'] );
		} else {
			$GLOBALS['stonewright_test_stylesheet_directory'] = $this->original_stylesheet_dir;
		}
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		self::remove_tree( dirname( $this->theme, 2 ) );
		$GLOBALS['stonewright_test_options'] = [];
	}

	/** @return array<string, mixed> */
	private static function entry( string $type, string $ref, array $detail = [], array $override = [] ): array {
		return array_merge(
			[
				'id'            => 'cs-test',
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => $type, 'ref' => $ref ],
				'recipe_detail' => $detail,
				'paths'         => [],
				'state'         => 'armed',
			],
			$override
		);
	}

	// -- post_snapshot ---------------------------------------------------------

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
			'meta'         => [ '_elementor_data' => '[{"id":"original"}]' ],
		];
	}

	public function test_a_post_snapshot_recipe_restores_the_post(): void {
		$this->post( 31, 'original body' );
		$snapshot = Backup::snapshot_post( 31 );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'broken body';
		update_post_meta( 31, '_elementor_data', '[{"id":"broken"}]' );

		$result = RollbackRecipes::run( self::entry( 'post_snapshot', $snapshot, [ 'post_id' => 31 ], [ 'resource_type' => 'post', 'resource_key' => '31' ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( 'post_snapshot', $result['recipe'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( '[{"id":"original"}]', get_post_meta( 31, '_elementor_data', true ) );
	}

	public function test_the_post_id_can_come_from_the_resource_key(): void {
		$this->post( 32, 'original body' );
		$snapshot = Backup::snapshot_post( 32 );
		$GLOBALS['stonewright_test_posts'][32]->post_content = 'broken body';

		$result = RollbackRecipes::run( self::entry( 'post_snapshot', $snapshot, [], [ 'resource_type' => 'post', 'resource_key' => '32' ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][32]->post_content );
	}

	public function test_a_missing_snapshot_is_a_failure_not_a_success(): void {
		$this->post( 33, 'body' );

		$result = RollbackRecipes::run( self::entry( 'post_snapshot', 'snap_missing', [ 'post_id' => 33 ] ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'snapshot_missing', $result['detail'] );
	}

	// -- option_restore --------------------------------------------------------

	public function test_an_option_restore_recipe_puts_the_options_back(): void {
		update_option( 'blogname', 'Before', false );
		$restore = Backup::snapshot_options( [ 'blogname', 'sw_new_option' ] );
		update_option( 'blogname', 'After', false );
		update_option( 'sw_new_option', 'created by the change', false );

		$result = RollbackRecipes::run( self::entry( 'option_restore', $restore, [], [ 'resource_type' => 'option', 'resource_key' => 'blogname' ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( 'Before', get_option( 'blogname' ) );
		self::assertFalse( get_option( 'sw_new_option', false ), 'An option the change created is removed again.' );
	}

	public function test_an_unknown_option_snapshot_is_a_failure(): void {
		$result = RollbackRecipes::run( self::entry( 'option_restore', 'snap_nope', [], [ 'resource_type' => 'option' ] ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'snapshot_missing', $result['detail'] );
	}

	// -- theme_backup ----------------------------------------------------------

	public function test_a_theme_backup_recipe_restores_the_original_bytes(): void {
		$path   = $this->theme . '/functions.php';
		$before = "<?php\n// original\n";
		file_put_contents( $path, $before );
		$write = ThemeWriteTransaction::apply(
			[
				'absolute'   => $path,
				'relative'   => 'functions.php',
				'before'     => $before,
				'after'      => "<?php\n// changed\n",
				'language'   => 'php',
				'skip_smoke' => true,
			]
		);
		self::assertIsArray( $write );

		$result = RollbackRecipes::run( self::entry( 'theme_backup', (string) $write['backup_ref'], [ 'absolute' => $path ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( $before, file_get_contents( $path ) );
	}

	public function test_a_theme_backup_recipe_refuses_a_path_outside_the_theme(): void {
		$path   = $this->theme . '/functions.php';
		file_put_contents( $path, "<?php\n// original\n" );
		$write = ThemeWriteTransaction::apply(
			[
				'absolute'   => $path,
				'relative'   => 'functions.php',
				'before'     => "<?php\n// original\n",
				'after'      => "<?php\n// changed\n",
				'language'   => 'php',
				'skip_smoke' => true,
			]
		);
		// Point the stored backup at a file outside every theme directory.
		$index = get_option( 'stonewright_theme_backup_index' );
		$ref   = (string) $write['backup_ref'];
		$index[ $ref ]['absolute'] = dirname( $this->theme, 2 ) . '/wp-config.php';
		update_option( 'stonewright_theme_backup_index', $index, false );
		file_put_contents( dirname( $this->theme, 2 ) . '/wp-config.php', 'keep' );

		$result = RollbackRecipes::run( self::entry( 'theme_backup', $ref, [] ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'path_outside_theme', $result['detail'] );
		self::assertSame( 'keep', file_get_contents( dirname( $this->theme, 2 ) . '/wp-config.php' ) );
	}

	public function test_a_file_the_change_created_is_removed_again(): void {
		$path = $this->theme . '/inc/new.php';
		mkdir( dirname( $path ), 0700, true );
		file_put_contents( $path, "<?php\n// created by the change\n" );

		$result = RollbackRecipes::run( self::entry( 'theme_backup', 'absent:inc/new.php', [ 'absolute' => $path ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertFileDoesNotExist( $path );
	}

	public function test_removing_a_file_that_is_already_gone_is_a_no_op_success(): void {
		$result = RollbackRecipes::run( self::entry( 'theme_backup', 'absent:inc/gone.php', [ 'absolute' => $this->theme . '/inc/gone.php' ] ) );

		self::assertSame( 'noop', $result['status'] );
	}

	public function test_a_file_that_was_empty_before_is_emptied_again(): void {
		$path = $this->theme . '/empty.css';
		file_put_contents( $path, 'a { color: red; }' );

		$result = RollbackRecipes::run( self::entry( 'theme_backup', 'empty:empty.css', [ 'absolute' => $path ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( '', file_get_contents( $path ) );
	}

	public function test_the_absent_marker_cannot_delete_outside_the_theme(): void {
		$outside = dirname( $this->theme, 2 ) . '/wp-config.php';
		file_put_contents( $outside, 'keep' );

		$result = RollbackRecipes::run( self::entry( 'theme_backup', 'absent:wp-config.php', [ 'absolute' => $outside ] ) );

		self::assertSame( 'failed', $result['status'] );
		self::assertFileExists( $outside );
	}

	// -- plugin_state ----------------------------------------------------------

	public function test_an_activation_is_undone_by_deactivating_the_plugin(): void {
		$GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] = true;

		$result = RollbackRecipes::run( self::entry( 'plugin_state', 'hello/hello.php', [ 'was_active' => false ], [ 'resource_type' => 'plugin' ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertArrayNotHasKey( 'hello/hello.php', $GLOBALS['stonewright_test_active_plugins'] );
	}

	public function test_a_deactivation_is_undone_by_activating_the_plugin_again(): void {
		$result = RollbackRecipes::run( self::entry( 'plugin_state', 'hello/hello.php', [ 'was_active' => true ], [ 'resource_type' => 'plugin' ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertTrue( $GLOBALS['stonewright_test_active_plugins']['hello/hello.php'] );
	}

	public function test_a_plugin_recipe_never_touches_stonewright_or_a_path_that_is_not_a_plugin_file(): void {
		$own = RollbackRecipes::run( self::entry( 'plugin_state', 'stonewright/stonewright.php', [ 'was_active' => false ], [ 'resource_type' => 'plugin' ] ) );
		$bad = RollbackRecipes::run( self::entry( 'plugin_state', '../../evil.php', [ 'was_active' => true ], [ 'resource_type' => 'plugin' ] ) );

		self::assertSame( 'failed', $own['status'] );
		self::assertSame( 'failed', $bad['status'] );
		self::assertSame( [], $GLOBALS['stonewright_test_active_plugins'] );
	}

	// -- sandbox_file ----------------------------------------------------------

	public function test_an_activated_sandbox_file_is_disabled_again(): void {
		$name = 'rescue-recipe-test.php';
		self::assertTrue( SandboxFiles::write( $name, "<?php\n// harmless\n" ) );
		self::assertTrue( SandboxFiles::activate( $name ) );
		$twin = SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name;
		self::assertFileExists( $twin );

		$result = RollbackRecipes::run( self::entry( 'sandbox_file', $name, [], [ 'resource_type' => 'sandbox', 'resource_key' => $name ] ) );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertFileDoesNotExist( $twin );
		self::assertFileExists( $twin . '.disabled' );
		self::assertFileExists( SandboxFiles::stored_path( $name ), 'The draft is kept for review.' );
		$this->cleanup_sandbox( $name );
	}

	public function test_a_sandbox_file_that_is_already_off_needs_nothing(): void {
		$name = 'rescue-recipe-off.php';
		SandboxFiles::write( $name, "<?php\n// harmless\n" );

		$result = RollbackRecipes::run( self::entry( 'sandbox_file', $name, [], [ 'resource_type' => 'sandbox' ] ) );

		self::assertSame( 'noop', $result['status'] );
		$this->cleanup_sandbox( $name );
	}

	public function test_a_sandbox_name_that_is_a_path_is_refused(): void {
		$result = RollbackRecipes::run( self::entry( 'sandbox_file', '../escape.php', [], [ 'resource_type' => 'sandbox' ] ) );

		self::assertSame( 'failed', $result['status'] );
	}

	private function cleanup_sandbox( string $name ): void {
		foreach ( [ SandboxFiles::stored_path( $name ), SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name, SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . $name . '.disabled' ] as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		foreach ( glob( SandboxFiles::draft_dir() . '/' . pathinfo( $name, PATHINFO_FILENAME ) . '.*.bak' ) ?: [] as $backup ) {
			@unlink( $backup );
		}
	}

	// -- provider snapshot (custom code) ---------------------------------------

	public function test_a_snapshot_without_a_known_provider_has_no_recipe(): void {
		$result = RollbackRecipes::run( self::entry( 'none', '', [], [ 'resource_type' => 'custom_code' ] ) );

		self::assertSame( 'not_available', $result['status'] );
		self::assertFalse( RollbackRecipes::available( self::entry( 'none', '' ) ) );
	}

	public function test_a_custom_code_snapshot_is_rolled_back_through_its_provider(): void {
		$calls = [];
		RollbackRecipes::set_provider_resolver(
			static function ( string $provider ) use ( &$calls ): ?object {
				return new class( $provider, $calls ) {
					public function __construct( private string $id, private array &$calls ) {}

					/** @return array<string, mixed> */
					public function rollback( array $args ): array {
						$this->calls[] = [ $this->id, $args ];
						return [ 'ok' => true, 'rolled_back' => true, 'effect_verified' => true ];
					}
				};
			}
		);

		$result = RollbackRecipes::run(
			self::entry( 'none', 'snap-9', [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9', 'target_id' => '12' ], [ 'resource_type' => 'custom_code' ] )
		);
		RollbackRecipes::set_provider_resolver( null );

		self::assertSame( 'succeeded', $result['status'] );
		self::assertSame( [ [ 'wpcode', [ 'snapshot_id' => 'snap-9', 'target_id' => '12' ] ] ], $calls );
		self::assertFalse( RollbackRecipes::available( self::entry( 'none', 'snap-9', [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9' ] ) ), 'No snapshot is stored under that id: the entry is not available.' );

		$GLOBALS['stonewright_test_transients']['sw_cc_snap_snap-9'] = [ 'snapshot_id' => 'snap-9', 'provider' => 'wpcode', 'target_id' => '12', 'body' => 'x' ];
		self::assertTrue( RollbackRecipes::available( self::entry( 'none', 'snap-9', [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9' ] ) ) );
		unset( $GLOBALS['stonewright_test_transients']['sw_cc_snap_snap-9'] );
	}

	public function test_a_snapshot_that_has_expired_reads_as_not_available_in_the_plan_and_the_check(): void {
		$entry = self::entry( 'none', 'snap-9', [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9', 'target_id' => '12' ], [ 'resource_type' => 'custom_code' ] );

		self::assertFalse( RollbackRecipes::available( $entry ) );
		self::assertStringContainsString( 'expired', RollbackRecipes::describe( $entry ) );

		$GLOBALS['stonewright_test_transients']['sw_cc_snap_snap-9'] = [ 'snapshot_id' => 'snap-9', 'provider' => 'wpcode', 'target_id' => '12', 'body' => 'x' ];
		self::assertTrue( RollbackRecipes::available( $entry ) );
		self::assertStringContainsString( 'provider snapshot taken before', RollbackRecipes::describe( $entry ) );
		unset( $GLOBALS['stonewright_test_transients']['sw_cc_snap_snap-9'] );
	}

	public function test_a_provider_that_cannot_verify_the_restore_is_a_failure(): void {
		RollbackRecipes::set_provider_resolver(
			static fn ( string $provider ): object => new class() {
				/** @return array<string, mixed> */
				public function rollback( array $args ): array {
					return [ 'ok' => true, 'effect_verified' => false ];
				}
			}
		);

		$result = RollbackRecipes::run( self::entry( 'none', 'snap-9', [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9', 'target_id' => '12' ], [ 'resource_type' => 'custom_code' ] ) );
		RollbackRecipes::set_provider_resolver( null );

		self::assertSame( 'failed', $result['status'] );
	}

	// -- shape -----------------------------------------------------------------

	public function test_every_result_has_the_same_small_shape(): void {
		$this->post( 34, 'body' );
		$result = RollbackRecipes::run( self::entry( 'post_snapshot', 'snap_missing', [ 'post_id' => 34 ] ) );

		self::assertSame( [ 'status', 'recipe', 'detail' ], array_keys( $result ) );
	}

	public function test_each_recipe_describes_itself_in_a_sentence(): void {
		$sentences = [
			RollbackRecipes::describe( self::entry( 'post_snapshot', 'snap_x', [ 'post_id' => 31 ], [ 'resource_key' => '31' ] ) ),
			RollbackRecipes::describe( self::entry( 'option_restore', 'snap_y' ) ),
			RollbackRecipes::describe( self::entry( 'theme_backup', 'sw-theme-backup-1', [], [ 'resource_key' => 'functions.php' ] ) ),
			RollbackRecipes::describe( self::entry( 'theme_backup', 'absent:inc/new.php' ) ),
			RollbackRecipes::describe( self::entry( 'plugin_state', 'hello/hello.php', [ 'was_active' => false ] ) ),
			RollbackRecipes::describe( self::entry( 'plugin_state', 'hello/hello.php', [ 'was_active' => true ] ) ),
			RollbackRecipes::describe( self::entry( 'sandbox_file', 'hook.php' ) ),
			RollbackRecipes::describe( self::entry( 'none', '' ) ),
		];

		foreach ( $sentences as $sentence ) {
			self::assertNotSame( '', $sentence );
			self::assertLessThan( 200, strlen( $sentence ) );
		}
		self::assertStringContainsString( 'Deactivate', $sentences[4] );
		self::assertStringContainsString( 'Activate', $sentences[5] );
		self::assertStringContainsString( 'No automatic', $sentences[7] );
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

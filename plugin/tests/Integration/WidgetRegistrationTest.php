<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetList;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Integration tests for the full define → register widget pipeline.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetList
 */
final class WidgetRegistrationTest extends TestCase {

	/** @var string Temp dir acting as WP_CONTENT_DIR */
	private string $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = sys_get_temp_dir() . '/stonewright-widget-test-' . getmypid() . '-' . uniqid();
		mkdir( $this->temp_dir, 0700, true );

		// Override WP_CONTENT_DIR for the SandboxFiles draft_dir() calculation.
		// SandboxFiles::draft_dir() uses WP_CONTENT_DIR constant — we can't
		// redefine a constant, so we verify the test environment's WP_CONTENT_DIR
		// is already the temp dir set up by the test bootstrap.
		// Tests run in an isolated process where WP_CONTENT_DIR is already
		// a writable temp dir (see tests/bootstrap.php).

		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_user_caps']        = [
			'edit_plugins'   => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_current_user_id']  = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;

		// Clean up any widget files left in the sandbox draft dir.
		$draft_dir = SandboxFiles::draft_dir();
		foreach ( glob( $draft_dir . '/widget-*.php' ) ?: [] as $f ) {
			@unlink( $f );
		}
		foreach ( glob( $draft_dir . '/widget-*.pending.php' ) ?: [] as $f ) {
			@unlink( $f );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function define_args( string $slug = 'int-widget' ): array {
		return [
			'widget_slug'     => $slug,
			'label'           => 'Integration Widget',
			'category'        => 'stonewright',
			'controls'        => [
				[
					'id'      => 'title',
					'label'   => 'Title',
					'type'    => 'text',
					'default' => '',
				],
			],
			'template'        => '{{ title }}',
			'render_strategy' => 'twig',
		];
	}

	// -------------------------------------------------------------------------
	// Full define → register flow
	// -------------------------------------------------------------------------

	public function test_define_creates_pending_file(): void {
		$ability = new WidgetDefine();
		$result  = $ability->execute( self::define_args() );

		$this->assertNotInstanceOf( \WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( (bool) ( $result['ok'] ?? false ) );
		$this->assertSame( 'int-widget', $result['widget_slug'] );

		$pending = SandboxFiles::draft_dir() . '/widget-int-widget.pending.php';
		$this->assertFileExists( $pending );
	}

	public function test_pending_file_contains_php_class(): void {
		$ability = new WidgetDefine();
		$ability->execute( self::define_args() );

		$content = file_get_contents( SandboxFiles::draft_dir() . '/widget-int-widget.pending.php' );
		$this->assertNotFalse( $content );
		$this->assertStringContainsString( 'Widget_Base', $content );
		$this->assertStringContainsString( 'int-widget', $content );
	}

	public function test_register_renames_pending_to_active(): void {
		( new WidgetDefine() )->execute( self::define_args() );

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'int-widget' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( (bool) ( $result['ok'] ?? false ) );

		$pending = SandboxFiles::draft_dir() . '/widget-int-widget.pending.php';
		$active  = SandboxFiles::draft_dir() . '/widget-int-widget.php';

		$this->assertFileDoesNotExist( $pending );
		$this->assertFileExists( $active );
	}

	public function test_register_updates_option(): void {
		( new WidgetDefine() )->execute( self::define_args() );
		( new WidgetRegister() )->execute( [ 'widget_slug' => 'int-widget' ] );

		$registered = (array) get_option( 'stonewright_registered_widgets', [] );
		$this->assertContains( 'int-widget', $registered );
	}

	public function test_register_not_duplicate_in_option(): void {
		( new WidgetDefine() )->execute( self::define_args() );
		( new WidgetRegister() )->execute( [ 'widget_slug' => 'int-widget' ] );

		// Re-define and re-register — should NOT duplicate slug in option.
		( new WidgetDefine() )->execute( self::define_args() ); // overwrites pending.
		// Rename active back to pending so register can run again.
		$draft_dir = SandboxFiles::draft_dir();
		rename( $draft_dir . '/widget-int-widget.php', $draft_dir . '/widget-int-widget.pending.php' );
		( new WidgetRegister() )->execute( [ 'widget_slug' => 'int-widget' ] );

		$registered = (array) get_option( 'stonewright_registered_widgets', [] );
		$count = count( array_filter( $registered, static fn( $s ) => $s === 'int-widget' ) );
		$this->assertSame( 1, $count, 'slug must appear exactly once in option' );
	}

	public function test_define_overwrites_existing_pending_file(): void {
		( new WidgetDefine() )->execute( self::define_args() );
		$mtime1 = filemtime( SandboxFiles::draft_dir() . '/widget-int-widget.pending.php' );

		// Sleep 1s to ensure mtime differs.
		sleep( 1 );
		( new WidgetDefine() )->execute( self::define_args() );
		$mtime2 = filemtime( SandboxFiles::draft_dir() . '/widget-int-widget.pending.php' );

		$this->assertGreaterThan( (int) $mtime1, (int) $mtime2 );
	}

	public function test_register_non_existent_slug_returns_wp_error(): void {
		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'non-existent-widget' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_widget_pending_not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// WidgetRegister — confirmation token roundtrip (production-safe mode)
	// -------------------------------------------------------------------------

	public function test_register_requires_confirmation_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'token-test-widget' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_register_valid_confirmation_token_passes_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		// First define the widget so a pending file exists.
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		( new WidgetDefine() )->execute( self::define_args( 'token-widget' ) );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$token_args = [ 'widget_slug' => 'token-widget' ];
		$token      = ConfirmationToken::issue( 'stonewright/elementor-widget-register', $token_args );

		$result = ( new WidgetRegister() )->execute(
			array_merge( $token_args, [ 'confirmation_token' => $token ] )
		);

		// Must NOT fail due to token validation.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'stonewright_confirmation_required',
				$result->get_error_code(),
				'Token check should pass: ' . $result->get_error_message()
			);
			$this->assertNotSame(
				'stonewright_confirmation_invalid',
				$result->get_error_code(),
				'Token check should pass: ' . $result->get_error_message()
			);
		} else {
			$this->assertTrue( (bool) ( $result['ok'] ?? false ) );
		}
	}

	// -------------------------------------------------------------------------
	// WidgetList
	// -------------------------------------------------------------------------

	public function test_list_shows_sandboxed_pending_widget(): void {
		( new WidgetDefine() )->execute( self::define_args( 'list-pending' ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$result = ( new WidgetList() )->execute( [] );

		$this->assertTrue( (bool) ( $result['ok'] ?? false ) );
		$slugs    = array_column( $result['widgets'], 'widget_slug' );
		$statuses = array_column( $result['widgets'], 'status' );

		$this->assertContains( 'list-pending', $slugs );
		$idx = array_search( 'list-pending', $slugs, true );
		$this->assertSame( 'sandboxed', $statuses[ $idx ] );
	}

	public function test_list_shows_active_registered_widget(): void {
		( new WidgetDefine() )->execute( self::define_args( 'list-active' ) );
		( new WidgetRegister() )->execute( [ 'widget_slug' => 'list-active' ] );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$result = ( new WidgetList() )->execute( [] );

		$this->assertTrue( (bool) ( $result['ok'] ?? false ) );
		$slugs    = array_column( $result['widgets'], 'widget_slug' );
		$statuses = array_column( $result['widgets'], 'status' );

		$this->assertContains( 'list-active', $slugs );
		$idx = array_search( 'list-active', $slugs, true );
		$this->assertSame( 'active', $statuses[ $idx ] );
	}
}

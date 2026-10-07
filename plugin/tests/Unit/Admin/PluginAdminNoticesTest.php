<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminBootstrap;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Core\VendorGuard;
use Stonewright\WpMcp\Sandbox\CrashRecovery;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * The notices the plugin prints through admin_notices appear on Stonewright pages too.
 * The shell moves every notice it does not recognise as the plugin's own into a collapsed
 * drawer, so each of these must carry a sw-* or stonewright-* class (shell.js isForeignNotice).
 *
 * @coversNothing
 */
final class PluginAdminNoticesTest extends TestCase {

	private const OWNED_CLASS = '/(^|\s)(sw|stonewright)-/';

	/** @var list<string> */
	private array $created_files = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_environment_type'] = 'production';
		VendorGuard::reset_for_tests();
	}

	protected function tearDown(): void {
		foreach ( $this->created_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->created_files                         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_environment_type'] = 'local';
		unset( $GLOBALS['stonewright_test_home_url'] );
		VendorGuard::reset_for_tests();
	}

	/** Every notice root a callback printed, as its class attribute. @return list<string> */
	private static function notice_classes( callable $print ): array {
		ob_start();
		$print();
		$html = (string) ob_get_clean();

		self::assertNotSame( '', $html, 'The notice must render in this state.' );
		preg_match_all( '/<div\b[^>]*\bclass="([^"]*\bnotice\b[^"]*)"/', $html, $matches );
		self::assertNotEmpty( $matches[1], 'The callback prints a WordPress notice.' );

		return $matches[1];
	}

	private static function assert_owned( array $classes, string $what ): void {
		foreach ( $classes as $class ) {
			self::assertMatchesRegularExpression( self::OWNED_CLASS, $class, $what . ' must carry a sw-* or stonewright-* class so the shell keeps it in place: "' . $class . '"' );
		}
	}

	public function test_the_production_mode_notice_is_marked_as_the_plugins(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';

		self::assert_owned( self::notice_classes( [ AdminBootstrap::class, 'production_mode_mismatch_notice' ] ), 'The production mode notice' );
	}

	public function test_the_domain_mismatch_notice_is_marked_as_the_plugins(): void {
		$GLOBALS['stonewright_test_options']['stonewright_locked_domain'] = 'https://old.example/';
		$GLOBALS['stonewright_test_home_url']                             = 'https://new.example/';

		self::assert_owned( self::notice_classes( [ PluginRegistration::class, 'domain_mismatch_admin_notice' ] ), 'The domain mismatch notice' );
	}

	public function test_the_missing_vendor_notice_is_marked_as_the_plugins(): void {
		VendorGuard::set_error_for_tests( VendorGuard::missing_vendor_error() );

		self::assert_owned( self::notice_classes( [ VendorGuard::class, 'render_admin_notice' ] ), 'The missing dependency notice' );
	}

	public function test_the_sandbox_crash_notice_is_marked_as_the_plugins(): void {
		$directory = SandboxFiles::mu_dir();
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}
		$file = $directory . '/' . SandboxFiles::active_prefix() . 'notice-ownership.php.crashed';
		file_put_contents( $file, "<?php\n" );
		$this->created_files[] = $file;

		self::assert_owned( self::notice_classes( [ CrashRecovery::class, 'admin_notice' ] ), 'The sandbox crash notice' );
	}
}

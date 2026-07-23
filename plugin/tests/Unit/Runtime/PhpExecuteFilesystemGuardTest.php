<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Security\ProtectedFilesystemWriteGuard;

/**
 * Incident regression: toxic theme append via php-execute must be BLOCKED.
 *
 * @covers \Stonewright\WpMcp\Security\ProtectedFilesystemWriteGuard
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 */
final class PhpExecuteFilesystemGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']          = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_current_user_id']    = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']       = [];
		$GLOBALS['stonewright_test_options']            = [
			'stonewright_mode'                  => 'development',
			'stonewright_essential_tools_mode'  => true,
			'stonewright_disabled_abilities'    => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_file_put_contents_on_functions_php_is_blocked(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'file_put_contents(get_stylesheet_directory() . "/functions.php", "<?php\\nobfuscated = 1;\\n", FILE_APPEND); return "ok";',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_code_file_write_blocked', $result->get_error_code() );
		self::assertFalse( (bool) ( $result->get_error_data()['retryable'] ?? true ) );
		self::assertStringContainsString( 'theme-file-patch', $result->get_error_message() );
	}

	/**
	 * @dataProvider mutation_variants
	 */
	public function test_indirect_filesystem_mutations_are_blocked( string $code ): void {
		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_code_file_write_blocked', $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function mutation_variants(): array {
		return [
			'fwrite'     => [ '$h=fopen("/tmp/x","w"); fwrite($h,"x"); return 1;' ],
			'copy'       => [ 'copy("/a.php","/b.php"); return 1;' ],
			'rename'     => [ 'rename("/a.php","/b.php"); return 1;' ],
			'unlink'     => [ 'unlink("/a.php"); return 1;' ],
			'wp_fs'      => [ 'global $wp_filesystem; $wp_filesystem->put_contents("functions.php","x"); return 1;' ],
			'call_user'  => [ 'call_user_func("file_put_contents", "/x.php", "y"); return 1;' ],
			'reflection' => [ '$r=new ReflectionFunction("file_put_contents"); $r->invoke("/x.php","y"); return 1;' ],
			'variable_fn' => [ '$fn="file_put_contents"; $fn("/x.php","y"); return 1;' ],
			'computed_fn' => [ '$fn="file_" . "put_contents"; $fn("/x.php","y"); return 1;' ],
			'shell'       => [ 'shell_exec("printf x > functions.php"); return 1;' ],
			'eval'        => [ 'eval(\'file_put_contents("/x.php","y");\'); return 1;' ],
			'spl_file'    => [ '$f=new SplFileObject("/x.php","w"); $f->fwrite("y"); return 1;' ],
			'grant_issue' => [ 'return \Stonewright\WpMcp\Security\CustomCodeGrant::issue(["path"=>"functions.php"]);' ],
			'grant_approve' => [ 'return \Stonewright\WpMcp\Security\CustomCodeGrant::approve_proposal("proposal");' ],
			'transaction_apply' => [ 'return \Stonewright\WpMcp\Security\ThemeWriteTransaction::apply([]);' ],
			'theme_ability' => [ 'return (new \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch())->execute([]);' ],
			'dynamic_static_class' => [ '$class="Stonewright\\\\WpMcp\\\\Security\\\\CustomCodeGrant"; return $class::issue([]);' ],
			'callable_grant' => [ 'return call_user_func([\Stonewright\WpMcp\Security\CustomCodeGrant::class,"issue"], []);' ],
		];
	}

	public function test_runtime_api_reads_still_allowed(): void {
		$result = ( new PhpExecute() )->execute(
			[
				'code' => 'return ["name" => get_bloginfo("name")];',
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	public function test_guard_detects_operations(): void {
		$ops = ProtectedFilesystemWriteGuard::detect_operations( 'file_put_contents($p, $c);' );
		self::assertContains( 'file_put_contents', $ops );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\PhpSyntaxValidator;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 * @covers \Stonewright\WpMcp\Security\PhpSyntaxValidator
 */
final class ThemeWriteTransactionTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/sw-txn-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode' => 'development',
		];
		// Force smoke skip in unit env.
		if ( ! defined( 'STONEWRIGHT_PHPUNIT' ) ) {
			define( 'STONEWRIGHT_PHPUNIT', true );
		}
	}

	protected function tearDown(): void {
		$this->rmTree( $this->dir );
	}

	public function test_invalid_complete_php_candidate_rejected_before_write(): void {
		$path = $this->dir . '/functions.php';
		$before = "<?php\n// ok\n";
		file_put_contents( $path, $before );

		// Fragment would look fine alone but is invalid in complete file context.
		$after = $before . "obfuscated = array(1);\n";

		$result = ThemeWriteTransaction::apply(
			[
				'absolute'   => $path,
				'relative'   => 'functions.php',
				'before'     => $before,
				'after'      => $after,
				'language'   => 'php',
				'skip_smoke' => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );
		// Target must remain original.
		self::assertSame( $before, file_get_contents( $path ) );
	}

	public function test_valid_php_candidate_writes_with_readback(): void {
		$path = $this->dir . '/functions.php';
		$before = "<?php\n// ok\n";
		file_put_contents( $path, $before );
		$after = "<?php\n// ok\nfunction sw_test_safe(){ return 1; }\n";

		$result = ThemeWriteTransaction::apply(
			[
				'absolute'   => $path,
				'relative'   => 'functions.php',
				'before'     => $before,
				'after'      => $after,
				'language'   => 'php',
				'skip_smoke' => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( hash( 'sha256', $after ), hash( 'sha256', (string) file_get_contents( $path ) ) );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( 'not_needed', $result['rollback_status'] );
	}

	public function test_rollback_restores_original_hash(): void {
		$path = $this->dir . '/functions.php';
		$before = "<?php\n// original\n";
		file_put_contents( $path, "<?php\n// corrupted\n" );
		$hash = hash( 'sha256', $before );

		$rb = ThemeWriteTransaction::rollback( $path, $before, $hash );
		self::assertSame( 'succeeded', $rb['status'] );
		self::assertSame( $hash, hash( 'sha256', (string) file_get_contents( $path ) ) );
	}

	public function test_validator_accepts_valid_and_rejects_parse_error(): void {
		self::assertTrue( PhpSyntaxValidator::validate_complete_file( "<?php\nreturn 1;\n" ) );
		$bad = PhpSyntaxValidator::validate_complete_file( "<?php\nif (\n" );
		self::assertInstanceOf( \WP_Error::class, $bad );
	}

	private function rmTree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rmTree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}

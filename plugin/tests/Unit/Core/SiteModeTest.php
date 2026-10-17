<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Stonewright\WpMcp\Core\SiteMode;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Every gate reads the mode through SiteMode, which fails safe: a mode that was never stored follows
 * the environment type and an invalid one counts as production-safe.
 *
 * @covers \Stonewright\WpMcp\Core\SiteMode
 * @covers \Stonewright\WpMcp\Security\Permissions
 */
final class SiteModeTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		unset( $GLOBALS['stonewright_test_environment_type'], $GLOBALS['stonewright_test_object_cache'] );
	}

	private static function environment( string $type ): void {
		$GLOBALS['stonewright_test_environment_type'] = $type;
	}

	private static function store( mixed $value ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = $value;
	}

	private static function admin(): void {
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	/** @return array<string,array{string}> */
	public static function environments(): array {
		return [
			'production'  => [ 'production' ],
			'staging'     => [ 'staging' ],
			'development' => [ 'development' ],
			'local'       => [ 'local' ],
		];
	}

	/** @dataProvider environments */
	public function test_every_valid_mode_is_returned_as_stored_in_every_environment( string $environment ): void {
		self::environment( $environment );
		foreach ( [ 'development', 'staging', 'production-safe' ] as $mode ) {
			self::store( $mode );
			self::assertSame( $mode, SiteMode::stored() );
			self::assertSame( $mode, SiteMode::current() );
			self::assertSame( 'production-safe' === $mode, SiteMode::is_production_safe() );
		}
	}

	/** @return array<string,array{string,string}> */
	public static function missing_mode(): array {
		return [
			'production'  => [ 'production', 'production-safe' ],
			'staging'     => [ 'staging', 'staging' ],
			'development' => [ 'development', 'development' ],
			'local'       => [ 'local', 'development' ],
		];
	}

	/** @dataProvider missing_mode */
	public function test_a_missing_or_empty_mode_follows_the_environment( string $environment, string $expected ): void {
		self::environment( $environment );

		self::assertNull( SiteMode::stored() );
		self::assertSame( $expected, SiteMode::current() );
		self::assertSame( 'production-safe' === $expected, SiteMode::is_production_safe() );

		self::store( '' );
		self::assertNull( SiteMode::stored() );
		self::assertSame( $expected, SiteMode::current() );
	}

	/** @return array<string,array{mixed}> */
	public static function invalid_values(): array {
		return [
			'unknown string'  => [ 'foo' ],
			'upper case'      => [ 'Production-Safe' ],
			'development cap' => [ 'Development' ],
			'padded'          => [ ' staging ' ],
			'underscore'      => [ 'production_safe' ],
			'array'           => [ [ 'development' ] ],
			'empty array'     => [ [] ],
			'int'             => [ 1 ],
			'zero'            => [ 0 ],
			'true'            => [ true ],
			'false'           => [ false ],
		];
	}

	/** @dataProvider invalid_values */
	public function test_an_invalid_stored_value_counts_as_production_safe( mixed $value ): void {
		foreach ( [ 'local', 'production' ] as $environment ) {
			self::environment( $environment );
			self::store( $value );

			self::assertNotNull( SiteMode::stored(), 'An invalid value is not "never stored".' );
			self::assertSame( 'production-safe', SiteMode::current() );
			self::assertTrue( SiteMode::is_production_safe() );
		}
	}

	public function test_resolve_applies_the_same_rules_to_a_value_about_to_be_stored(): void {
		self::environment( 'local' );
		self::assertSame( 'development', SiteMode::resolve( null ) );
		self::assertSame( 'development', SiteMode::resolve( '' ) );
		self::assertSame( 'staging', SiteMode::resolve( 'staging' ) );
		self::assertSame( 'production-safe', SiteMode::resolve( 'Staging' ) );
		self::assertSame( 'production-safe', SiteMode::resolve( [ 'development' ] ) );
		self::assertSame( 'production-safe', SiteMode::resolve( 0 ) );

		self::environment( 'production' );
		self::assertSame( 'production-safe', SiteMode::resolve( '' ) );
	}

	public function test_a_registered_default_does_not_hide_a_missing_mode(): void {
		// register_setting() with a default adds a default_option filter that answers a read without a default.
		$GLOBALS['stonewright_test_filters']['default_option_stonewright_mode'] = static fn ( mixed $default, string $option, bool $passed_default ): mixed => $passed_default ? $default : 'development';
		self::environment( 'production' );

		self::assertNull( SiteMode::stored() );
		self::assertSame( 'production-safe', SiteMode::current() );
	}

	public function test_a_default_filter_cannot_hide_a_row_wordpress_knows_is_missing(): void {
		// A filter that ignores the passed default and answers development, on a production site.
		$GLOBALS['stonewright_test_filters']['default_option_stonewright_mode'] = static fn (): string => 'development';
		$GLOBALS['stonewright_test_object_cache']['options']['notoptions']        = [ 'stonewright_mode' => true ];
		self::environment( 'production' );

		self::assertNull( SiteMode::stored() );
		self::assertSame( 'production-safe', SiteMode::current() );
	}

	public function test_a_stored_mode_wins_over_an_unrelated_notoptions_entry(): void {
		$GLOBALS['stonewright_test_object_cache']['options']['notoptions'] = [ 'another_option' => true ];
		self::environment( 'production' );
		self::store( 'development' );

		self::assertSame( 'development', SiteMode::stored() );
		self::assertSame( 'development', SiteMode::current() );
	}

	public function test_reading_never_writes_the_option(): void {
		self::environment( 'production' );

		SiteMode::current();
		SiteMode::is_production_safe();
		SiteMode::stored();

		self::assertArrayNotHasKey( 'stonewright_mode', $GLOBALS['stonewright_test_options'] );
	}

	public function test_permissions_with_the_mode_missing_on_a_production_environment(): void {
		self::environment( 'production' );
		self::admin();

		self::assertTrue( Permissions::is_production_safe() );
		self::assertFalse( Permissions::not_production_safe() );
		self::assertFalse( Permissions::destructive() );
	}

	public function test_permissions_with_the_mode_missing_on_a_development_environment(): void {
		self::environment( 'local' );
		self::admin();

		self::assertFalse( Permissions::is_production_safe() );
		self::assertTrue( Permissions::not_production_safe() );
		self::assertTrue( Permissions::destructive() );
	}

	public function test_permissions_with_an_invalid_mode_stay_strict(): void {
		self::environment( 'local' );
		self::admin();
		self::store( 'foo' );

		self::assertTrue( Permissions::is_production_safe() );
		self::assertFalse( Permissions::not_production_safe() );
		self::assertFalse( Permissions::destructive() );
	}

	public function test_no_file_outside_site_mode_reads_the_option_directly(): void {
		$root     = dirname( __DIR__, 3 ) . '/includes';
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$offenders = [];
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() || 'SiteMode.php' === $file->getFilename() ) {
				continue;
			}
			if ( preg_match( '/get_option\(\s*[\'"]stonewright_mode[\'"]/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$offenders[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		self::assertSame( [], $offenders, 'Read the mode through SiteMode::current() or SiteMode::is_production_safe().' );
	}
}

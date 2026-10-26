<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\LateLoad;

/**
 * @covers \Stonewright\WpMcp\Support\LateLoad
 */
final class LateLoadTest extends TestCase {

	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/stonewright-late-load-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '/';
		mkdir( $this->root . 'includes/Security', 0700, true );
		LateLoad::set_root( $this->root );
	}

	protected function tearDown(): void {
		LateLoad::set_root( null );
		foreach ( glob( $this->root . 'includes/*/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		foreach ( glob( $this->root . 'includes/*' ) ?: [] as $dir ) {
			rmdir( $dir );
		}
		rmdir( $this->root . 'includes' );
		rmdir( $this->root );
	}

	public function test_loaded_class_is_ready_without_touching_disk(): void {
		// The root is an empty directory, so a disk lookup for this class would fail.
		self::assertTrue( LateLoad::ready( LateLoad::class ) );
	}

	public function test_unloaded_class_with_missing_file_is_not_ready(): void {
		self::assertFalse( LateLoad::ready( 'Stonewright\\WpMcp\\Security\\VanishedFixtureClass' ) );
	}

	public function test_unloaded_class_with_a_readable_file_is_ready_and_stays_unloaded(): void {
		file_put_contents( $this->root . 'includes/Security/PresentFixtureClass.php', "<?php\n" );

		self::assertTrue( LateLoad::ready( 'Stonewright\\WpMcp\\Security\\PresentFixtureClass' ) );
		self::assertFalse( class_exists( 'Stonewright\\WpMcp\\Security\\PresentFixtureClass', false ), 'Checking must never load the file.' );
	}

	public function test_every_class_must_be_ready(): void {
		file_put_contents( $this->root . 'includes/Security/PresentFixtureClass.php', "<?php\n" );

		self::assertFalse(
			LateLoad::ready(
				'Stonewright\\WpMcp\\Security\\PresentFixtureClass',
				'Stonewright\\WpMcp\\Security\\VanishedFixtureClass'
			)
		);
		self::assertTrue( LateLoad::ready( 'Stonewright\\WpMcp\\Security\\PresentFixtureClass', LateLoad::class ) );
	}

	public function test_a_class_outside_the_plugin_namespace_is_ready_only_when_already_loaded(): void {
		self::assertTrue( LateLoad::ready( \stdClass::class ) );
		self::assertFalse( LateLoad::ready( 'Other\\Vendor\\NeverLoaded' ) );
	}

	public function test_no_class_names_means_ready(): void {
		self::assertTrue( LateLoad::ready() );
	}

	public function test_resetting_the_root_follows_the_plugin_directory_again(): void {
		LateLoad::set_root( null );

		self::assertFalse( LateLoad::ready( 'Stonewright\\WpMcp\\Security\\VanishedFixtureClass' ) );
		self::assertSame( rtrim( STONEWRIGHT_DIR, '/\\' ), rtrim( LateLoad::root(), '/\\' ) );
	}
}

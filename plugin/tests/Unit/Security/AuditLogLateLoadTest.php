<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Support\LateLoad;
use Stonewright\WpMcp\Support\StoreSchemaRepair;

/**
 * The daily retention event reaches the stores through StoreSchemaRepair and ErrorPatterns. If any class on that
 * path is gone from disk the event must return before it loads the first of them. Each test runs in its own process,
 * so none of the classes is loaded yet.
 *
 * @covers \Stonewright\WpMcp\Security\AuditLog::run_scheduled_retention
 */
final class AuditLogLateLoadTest extends TestCase {

	private const FILES = [
		'Support/StoreSchemaRepair',
		'Support/SchemaGate',
		'Support/Logger',
		'Security/ErrorPatterns',
		'Memory/Memory',
		'Security/ChangeLedger',
		'Security/IncidentStore',
	];

	/** @return array<string, array{0: string}> */
	public static function classes_on_the_retention_path(): array {
		$cases = [];
		foreach ( self::FILES as $file ) {
			$cases[ $file ] = [ $file ];
		}
		return $cases;
	}

	/**
	 * @dataProvider classes_on_the_retention_path
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_retention_returns_before_loading_anything_when_a_class_on_its_path_is_gone( string $missing ): void {
		$root = sys_get_temp_dir() . '/stonewright-audit-late-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '/';
		$made = [];
		foreach ( self::FILES as $file ) {
			if ( $file === $missing ) {
				continue;
			}
			@mkdir( $root . 'includes/' . dirname( $file ), 0700, true );
			file_put_contents( $root . 'includes/' . $file . '.php', "<?php\n" );
			$made[] = $root . 'includes/' . $file . '.php';
		}
		LateLoad::set_root( $root );

		try {
			AuditLog::run_scheduled_retention();

			self::assertFalse( class_exists( StoreSchemaRepair::class, false ), $missing . ' is gone, so the event did not start.' );
		} finally {
			foreach ( $made as $path ) {
				unlink( $path );
			}
			foreach ( glob( $root . 'includes/*' ) ?: [] as $dir ) {
				rmdir( $dir );
			}
			@rmdir( $root . 'includes' );
			@rmdir( $root );
		}
	}
}

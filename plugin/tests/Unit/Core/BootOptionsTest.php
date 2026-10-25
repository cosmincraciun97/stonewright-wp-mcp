<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Stonewright\WpMcp\Core\BootOptions;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Design\Direction\DesignDirectionsTable;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;
use Stonewright\WpMcp\Elementor\WidgetBuilder\RegisteredWidgets;
use Stonewright\WpMcp\Elementor\WidgetBuilder\ScanCache;
use Stonewright\WpMcp\Expertise\ExpertiseTable;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\StaticAnalysis;
use Stonewright\WpMcp\SkillLibrary\Site\SkillTables;

/**
 * Every option the plugin reads on a request is stored autoloaded, so WordPress loads it with the
 * rest of the autoloaded set in one query instead of one query per option on a cold object cache.
 *
 * @covers \Stonewright\WpMcp\Core\BootOptions
 */
final class BootOptionsTest extends TestCase {

	/**
	 * Options written here with update_option(): the class and the constant that names each.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	private static function writers(): array {
		return [
			'skills table version'            => [ SkillTables::class, 'SKILLS_OPTION' ],
			'skill versions table version'    => [ SkillTables::class, 'VERSIONS_OPTION' ],
			'design directions table version' => [ DesignDirectionsTable::class, 'VERSION_OPTION' ],
			'design versions table version'   => [ DesignDirectionVersionsTable::class, 'VERSION_OPTION' ],
			'knowledge candidates version'    => [ CandidateTable::class, 'VERSION_OPTION' ],
			'expertise tables version'        => [ ExpertiseTable::class, 'VERSION_OPTION' ],
			'draft lessons returned flag'     => [ ErrorPatterns::class, 'DRAFT_LESSON_RETURN_OPTION' ],
			'legacy lessons migrated flag'    => [ ErrorPatterns::class, 'LEGACY_LESSON_MIGRATION_OPTION' ],
			'dangerous functions notice'      => [ StaticAnalysis::class, 'NOTICE_OPTION' ],
			'registered widget slugs'         => [ RegisteredWidgets::class, 'OPTION_KEY' ],
			'generated widget scan verdicts'  => [ ScanCache::class, 'OPTION' ],
			'locked domain'                   => [ DomainLock::class, 'OPTION_LOCKED' ],
			'domain mismatch record'          => [ DomainLock::class, 'OPTION_MISMATCH' ],
		];
	}

	/**
	 * The four store schema options Task 1 moved to SchemaGate, and the memory one.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	private static function schema_options(): array {
		return [
			'memory schema version'        => [ Memory::class, 'SCHEMA_OPTION' ],
			'audit schema version'         => [ AuditLog::class, 'SCHEMA_OPTION' ],
			'change ledger schema version' => [ ChangeLedger::class, 'SCHEMA_OPTION' ],
			'incident schema version'      => [ IncidentStore::class, 'SCHEMA_OPTION' ],
		];
	}

	/** A constant may be private: reflection reads it without changing its visibility. */
	private static function option_name( string $class, string $constant ): string {
		$value = ( new ReflectionClassConstant( $class, $constant ) )->getValue();
		self::assertIsString( $value );

		return $value;
	}

	private static function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$lines      = file( (string) $reflection->getFileName() );
		self::assertIsArray( $lines );

		return implode(
			'',
			array_slice(
				$lines,
				$reflection->getStartLine() - 1,
				$reflection->getEndLine() - $reflection->getStartLine() + 1
			)
		);
	}

	public function test_names_match_the_owning_class_constants(): void {
		$expected = [];
		foreach ( array_merge( self::writers(), self::schema_options() ) as [ $class, $constant ] ) {
			$expected[] = self::option_name( $class, $constant );
		}

		self::assertCount( 17, BootOptions::NAMES );
		self::assertSame( BootOptions::NAMES, array_values( array_unique( BootOptions::NAMES ) ), 'no name twice' );
		self::assertEqualsCanonicalizing( $expected, BootOptions::NAMES );
	}

	public function test_every_boot_option_writer_passes_autoload_true(): void {
		foreach ( self::writers() as $label => [ $class, $constant ] ) {
			$lines = file( (string) ( new ReflectionClass( $class ) )->getFileName() );
			self::assertIsArray( $lines );
			$seen = 0;
			foreach ( $lines as $line ) {
				if ( 1 !== preg_match( '/\bupdate_option\(\s*self::' . $constant . '\b(.*)$/', $line, $call ) ) {
					continue;
				}
				++$seen;
				self::assertMatchesRegularExpression( '/,\s*true\s*\)\s*;/', $call[1], $label . ' is written with autoload true' );
			}
			self::assertGreaterThan( 0, $seen, $label . ' has an update_option() call to check' );
		}
	}

	public function test_maybe_upgrade_autoloads_every_boot_option_before_it_stores_the_version(): void {
		$body = self::method_source( PluginRegistration::class, 'maybe_upgrade' );

		self::assertStringContainsString( 'BootOptions::autoload_all()', $body );
		self::assertLessThan(
			strpos( $body, "update_option( 'stonewright_version'" ),
			strpos( $body, 'BootOptions::autoload_all()' ),
			'a run that fails before the version is stored runs again'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_autoload_all_sets_every_known_name(): void {
		require_once __DIR__ . '/Fixtures/AutoloadValuesFunction.php';
		$GLOBALS['stonewright_test_autoload_value_calls'] = [];

		BootOptions::autoload_all();

		self::assertSame(
			[ array_fill_keys( BootOptions::NAMES, true ) ],
			$GLOBALS['stonewright_test_autoload_value_calls'],
			'one call, every name, autoload true'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_the_wordpress_6_4_function_each_stored_option_is_re_added_autoloaded(): void {
		self::assertFalse( function_exists( 'wp_set_option_autoload_values' ), 'this process has no WordPress 6.4 function' );
		$stored                                      = [
			'stonewright_registered_widgets'    => [ 'hero-card' ],
			'stonewright_memory_schema_version' => '7',
		];
		$GLOBALS['stonewright_test_options']         = $stored;
		$GLOBALS['stonewright_test_option_autoload'] = [
			'stonewright_registered_widgets'    => false,
			'stonewright_memory_schema_version' => false,
		];

		BootOptions::autoload_all();

		foreach ( $stored as $name => $value ) {
			self::assertSame( $value, get_option( $name ), $name . ' keeps its value' );
			self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ $name ], $name . ' is autoloaded now' );
		}
		self::assertEqualsCanonicalizing( array_keys( $stored ), array_keys( $GLOBALS['stonewright_test_options'] ), 'an option that was never stored is not created' );
	}
}

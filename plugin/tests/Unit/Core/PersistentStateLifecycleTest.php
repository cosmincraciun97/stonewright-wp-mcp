<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Skills\SkillsSeeder;
use Stonewright\WpMcp\Skills\SkillsTable;

/**
 * Locks the non-destructive install/upgrade contract for persistent site state.
 */
final class PersistentStateLifecycleTest extends TestCase {

	public function test_activation_creates_schema_and_builtins_without_user_state_rows(): void {
		$source = self::method_source( PluginRegistration::class, 'on_activate' );

		self::assertStringContainsString( 'Memory::maybe_install_table()', $source );
		self::assertStringContainsString( 'AuditLog::maybe_install_table()', $source );
		self::assertStringContainsString( 'SkillsSeeder::seed()', $source );
		self::assertStringNotContainsString( 'Memory::put', $source );
		self::assertStringNotContainsString( 'AuditLog::record', $source );
		self::assertDoesNotMatchRegularExpression( '/\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i', $source );
	}

	public function test_version_change_reseeds_packaged_skills_without_wiping_tables(): void {
		$upgrade = self::method_source( PluginRegistration::class, 'maybe_upgrade' );
		$hooks   = self::method_source( PluginRegistration::class, 'register_hooks' );

		self::assertStringContainsString( 'SkillsSeeder::seed()', $upgrade );
		self::assertStringContainsString( "get_option( 'stonewright_version'", $upgrade );
		self::assertStringContainsString( 'STONEWRIGHT_VERSION', $upgrade );
		self::assertStringContainsString( 'maybe_upgrade', $hooks );
		self::assertDoesNotMatchRegularExpression( '/\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i', $upgrade );
	}

	public function test_schema_upgrades_do_not_reset_memory_skills_or_audit(): void {
		$methods = [
			self::method_source( Memory::class, 'maybe_install_table' ),
			self::method_source( AuditLog::class, 'maybe_install_table' ),
			self::method_source( SkillsTable::class, 'run_delta' ),
			self::method_source( SkillsSeeder::class, 'seed' ),
		];

		foreach ( $methods as $source ) {
			self::assertDoesNotMatchRegularExpression( '/\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i', $source );
		}
	}

	private static function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$file       = (string) $reflection->getFileName();
		$lines      = file( $file );
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
}

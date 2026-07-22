<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Memory;

/**
 * @covers \Stonewright\WpMcp\Memory\Memory
 */
final class MemorySchemaTest extends TestCase {

	private mixed $original_wpdb;

	/** @var list<string> */
	private const V3_COLUMNS = [
		'id',
		'scope',
		'type',
		'name',
		'memory_key',
		'value_json',
		'confidence',
		'topic',
		'version_fingerprint',
		'expires_at',
		'status',
		'precedence',
		'created_by',
		'created_at',
		'updated_at',
	];

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_schema_ok_false_when_table_missing(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( [] );

		self::assertFalse( Memory::table_schema_ok() );
	}

	public function test_schema_ok_false_when_columns_incomplete(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( [ 'id', 'scope', 'memory_key', 'value_json' ] );

		self::assertFalse( Memory::table_schema_ok() );
	}

	public function test_schema_ok_true_when_all_v3_columns_present(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( self::V3_COLUMNS );

		self::assertTrue( Memory::table_schema_ok() );
	}

	public function test_schema_version_not_bumped_when_columns_missing(): void {
		delete_option( 'stonewright_memory_schema_version' );
		$GLOBALS['wpdb'] = $this->make_wpdb( [] );

		Memory::maybe_install_table();

		self::assertFalse( Memory::table_schema_ok() );
		self::assertSame( 0, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	public function test_schema_ok_after_install_bumps_version_to_3(): void {
		delete_option( 'stonewright_memory_schema_version' );
		// Simulate successful dbDelta: columns present after install.
		$GLOBALS['wpdb'] = $this->make_wpdb( self::V3_COLUMNS );

		Memory::maybe_install_table();

		self::assertTrue( Memory::table_schema_ok() );
		self::assertSame( 3, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	public function test_maybe_install_skips_when_version_already_current(): void {
		update_option( 'stonewright_memory_schema_version', 3 );
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $charset_calls = 0;

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return '';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}
		};

		Memory::maybe_install_table();

		// Early return must not touch charset / dbDelta path.
		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 3, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	/**
	 * @param array<int, string> $columns
	 */
	private function make_wpdb( array $columns ): object {
		return new class( $columns ) {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			private array $columns;

			/** @param array<int, string> $columns */
			public function __construct( array $columns ) {
				$this->columns = $columns;
			}

			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->columns;
			}
		};
	}
}

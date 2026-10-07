<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository
 */
final class WidgetSchemaCacheTest extends TestCase {

	private const INDEX_OPTION = 'stonewright_elementor_schema_cache_keys';

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options'] = [
			'active_plugins'                         => [],
			'elementor_experiment-container'         => 'active',
			'elementor_experiment-e_atomic_elements' => 'inactive',
		];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true ];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					if ( null === $name ) {
						return [];
					}
					return str_starts_with( $name, 'bulk-' ) ? new BulkCacheWidget( $name ) : null;
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance                = $this->original_elementor;
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_persistent_cache_stays_within_the_entry_and_byte_budget(): void {
		for ( $index = 0; $index < 500; ++$index ) {
			self::assertIsArray( WidgetSchemaRepository::get( 'bulk-' . $index ) );
		}

		$entries = $GLOBALS['stonewright_test_transients'];
		$bytes   = array_sum( array_map( static fn( mixed $value ): int => strlen( serialize( $value ) ), $entries ) );
		self::assertLessThanOrEqual( 300, count( $entries ) );
		self::assertLessThanOrEqual( 6 * 1024 * 1024, $bytes );
		self::assertCount( count( $entries ), (array) get_option( self::INDEX_OPTION ) );
		foreach ( $GLOBALS['stonewright_test_transient_ttls'] as $ttl ) {
			self::assertGreaterThan( 0, $ttl );
			self::assertLessThanOrEqual( 43200, $ttl );
		}
	}

	public function test_a_schema_evicted_from_the_cache_is_rebuilt_identically(): void {
		$first = WidgetSchemaRepository::get( 'bulk-0' );
		for ( $index = 1; $index < 500; ++$index ) {
			WidgetSchemaRepository::get( 'bulk-' . $index );
		}
		WidgetSchemaRepository::reset_request_cache();

		$again = WidgetSchemaRepository::get( 'bulk-0' );

		self::assertIsArray( $first );
		self::assertIsArray( $again );
		self::assertSame( $first['schema_hash'], $again['schema_hash'] );
		self::assertSame( $first['controls'], $again['controls'] );
	}

	public function test_cached_record_is_compact_and_served_back_unchanged(): void {
		$first = WidgetSchemaRepository::get( 'bulk-1' );
		self::assertIsArray( $first );
		$stored = array_values( $GLOBALS['stonewright_test_transients'] )[0];
		self::assertLessThan( strlen( serialize( $first ) ) / 2, strlen( serialize( $stored ) ) );

		WidgetSchemaRepository::reset_request_cache();
		$from_cache = WidgetSchemaRepository::get( 'bulk-1' );

		self::assertSame( $first, $from_cache );
	}

	public function test_entries_of_an_older_runtime_fingerprint_are_dropped_when_the_fingerprint_changes(): void {
		WidgetSchemaRepository::get( 'bulk-1' );
		WidgetSchemaRepository::get( 'bulk-2' );
		self::assertCount( 2, $GLOBALS['stonewright_test_transients'] );
		$before = RuntimeFingerprint::describe()['hash'];

		$GLOBALS['stonewright_test_options']['elementor_experiment-container'] = 'inactive';
		WidgetSchemaRepository::reset_request_cache();
		$record = WidgetSchemaRepository::get( 'bulk-1' );

		self::assertIsArray( $record );
		self::assertNotSame( $before, $record['runtime_fingerprint'] );
		self::assertCount( 1, $GLOBALS['stonewright_test_transients'] );
		self::assertCount( 1, (array) get_option( self::INDEX_OPTION ) );
	}

	public function test_a_record_stored_for_another_fingerprint_is_not_served(): void {
		WidgetSchemaRepository::get( 'bulk-1' );
		$key = (string) array_key_first( $GLOBALS['stonewright_test_transients'] );
		$GLOBALS['stonewright_test_transients'][ $key ] = [ 'widget_type' => 'bulk-1', 'runtime_fingerprint' => 'stale', 'title' => 'Stale' ];
		WidgetSchemaRepository::reset_request_cache();

		$record = WidgetSchemaRepository::get( 'bulk-1' );

		self::assertIsArray( $record );
		self::assertNotSame( 'Stale', $record['title'] );
	}

	public function test_a_corrupt_cached_payload_is_a_miss_not_an_error(): void {
		WidgetSchemaRepository::get( 'bulk-1' );
		$key = (string) array_key_first( $GLOBALS['stonewright_test_transients'] );
		$GLOBALS['stonewright_test_transients'][ $key ] = 'z1:not-valid-base64-or-deflate!';
		WidgetSchemaRepository::reset_request_cache();

		$record = WidgetSchemaRepository::get( 'bulk-1' );

		self::assertIsArray( $record );
		self::assertSame( 'bulk-1', $record['widget_type'] );
	}

	public function test_legacy_untracked_format_list_is_cleaned_on_the_next_write(): void {
		$GLOBALS['stonewright_test_transients']['stonewright_el_schema_legacy'] = [ 'widget_type' => 'old' ];
		$GLOBALS['stonewright_test_options'][ self::INDEX_OPTION ]              = [ 'stonewright_el_schema_legacy' ];

		WidgetSchemaRepository::get( 'bulk-1' );

		self::assertArrayNotHasKey( 'stonewright_el_schema_legacy', $GLOBALS['stonewright_test_transients'] );
		self::assertCount( 1, (array) get_option( self::INDEX_OPTION ) );
	}

	public function test_invalidate_removes_every_tracked_entry_and_the_index(): void {
		for ( $index = 0; $index < 5; ++$index ) {
			WidgetSchemaRepository::get( 'bulk-' . $index );
		}

		WidgetSchemaRepository::invalidate();

		self::assertSame( [], $GLOBALS['stonewright_test_transients'] );
		self::assertSame( [], get_option( self::INDEX_OPTION ) );
	}
}

final class BulkCacheWidget {
	public function __construct( private string $name ) {
	}

	public function get_title(): string {
		return 'Bulk ' . $this->name;
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		$controls = [];
		for ( $index = 1; $index <= 120; ++$index ) {
			$controls[ 'control_' . $index ] = [
				'type'        => 'select',
				'label'       => 'Control ' . $index . ' of ' . $this->name,
				'tab'         => 'content',
				'section'     => 'section_' . ( $index % 6 ),
				'description' => str_repeat( 'Describes the control. ', 6 ),
				'options'     => [ 'a' => 'Option A', 'b' => 'Option B', 'c' => 'Option C' ],
				'selectors'   => [ '{{WRAPPER}} .item-' . $index => 'color: {{VALUE}};' ],
			];
		}
		return $controls;
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;

/**
 * Every V4 write, native or fallback, reads back and compares nested content; a dropped child is an error.
 *
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter
 */
final class AtomicWriteReadbackTest extends TestCase {

	private const POST = 9401;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::POST => (object) [ 'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page', 'post_content' => '', 'post_excerpt' => '', 'meta' => [ '_elementor_data' => wp_json_encode( self::tree() ) ] ],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_a_stored_tree_equal_to_the_written_tree_verifies_with_exact_children(): void {
		$report = AtomicWriteReadback::verify_tree( self::POST, self::tree(), '', 'update_node' );

		self::assertIsArray( $report );
		self::assertTrue( $report['verified'] );
		self::assertSame( 'document_tree', $report['method'] );
		self::assertSame( 4, $report['checked'] );
	}

	public function test_a_dropped_nested_child_is_an_error_and_the_snapshot_is_restored(): void {
		$snapshot = Backup::snapshot_post( self::POST );
		$stored   = self::tree();
		array_pop( $stored[0]['elements'][0]['elements'] );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $stored );

		$result = AtomicWriteReadback::verify_tree( self::POST, self::tree(), $snapshot, 'render_from_spec' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'render_from_spec', $data['readback_context'] );
		self::assertSame( 'b3', $data['problems'][0]['id'] );
		self::assertSame( 'failed', $data['verification_status'] );
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( TreeHasher::hash( self::tree() ), TreeHasher::hash( json_decode( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], true ) ) );
	}

	public function test_without_a_snapshot_the_failed_rollback_is_reported(): void {
		$stored = self::tree();
		array_pop( $stored[0]['elements'][0]['elements'] );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $stored );

		$result = AtomicWriteReadback::verify_tree( self::POST, self::tree(), '', 'update_node' );

		self::assertSame( 'failed', $result->get_error_data()['rollback_status'] );
	}

	public function test_an_unexpected_extra_node_is_also_an_error(): void {
		$snapshot = Backup::snapshot_post( self::POST );
		$stored   = self::tree();
		$stored[0]['elements'][0]['elements'][] = [ 'id' => 'extra', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ];
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $stored );

		$result = AtomicWriteReadback::verify_tree( self::POST, self::tree(), $snapshot, 'update_node' );

		self::assertSame( 'unexpected_child', $result->get_error_data()['problems'][0]['code'] );
	}

	public function test_a_class_whose_nested_variants_were_dropped_is_an_error_and_is_removed(): void {
		$repository = self::class_repository();
		$repository->write_once = static fn( array $item ): array => [ 'id' => $item['id'], 'label' => $item['label'], 'type' => 'class', 'variants' => [] ];
		$adapter    = new AtomicClassRepositoryAdapter( $repository );

		$result = $adapter->create( self::class_item() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertNull( $repository->get( 'sw-1' ), 'the half-written class is removed' );
	}

	public function test_a_class_update_that_lost_a_nested_prop_restores_the_previous_class(): void {
		$repository = self::class_repository();
		$adapter    = new AtomicClassRepositoryAdapter( $repository );
		self::assertIsArray( $adapter->create( self::class_item() ) );
		$repository->write_once = static function ( array $item ): array {
			$item['variants'][0]['props'] = [];
			return $item;
		};
		$changed = self::class_item();
		$changed['variants'][0]['props'] = [ 'color' => [ '$$type' => 'color', 'value' => '#fff' ] ];

		$result = $adapter->update( 'sw-1', $changed );

		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] );
		self::assertSame( self::class_item()['variants'], $repository->get( 'sw-1' )['variants'] );
	}

	public function test_extra_keys_added_by_elementor_do_not_fail_a_class_readback(): void {
		$repository = self::class_repository();
		$repository->write_once = static function ( array $item ): array {
			$item['variants'][0]['custom_css'] = null;
			return $item;
		};

		self::assertIsArray( ( new AtomicClassRepositoryAdapter( $repository ) )->create( self::class_item() ) );
	}

	public function test_a_variable_that_lost_its_value_or_type_is_an_error(): void {
		$service = new class() {
			public function get_variables_list(): array {
				return [ 'v-1' => [ 'label' => 'Brand', 'type' => 'global-color-variable' ] ];
			}
			public function create( array $data ): array {
				return [ 'variable' => [ 'id' => 'v-1', 'label' => 'Brand' ] ];
			}
		};

		$result = ( new AtomicVariableRepositoryAdapter( $service ) )->create( [ 'label' => 'Brand', 'type' => 'global-color-variable', 'value' => '#000' ] );

		self::assertSame( 'stonewright_atomic_readback_mismatch', $result->get_error_code() );
	}

	/** @return list<array<string,mixed>> */
	private static function tree(): array {
		return [
			[ 'id' => 'a1', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [
				[ 'id' => 'b1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
					[ 'id' => 'b2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'string', 'value' => 'Hi' ] ], 'elements' => [] ],
					[ 'id' => 'b3', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [], 'elements' => [] ],
				] ],
			] ],
		];
	}

	/** @return array<string,mixed> */
	private static function class_item(): array {
		return [ 'id' => 'sw-1', 'label' => 'Card', 'type' => 'class', 'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#000' ] ] ] ] ];
	}

	private static function class_repository(): object {
		return new class() {
			/** @var array<string, array<string,mixed>> */
			public array $items = [];
			/** @var callable|null Applied to the next written item only. */
			public $write_once = null;
			/** @return list<string> */
			public function get_order(): array {
				return array_keys( $this->items );
			}
			/** @return array<string,mixed>|null */
			public function get( string $id ): ?array {
				return $this->items[ $id ] ?? null;
			}
			/** @param array<string,array<string,mixed>> $items @param array<string,mixed> $changes @param list<string> $order */
			public function apply_changes( array $items, array $changes, array $order ): void {
				foreach ( (array) ( $changes['deleted'] ?? [] ) as $id ) {
					unset( $this->items[ $id ] );
				}
				foreach ( $items as $id => $item ) {
					if ( null !== $this->write_once ) {
						$item             = ( $this->write_once )( $item );
						$this->write_once = null;
					}
					$this->items[ $id ] = $item;
				}
			}
		};
	}
}
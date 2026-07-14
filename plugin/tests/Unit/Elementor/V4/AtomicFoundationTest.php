<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicClassRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\AtomicVariableRepositoryAdapter;
use Stonewright\WpMcp\Elementor\V4\CoexistencePlanner;
use Stonewright\WpMcp\Elementor\V4\MigrationPlanner;

final class AtomicFoundationTest extends TestCase {
	public function test_official_layout_fixture_is_recursive_and_versioned(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-v4/atomic-layout-0.4.json' ), true );
		$this->assertSame( '0.4', $fixture['version'] );
		$this->assertSame( 'e-div-block', $fixture['content'][0]['elType'] );
		$this->assertSame( 'e-grid', $fixture['content'][0]['elements'][0]['elType'] );
		$this->assertSame( 'e-flexbox', $fixture['content'][0]['elements'][0]['elements'][0]['elType'] );
	}

	public function test_mixed_tree_inventory_keeps_atomic_descendants_without_conversion(): void {
		$tree = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-v4/mixed-v3-v4.json' ), true );
		$result = AtomicTreeInspector::inspect( $tree );
		$this->assertSame( 'mixed', $result['architecture'] );
		$this->assertSame( 2, $result['atomic_count'] );
		$this->assertSame( 1, $result['non_atomic_count'] );
		$this->assertCount( 2, $result['atomic_tree'] );
		$this->assertFalse( $result['implicit_conversion'] );
	}

	public function test_styles_support_responsive_and_pseudo_variants(): void {
		$styles = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-v4/atomic-style.json' ), true );
		$result = AtomicRenderer::render_node( [ 'type' => 'Button', 'props' => [ 'text' => 'Buy', 'link' => '/buy' ], 'styles' => $styles, 'class_ids' => [ 'sw-primary-button' ] ] );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['styles']['sw-primary-button']['variants'] );
		$this->assertSame( [ 'sw-primary-button' ], $result['settings']['classes']['value'] );
	}

	public function test_schema_has_stable_fingerprint_and_direct_atomic_types(): void {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', AtomicSchemaRepository::fingerprint() );
		$this->assertNotNull( AtomicSchemaRepository::for_design_type( 'Heading' ) );
		$this->assertNotNull( AtomicSchemaRepository::for_atomic_type( 'e-grid' ) );
	}

	public function test_class_adapter_uses_apply_changes_and_readback(): void {
		$repo = new class() {
			public array $items = [];
			public function all_labels(): array { return array_map( static fn( array $item ): string => $item['label'], $this->items ); }
			public function get_by_ids( array $ids ): array { return array_intersect_key( $this->items, array_flip( $ids ) ); }
			public function get_order(): array { return array_keys( $this->items ); }
			public function get( string $id ): ?array { return $this->items[ $id ] ?? null; }
			public function apply_changes( array $items, array $changes, array $order ): void { $this->items = array_replace( $this->items, $items ); }
		};
		$adapter = new AtomicClassRepositoryAdapter( $repo );
		$item = [ 'id' => 'sw-test', 'label' => 'Test', 'type' => 'class', 'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [] ] ] ];
		$this->assertSame( $item, $adapter->create( $item ) );
		$item['label'] = 'Updated';
		$this->assertSame( 'Updated', $adapter->update( 'sw-test', $item )['label'] );
	}

	public function test_variable_adapter_requires_mutation_readback(): void {
		$service = new class() {
			public array $items = [];
			public function get_variables_list(): array { return $this->items; }
			public function create( array $data ): array { $this->items['v-1'] = $data; return [ 'variable' => array_merge( [ 'id' => 'v-1' ], $data ) ]; }
			public function update( string $id, array $data ): array { $this->items[ $id ] = array_replace( $this->items[ $id ], $data ); return [ 'variable' => array_merge( [ 'id' => $id ], $this->items[ $id ] ) ]; }
		};
		$adapter = new AtomicVariableRepositoryAdapter( $service );
		$created = $adapter->create( [ 'label' => 'Brand', 'type' => 'global-color-variable', 'value' => '#000' ] );
		$this->assertSame( 'v-1', $created['id'] );
		$this->assertSame( '#fff', $adapter->update( 'v-1', [ 'value' => '#fff' ] )['value'] );
	}

	public function test_coexistence_planner_never_converts_implicitly(): void {
		$tree = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-v4/mixed-v3-v4.json' ), true );
		$plan = CoexistencePlanner::plan( $tree, 'v4' );
		$this->assertSame( 'blocked', $plan['strategy'] );
		$this->assertFalse( $plan['implicit_conversion'] );
		$this->assertSame( 'explicit_migration_required', $plan['blocked'][0]['code'] );
	}

	public function test_migration_requires_a_complete_zero_loss_mapping(): void {
		$ready = MigrationPlanner::plan( [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [ 'flex_direction' => 'row' ], 'elements' => [ [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Hello', 'header_size' => 'h2' ], 'elements' => [] ] ] ] ] );
		$this->assertTrue( $ready['write_ready'] );
		$this->assertSame( 'e-flexbox', $ready['converted_tree'][0]['elType'] );
		$this->assertSame( 'e-heading', $ready['converted_tree'][0]['elements'][0]['widgetType'] );

		$blocked = MigrationPlanner::plan( [ [ 'id' => 'b1', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Buy' ], 'elements' => [] ] ] );
		$this->assertFalse( $blocked['write_ready'] );
		$this->assertSame( 'keep_v3_or_rebuild_explicitly', $blocked['loss_report'][0]['action'] );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Loop\LoopTransaction;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Support\ElementorData;

require_once __DIR__ . '/SyntheticLoopWidgets.php';

/**
 * @covers \Stonewright\WpMcp\Elementor\Loop\LoopTransaction
 */
final class LoopTransactionTest extends TestCase {
	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		unset( $GLOBALS['stonewright_test_loop_control_overrides'] );
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new TransactionWidgetManager( $this->original_elementor->widgets_manager ),
		];
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'] = [
			9049 => self::post(
				9049,
				'page',
				[
					[
						'id'       => 'parent-a',
						'elType'   => 'container',
						'settings' => [ 'container_type' => 'flex' ],
						'elements' => [],
					],
				]
			),
			77 => self::template( 77, 'loop-item', [ self::heading_tree() ] ),
		];
		$GLOBALS['stonewright_test_post_types'] = [
			'project'           => (object) [ 'name' => 'project', 'public' => true ],
			'elementor_library' => (object) [
				'name' => 'elementor_library',
				'cap'  => (object) [
					'create_posts'  => 'edit_posts',
					'publish_posts' => 'publish_posts',
				],
			],
		];
		$GLOBALS['stonewright_test_search_posts'] = [ (object) [ 'ID' => 301 ] ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development', 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_deleted_posts']  = [];
		$GLOBALS['stonewright_test_inserted_posts'] = [];
		$GLOBALS['stonewright_test_next_post_id']   = 8000;
	}

	protected function tearDown(): void {
		LoopTransaction::fail_at_for_test( '' );
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_post_types']     = [];
		$GLOBALS['stonewright_test_search_posts']   = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_deleted_posts']  = [];
		$GLOBALS['stonewright_test_inserted_posts'] = [];
		unset( $GLOBALS['stonewright_test_loop_control_overrides'] );
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_dry_run_plans_without_page_or_template_writes(): void {
		$before = ElementorData::read( 9049 );

		$result = LoopTransaction::run( self::args( [ 'dry_run' => true, 'template_id' => 77 ] ) );

		self::assertIsArray( $result );
		self::assertSame( 'planned', $result['status'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( $before, ElementorData::read( 9049 ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertSame( 'loop-grid', $result['widget_type'] );
	}

	public function test_template_spec_dry_run_never_exposes_validation_placeholder_as_template(): void {
		$result = LoopTransaction::run(
			self::args(
				[
					'dry_run'      => true,
					'template_spec' => self::minimal_spec(),
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( 0, $result['template_id'] );
		self::assertSame( 'transaction_created', $result['template_id_source'] );
		self::assertSame( 0, $result['resolved_settings']['template_id'] );
	}

	public function test_existing_template_writes_page_once_and_replays_idempotently(): void {
		$args   = self::args( [ 'template_id' => 77 ] );
		$result = LoopTransaction::run( $args );
		$replay = LoopTransaction::run( $args );

		self::assertIsArray( $result );
		self::assertSame( 'applied', $result['status'] );
		self::assertFalse( $result['created_template'] );
		self::assertTrue( $result['readback']['verified'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertTrue( $replay['idempotent_replay'] );
		self::assertCount( 1, self::elementor_data_writes( 9049 ) );
	}

	public function test_existing_template_must_be_published_before_wiring(): void {
		$GLOBALS['stonewright_test_posts'][77]->post_status = 'draft';

		$result = LoopTransaction::run( self::args( [ 'dry_run' => true, 'template_id' => 77 ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'template_validation', $result->get_error_data()['transaction_phase'] );
		self::assertSame( 'draft', $result->get_error_data()['template_status'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_new_template_is_published_only_after_verified_page_write(): void {
		$result = LoopTransaction::run(
			self::args(
				[
					'template_spec'  => self::minimal_spec(),
					'template_title' => 'Project card',
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( 'applied', $result['status'] );
		self::assertTrue( $result['created_template'] );
		self::assertSame( 'publish', get_post_status( $result['template_id'] ) );
		self::assertSame( '', get_post_meta( $result['template_id'], '_stonewright_transaction_owner', true ) );
		self::assertTrue( $result['readback']['verified'] );
	}

	public function test_page_readback_failure_restores_page_and_deletes_owned_template(): void {
		$before = ElementorData::read( 9049 );
		LoopTransaction::fail_at_for_test( 'page_readback' );

		$result = LoopTransaction::run(
			self::args( [ 'template_spec' => self::minimal_spec() ] )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'page_readback', $result->get_error_data()['transaction_phase'] );
		self::assertSame( 'completed', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, ElementorData::read( 9049 ) );
		self::assertCount( 1, $GLOBALS['stonewright_test_deleted_posts'] );
		self::assertNull( get_post( 8000 ) );
	}

	/**
	 * @dataProvider rollback_phase_provider
	 */
	public function test_each_mutating_failure_phase_removes_owned_template(
		string $phase
	): void {
		$before = ElementorData::read( 9049 );
		LoopTransaction::fail_at_for_test( $phase );

		$result = LoopTransaction::run(
			self::args( [ 'template_spec' => self::minimal_spec() ] )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $phase, $result->get_error_data()['transaction_phase'] );
		self::assertSame( 'completed', $result->get_error_data()['rollback_status'] );
		self::assertSame( $before, ElementorData::read( 9049 ) );
		self::assertNull( get_post( 8000 ) );
	}

	/** @return array<string, array{0:string}> */
	public static function rollback_phase_provider(): array {
		return [
			'template write'    => [ 'template_write' ],
			'template readback' => [ 'template_readback' ],
			'page write'        => [ 'page_write' ],
			'page readback'     => [ 'page_readback' ],
			'template publish'  => [ 'template_publish' ],
			'final readback'    => [ 'final_readback' ],
			'template finalize' => [ 'template_finalize' ],
		];
	}

	public function test_schema_without_post_type_control_writes_nothing(): void {
		$before = ElementorData::read( 9049 );
		$GLOBALS['stonewright_test_loop_control_overrides']['loop-grid'] = [
			'template_id' => [ 'type' => 'query' ],
			'columns'     => [ 'type' => 'number', 'responsive' => true ],
		];
		WidgetSchemaRepository::reset_request_cache();

		$result = LoopTransaction::run( self::args( [ 'template_id' => 77 ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_schema_incompatible', $result->get_error_code() );
		self::assertSame( $before, ElementorData::read( 9049 ) );
		self::assertSame( [], self::elementor_data_writes( 9049 ) );
	}

	public function test_unsupported_post_type_does_not_become_static_cards(): void {
		$GLOBALS['stonewright_test_post_types']['events'] = (object) [ 'name' => 'events', 'public' => true ];
		$before = ElementorData::read( 9049 );

		$result = LoopTransaction::run( self::args( [ 'template_id' => 77, 'post_type' => 'events' ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_post_type_unsupported', $result->get_error_code() );
		self::assertSame( $before, ElementorData::read( 9049 ) );
		self::assertSame( [], self::elementor_data_writes( 9049 ) );
	}

	public function test_readback_includes_template_query_post_type_and_pagination(): void {
		$result = LoopTransaction::run(
			self::args(
				[
					'template_id' => 77,
					'pagination'  => true,
				]
			)
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['readback']['verified'] );
		self::assertContains( 'template', $result['readback']['checks'] );
		self::assertContains( 'settings', $result['readback']['checks'] );
		$tree   = ElementorData::read( 9049 );
		$widget = $tree[0]['elements'][0];
		self::assertSame( 'loop-grid', $widget['widgetType'] );
		self::assertSame( 77, $widget['settings']['template_id'] );
		self::assertSame( 'project', $widget['settings']['query_post_type'] );
		self::assertSame( 6, $widget['settings']['posts_per_page'] );
		self::assertSame( 'numbers', $widget['settings']['pagination_type'] );
		self::assertArrayNotHasKey( 'pagination_load_type', $widget['settings'] );
	}

	public function test_mobile_only_posts_per_page_rejects_before_write(): void {
		$before = ElementorData::read( 9049 );

		$result = LoopTransaction::run(
			self::args(
				[
					'template_id'      => 77,
					'query'            => [ 'posts_per_page' => 3 ],
					'responsive_scope' => [ 'mobile' ],
				]
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_non_responsive_control', $result->get_error_code() );
		self::assertContains(
			'explicit_two_loops',
			array_column( (array) ( $result->get_error_data()['plan_alternatives'] ?? [] ), 'id' )
		);
		self::assertSame( $before, ElementorData::read( 9049 ) );
	}

	public function test_explicit_two_loops_keep_query_template_unique_ids_and_native_visibility(): void {
		$result = LoopTransaction::run(
			self::args(
				[
					'template_id' => 77,
					'instances'   => [
						[ 'visibility' => [ 'hide_mobile' => 'hidden-mobile' ] ],
						[
							'visibility' => [
								'hide_desktop' => 'hidden-desktop',
								'hide_tablet'  => 'hidden-tablet',
							],
							'query'      => [ 'posts_per_page' => 3 ],
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$elements = ElementorData::read( 9049 )[0]['elements'];
		self::assertCount( 2, $elements );
		self::assertNotSame( $elements[0]['id'], $elements[1]['id'] );
		self::assertSame( 77, $elements[0]['settings']['template_id'] );
		self::assertSame( 77, $elements[1]['settings']['template_id'] );
		self::assertSame( 'project', $elements[0]['settings']['query_post_type'] );
		self::assertSame( 'project', $elements[1]['settings']['query_post_type'] );
		self::assertSame( 'hidden-mobile', $elements[0]['settings']['hide_mobile'] );
		self::assertSame( 'hidden-desktop', $elements[1]['settings']['hide_desktop'] );
		self::assertSame( 'hidden-tablet', $elements[1]['settings']['hide_tablet'] );
		self::assertArrayNotHasKey( 'hide_mobile', $elements[1]['settings'] );
		self::assertSame( 3, $elements[1]['settings']['posts_per_page'] );
		self::assertNotEmpty( $result['warnings'] );
	}

	public function test_single_loop_remains_the_default(): void {
		$result = LoopTransaction::run( self::args( [ 'template_id' => 77 ] ) );

		self::assertIsArray( $result );
		self::assertCount( 1, ElementorData::read( 9049 )[0]['elements'] );
	}

	public function test_existing_template_is_never_deleted_on_page_failure(): void {
		LoopTransaction::fail_at_for_test( 'page_readback' );

		$result = LoopTransaction::run( self::args( [ 'template_id' => 77 ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotNull( get_post( 77 ) );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	/** @param array<string, mixed> $overrides @return array<string, mixed> */
	private static function args( array $overrides = [] ): array {
		return array_replace_recursive(
			[
				'post_id'         => 9049,
				'parent_id'       => 'parent-a',
				'display'         => 'grid',
				'post_type'       => 'project',
				'query'           => [ 'posts_per_page' => 6 ],
				'responsive'      => [ 'desktop' => 3, 'mobile' => 1 ],
				'require_results' => true,
				'idempotency_key' => 'loop-transaction-9049',
				'dry_run'         => false,
			],
			$overrides
		);
	}

	/** @return list<array<string, mixed>> */
	private static function minimal_spec(): array {
		return [
			'page'     => [ 'title' => 'Loop card' ],
			'sections' => [
				[
					'id'     => 'card',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Project' ],
					],
				],
			],
		];
	}

	/** @return array<string, mixed> */
	private static function post( int $id, string $type, array $tree ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => $type,
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data'      => wp_json_encode( $tree ),
				'_elementor_edit_mode' => 'builder',
			],
		];
	}

	/** @return array<string, mixed> */
	private static function template( int $id, string $template_type, array $tree ): object {
		$post = self::post( $id, 'elementor_library', $tree );
		$post->post_status = 'publish';
		$post->meta['_elementor_template_type'] = $template_type;
		return $post;
	}

	/** @return array<string, mixed> */
	private static function heading_tree(): array {
		return [
			'id'         => 'heading-a',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => [ 'title' => 'Card', 'header_size' => 'h2' ],
			'elements'   => [],
		];
	}

	/** @return list<array<string, mixed>> */
	private static function elementor_data_writes( int $post_id ): array {
		return array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn( array $call ): bool =>
					$post_id === (int) ( $call['post_id'] ?? 0 )
					&& '_elementor_data' === (string) ( $call['meta_key'] ?? '' )
			)
		);
	}
}

final class TransactionWidgetManager {
	public function __construct( private object $fallback ) {
	}

	public function get_widget_types( ?string $name = null ): array|object|null {
		$loops = [
			'loop-carousel' => new TransactionLoopCarouselWidget(),
			'loop-grid'     => new TransactionLoopGridWidget(),
		];
		if ( null === $name ) {
			return array_merge( (array) $this->fallback->get_widget_types(), $loops );
		}
		return $loops[ $name ] ?? $this->fallback->get_widget_types( $name );
	}
}

final class TransactionLoopCarouselWidget extends SyntheticLoopCarouselWidget {
}

final class TransactionLoopGridWidget extends SyntheticLoopGridWidget {
}

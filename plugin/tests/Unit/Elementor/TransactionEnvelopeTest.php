<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\ElementorTransactionRunner;
use Stonewright\WpMcp\Elementor\TransactionEnvelope;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Elementor\TransactionEnvelope
 * @covers \Stonewright\WpMcp\Elementor\ElementorTransactionRunner
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\TransactionRun
 */
final class TransactionEnvelopeTest extends TestCase {

	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_posts'] = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Txn target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_transients']      = [];
		ElementorTransactionRunner::set_read_override( null );
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		ElementorTransactionRunner::set_read_override( null );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_normalize_requires_operations(): void {
		$result = TransactionEnvelope::normalize( [] );
		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_failed_readback_triggers_rollback(): void {
		$before = ElementorData::read( 601 );

		// Force readback to an empty tree so expected min_elements fails.
		ElementorTransactionRunner::set_read_override(
			static function ( int $post_id ): array {
				unset( $post_id );
				return [];
			}
		);

		$result = ElementorTransactionRunner::run(
			601,
			[
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Txn headline' ],
					],
				],
				'rollback_on_error' => true,
				'expected_readback' => [
					'min_elements' => 2,
				],
			],
			false
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_transaction_readback_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertTrue( (bool) ( $data['rolled_back'] ?? false ) );
		self::assertNotEmpty( $data['snapshot_id'] ?? '' );

		// Snapshot restore should put the original tree back.
		$after = ElementorData::read( 601 );
		self::assertSame( $before, $after );
	}

	public function test_successful_transaction_returns_snapshot(): void {
		$result = ElementorTransactionRunner::run(
			601,
			[
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'root',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'OK' ],
					],
				],
				'expected_readback' => [
					'min_elements'          => 2,
					'contains_widget_types' => [ 'heading' ],
				],
			],
			false
		);

		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' )
		);
		self::assertTrue( $result['ok'] );
		self::assertNotEmpty( $result['snapshot_id'] );
		self::assertFalse( $result['rolled_back'] );
	}

	public function test_replace_tree_full_tree_path(): void {
		$files_manager = new class() {
			public int $calls = 0;

			public function clear_cache(): void {
				++$this->calls;
			}
		};
		$posts_css_manager = new class() {
			/** @var list<int> */
			public array $post_ids = [];

			public function clear_cache_post( int $post_id ): void {
				$this->post_ids[] = $post_id;
			}
		};
		\Elementor\Plugin::$instance = (object) [
			'files_manager'     => $files_manager,
			'posts_css_manager' => $posts_css_manager,
			'widgets_manager'   => $this->elementor_instance->widgets_manager,
		];
		$tree = [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [
					'content_width'     => 'full',
					'flex_direction'    => 'column',
					'flex_align_items'  => 'center',
					'container_type'    => 'flex',
				],
				'elements' => [
					[
						'id'         => 'h1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Centered hero' ],
						'elements'   => [],
					],
				],
			],
		];

		$result = ElementorTransactionRunner::run(
			601,
			[
				'operations' => [
					[
						'action' => 'replace_tree',
						'tree'   => $tree,
					],
				],
				'expected_readback' => [
					'min_elements'          => 2,
					'contains_widget_types' => [ 'heading' ],
				],
			],
			false
		);

		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' )
		);
		self::assertTrue( $result['ok'] );
		self::assertSame( 'full_tree', $result['mode'] ?? null );
		self::assertNotEmpty( $result['snapshot_id'] );
		self::assertSame( [ 601 ], $posts_css_manager->post_ids );
		self::assertSame( 0, $files_manager->calls, 'Full-tree transactions must not perform a second global cache clear.' );
		$read = ElementorData::read( 601 );
		self::assertSame( 'center', $read[0]['settings']['flex_align_items'] ?? null );
	}

	public function test_runner_does_not_raw_write_when_integrity_gate_rejects(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$rejected_tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => (object) [ 'invalid' => true ] ],
				'elements' => [],
			],
		];

		$result = ElementorTransactionRunner::run_full_tree( 601, $rejected_tree, [], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		$data_writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $call ): bool => '_elementor_data' === ( $call['meta_key'] ?? '' )
		);
		self::assertSame( [], $data_writes, 'runner must not raw-write _elementor_data past the gate' );
	}
}

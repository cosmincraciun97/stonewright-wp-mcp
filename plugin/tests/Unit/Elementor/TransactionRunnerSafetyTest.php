<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\ElementorTransactionRunner;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * The transaction runner restores the snapshot only when its own write left the document changed, applies the
 * code gates to a whole-tree replace for every caller, and reports in a dry run what the write would be refused for.
 *
 * @covers \Stonewright\WpMcp\Elementor\ElementorTransactionRunner
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 */
final class TransactionRunnerSafetyTest extends TestCase {

	private const POST     = 611;
	private const EXISTING = '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[{"id":"h1","elType":"widget","widgetType":"heading","settings":{"title":"Kept"},"elements":[]}]}]';

	protected function setUp(): void {
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_posts']           = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Txn target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => self::EXISTING,
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
		ElementorTransactionRunner::set_read_override( null );
	}

	protected function tearDown(): void {
		ElementorCustomCssGate::reset();
		ElementorTransactionRunner::set_read_override( null );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		unset( $GLOBALS['stonewright_test_after_add_option'] );
	}

	/** @return list<string> Meta keys written since the test began. */
	private static function written_keys(): array {
		return array_values( array_unique( array_map( static fn( array $call ): string => (string) $call['meta_key'], $GLOBALS['stonewright_test_post_meta_calls'] ) ) );
	}

	/** @return array<string, mixed> */
	private static function add_heading_envelope(): array {
		return [
			'operations'        => [ [ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'heading', 'allow_raw_known_widget' => true, 'settings' => [ 'title' => 'New' ] ] ],
			'rollback_on_error' => true,
		];
	}

	/** @return list<array<string, mixed>> */
	private static function html_tree(): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[ 'id' => 'raw1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<style>.x{color:red}</style><p>Hi</p>' ], 'elements' => [] ],
				],
			],
		];
	}

	public function test_a_busy_lock_does_not_restore_the_snapshot_over_the_other_writers_work(): void {
		PostWriteLock::acquire( self::POST, 'other-writer', 30 );

		$result = ElementorTransactionRunner::run( self::POST, self::add_heading_envelope(), false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertNotContains( '_elementor_data', self::written_keys(), 'The snapshot was not restored over the document.' );
		self::assertNotTrue( (bool) ( $result->get_error_data()['rolled_back'] ?? false ) );
		self::assertTrue( PostWriteLock::owned_by( self::POST, 'other-writer' ) );
	}

	public function test_an_operation_that_fails_before_any_write_does_not_restore(): void {
		$result = ElementorTransactionRunner::run(
			self::POST,
			[
				'operations'        => [ [ 'action' => 'add_widget', 'parent_id' => 'missing', 'widget_type' => 'heading', 'allow_raw_known_widget' => true, 'settings' => [ 'title' => 'x' ] ] ],
				'rollback_on_error' => true,
			],
			false
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotContains( '_elementor_data', self::written_keys() );
		self::assertNotTrue( (bool) ( $result->get_error_data()['rolled_back'] ?? false ) );
	}

	public function test_a_busy_lock_on_a_whole_tree_replace_does_not_restore_either(): void {
		PostWriteLock::acquire( self::POST, 'other-writer', 30 );

		$result = ElementorTransactionRunner::run(
			self::POST,
			[ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => [ [ 'id' => 'n1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] ] ] ],
			false
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertNotContains( '_elementor_data', self::written_keys() );
	}

	/** @return list<array<string, mixed>> */
	private static function replacement_tree(): array {
		return [ [ 'id' => 'n1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
	}

	/** @return list<array<string, mixed>> The stored tree plus one root element another writer added. */
	private static function tree_with_a_concurrent_element(): array {
		$tree   = json_decode( self::EXISTING, true );
		$tree[] = [ 'id' => 'other-writer-el', 'elType' => 'container', 'settings' => [], 'elements' => [] ];
		return $tree;
	}

	public function test_a_whole_tree_write_is_refused_before_a_snapshot_while_another_writer_holds_the_lease(): void {
		PostWriteLock::acquire( self::POST, 'other-writer', 30 );

		$result = ElementorTransactionRunner::run_full_tree( self::POST, self::replacement_tree() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
		self::assertTrue( PostWriteLock::owned_by( self::POST, 'other-writer' ) );
	}

	public function test_a_whole_tree_write_checks_the_precondition_after_taking_the_lease(): void {
		$read_hash = TreeHasher::hash( ElementorData::read( self::POST ) );
		$GLOBALS['stonewright_test_after_add_option'] = function (): void {
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode( self::tree_with_a_concurrent_element() );
		};

		$result = ElementorTransactionRunner::run_full_tree( self::POST, self::replacement_tree(), [], true, [], $read_hash );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_transaction_precondition', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'The stale read takes no snapshot and writes nothing.' );
		self::assertSame( [ 'root', 'other-writer-el' ], array_column( ElementorData::read( self::POST ), 'id' ), 'The change another writer made is left as it is.' );
	}

	public function test_a_whole_tree_write_releases_the_lease_after_it_succeeds_and_after_it_is_refused(): void {
		$ok = ElementorTransactionRunner::run_full_tree( self::POST, self::replacement_tree() );
		self::assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_code() : '' );
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'next-writer', 30 ), 'The lease is free after a write.' );
		PostWriteLock::release( self::POST, 'next-writer' );

		$refused = ElementorTransactionRunner::run_full_tree( self::POST, self::replacement_tree(), [], true, [], str_repeat( 'a', 64 ) );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'last-writer', 30 ), 'The lease is free after a refusal.' );
	}

	public function test_an_operation_batch_is_refused_when_the_page_changed_after_the_runner_took_its_snapshot(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		$changed                             = false;
		add_filter(
			'update_post_metadata',
			static function ( $check, int $post_id, string $meta_key ) use ( &$changed ) {
				if ( ! $changed && self::POST === $post_id && '_stonewright_backups' === $meta_key ) {
					$changed = true;
					$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode( self::tree_with_a_concurrent_element() );
				}
				return $check;
			},
			10,
			3
		);

		$result = ElementorTransactionRunner::run( self::POST, self::add_heading_envelope(), false );

		self::assertTrue( $changed, 'The page was changed after the snapshot.' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_revision_conflict', $result->get_error_code() );
		self::assertSame( [ 'root', 'other-writer-el' ], array_column( ElementorData::read( self::POST ), 'id' ), 'The change another writer made is neither overwritten nor restored over.' );
		self::assertSame( [], (array) ( ElementorData::read( self::POST )[0]['elements'][1] ?? [] ), 'The heading was not added to a page the snapshot does not describe.' );
	}

	public function test_a_write_that_fails_its_readback_is_still_restored(): void {
		ElementorTransactionRunner::set_read_override( static fn( int $post_id ): array => [] );
		$before = ElementorData::read( self::POST );

		$result = ElementorTransactionRunner::run( self::POST, self::add_heading_envelope() + [ 'expected_readback' => [ 'min_elements' => 5 ] ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_transaction_readback_failed', $result->get_error_code() );
		self::assertTrue( $result->get_error_data()['rolled_back'] );
		self::assertTrue( $result->get_error_data()['restored'] );
		self::assertSame( $before, ElementorData::read( self::POST ) );
	}

	public function test_an_internal_caller_cannot_replace_a_tree_with_an_html_widget_the_site_refuses(): void {
		$result = ElementorTransactionRunner::run( self::POST, [ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => self::html_tree() ] ] ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
		self::assertSame( self::EXISTING, $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] );
	}

	public function test_a_caller_that_passes_the_approval_can_replace_a_tree_with_an_html_widget(): void {
		$GLOBALS['stonewright_test_options']['stonewright_allow_html_widgets'] = true;
		$tree = self::html_tree();
		$tree[0]['elements'][0]['settings']['html'] = '<p>Hi</p>';

		$result = ElementorTransactionRunner::run( self::POST, [ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => $tree ] ] ], false, [ 'allow_html_widget' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertTrue( $result['ok'] );
	}

	public function test_a_dry_run_of_a_whole_tree_replace_reports_the_gate_and_preflight_refusals(): void {
		$gate = ElementorTransactionRunner::run( self::POST, [ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => self::html_tree() ] ] ], true );
		self::assertInstanceOf( \WP_Error::class, $gate );
		self::assertSame( 'stonewright_html_widget_disabled', $gate->get_error_code() );

		$root_widget = [ [ 'id' => 'lone', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'x' ], 'elements' => [] ] ];
		$preflight   = ElementorTransactionRunner::run( self::POST, [ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => $root_widget ] ] ], true );
		self::assertInstanceOf( \WP_Error::class, $preflight );
		self::assertSame( 'widget_at_root', $preflight->get_error_data()['violations'][0]['code'] );

		$ok = ElementorTransactionRunner::run( self::POST, [ 'operations' => [ [ 'action' => 'replace_tree', 'tree' => [ [ 'id' => 'n1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] ] ] ], true );
		self::assertIsArray( $ok );
		self::assertTrue( $ok['dry_run'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	/**
	 * A stored document the write would refuse (two elements share an id) is refused by the dry run as well.
	 */
	private function seed_document_the_write_refuses(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode(
			[
				[ 'id' => 'dup', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'dup', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Kept' ], 'elements' => [] ] ] ],
			]
		);
	}

	public function test_update_element_dry_run_runs_the_same_preflight_as_the_write(): void {
		$this->seed_document_the_write_refuses();
		$args = [ 'post_id' => self::POST, 'element_id' => 'h1', 'settings' => [ 'title' => 'Changed' ] ];

		$write = ( new UpdateElement() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $write );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$dry = ( new UpdateElement() )->execute( $args + [ 'dry_run' => true ] );

		self::assertInstanceOf( \WP_Error::class, $dry, 'The dry run reports what the write would be refused for.' );
		self::assertSame( $write->get_error_code(), $dry->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_batch_mutate_dry_run_runs_the_same_preflight_as_the_write(): void {
		$this->seed_document_the_write_refuses();
		$args = [ 'post_id' => self::POST, 'operations' => [ [ 'action' => 'update_element', 'element_id' => 'h1', 'settings' => [ 'title' => 'Changed' ] ] ] ];

		$write = ( new BatchMutate() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $write );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$dry = ( new BatchMutate() )->execute( $args + [ 'dry_run' => true ] );

		self::assertInstanceOf( \WP_Error::class, $dry, 'The dry run reports what the write would be refused for.' );
		self::assertSame( $write->get_error_code(), $dry->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}
}

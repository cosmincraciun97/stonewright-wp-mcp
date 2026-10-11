<?php
/**
 * Observation of Elementor's own abilities that run outside stonewright-elementor-native-execute.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use Stonewright\WpMcp\Elementor\Provider\NativeObserver;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\PostLedgerTestCase;

/**
 * The hooks are core's `wp_before_execute_ability` (name, input, ability) and `wp_after_execute_ability`
 * (name, input, result, ability): https://developer.wordpress.org/reference/classes/wp_ability/execute/
 * They fire after the input, permission and (for after) output checks pass, and only after a successful call.
 *
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeObserver
 * @covers \Stonewright\WpMcp\Security\Adapters\PostAdapter
 */
final class NativeObserverTest extends PostLedgerTestCase {

	private const PAGE = 51;
	private const KIT  = 52;
	private const TREE = '[{"id":"a1b2c3d","elType":"e-div-block","settings":{},"elements":[]}]';

	protected function setUp(): void {
		parent::setUp();
		remove_all_actions( 'wp_before_execute_ability' );
		remove_all_actions( 'wp_after_execute_ability' );
		NativeObserver::reset_for_tests();
		NativeObserver::use_kit_resolver_for_tests( static fn(): int => self::KIT );
		NativeObserver::register();
		$this->make_post( self::PAGE, [ 'post_title' => 'Observed page' ] );
		$this->set_meta( self::PAGE, '_elementor_data', self::TREE );
		$this->make_post( self::KIT, [ 'post_type' => 'elementor_library', 'post_title' => 'Kit' ] );
		$this->set_meta( self::KIT, '_elementor_page_settings', [ 'default_styles' => 'one' ] );
	}

	protected function tearDown(): void {
		remove_all_actions( 'wp_before_execute_ability' );
		remove_all_actions( 'wp_after_execute_ability' );
		NativeObserver::reset_for_tests();
		parent::tearDown();
	}

	public function test_it_hooks_the_two_core_actions_with_the_arguments_core_passes(): void {
		self::assertSame( 3, $GLOBALS['stonewright_test_actions']['wp_before_execute_ability'][0]['args'] );
		self::assertSame( 4, $GLOBALS['stonewright_test_actions']['wp_after_execute_ability'][0]['args'] );
	}

	public function test_the_plugin_registers_the_observer_at_boot(): void {
		$boot = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString( 'NativeObserver::register();', $boot );
	}

	public function test_a_direct_native_write_is_snapshotted_before_and_recorded_after(): void {
		$input = [ 'post_id' => self::PAGE, 'xml_structure' => '<e-heading configuration-id="a"/>' ];

		do_action( 'wp_before_execute_ability', 'elementor/build-composition', $input, $this->ability( false ) );
		self::assertCount( 1, Backup::list_snapshots( self::PAGE ), 'the snapshot is taken before Elementor runs' );
		self::assertSame( [], $this->ledger_rows(), 'nothing is recorded until the call has succeeded' );

		$this->set_meta( self::PAGE, '_elementor_data', '[{"id":"a1b2c3d","elType":"e-div-block","settings":{},"elements":[{"id":"zz9","elType":"widget","widgetType":"e-heading"}]}]' );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', $input, [ 'success' => true, 'post_id' => self::PAGE ], $this->ability( false ) );

		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'elementor/build-composition', $rows[0]['ability'] );
		self::assertSame( 'elementor', $rows[0]['family'] );
		self::assertSame( 'post', $rows[0]['resource_type'] );
		self::assertSame( (string) self::PAGE, $rows[0]['resource_id'] );
		self::assertSame( 'probe_unavailable', $rows[0]['status'], 'observed, never probed or verified by Stonewright' );
		self::assertSame( 1, (int) $rows[0]['restorable'], 'the before image lets the history restore the page' );
		self::assertNotSame( '', $rows[0]['before_sha256'] );
		self::assertNotSame( '', $rows[0]['after_sha256'] );
		self::assertNotSame( $rows[0]['before_sha256'], $rows[0]['after_sha256'] );

		$audit = $this->audit_rows( 'elementor/build-composition' );
		self::assertCount( 1, $audit );
		self::assertStringContainsString( 'observed_native', $audit[0]['sanitized_args'] );
		self::assertStringContainsString( (string) $rows[0]['change_id'], $audit[0]['sanitized_args'] );
		self::assertSame( 'ok', $audit[0]['result_status'] );
		self::assertStringNotContainsString( 'e-heading configuration-id', $audit[0]['sanitized_args'], 'the content of the call is not copied into the audit row' );
	}

	public function test_the_recorded_row_can_put_the_page_back(): void {
		$input = [ 'post_id' => self::PAGE ];
		do_action( 'wp_before_execute_ability', 'elementor/update-page-settings', $input, $this->ability( false ) );
		$this->set_meta( self::PAGE, '_elementor_data', '[{"id":"changed"}]' );
		do_action( 'wp_after_execute_ability', 'elementor/update-page-settings', $input, [ 'success' => true ], $this->ability( false ) );

		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows );
		$undone = \Stonewright\WpMcp\Security\Adapters\PostAdapter::undo( (string) $rows[0]['change_id'] );

		self::assertIsArray( $undone, $undone instanceof \WP_Error ? $undone->get_error_message() : '' );
		self::assertSame( self::TREE, get_post_meta( self::PAGE, '_elementor_data', true ) );
	}

	public function test_a_write_that_changed_nothing_leaves_an_audit_row_and_no_ledger_row(): void {
		$input = [ 'post_id' => self::PAGE ];
		do_action( 'wp_before_execute_ability', 'elementor/manage-elements', $input, $this->ability( false ) );
		do_action( 'wp_after_execute_ability', 'elementor/manage-elements', $input, [ 'success' => true ], $this->ability( false ) );

		self::assertSame( [], $this->ledger_rows() );
		$audit = $this->audit_rows( 'elementor/manage-elements' );
		self::assertCount( 1, $audit );
		self::assertStringContainsString( 'unchanged', $audit[0]['sanitized_args'] );
	}

	public function test_a_kit_level_write_resolves_the_active_kit_when_no_post_is_named(): void {
		$input = [ 'operations' => [ [ 'action' => 'update', 'tag' => 'h2' ] ] ];

		do_action( 'wp_before_execute_ability', 'elementor/manage-default-styles', $input, $this->ability( false ) );
		self::assertCount( 1, Backup::list_snapshots( self::KIT ) );
		$this->set_meta( self::KIT, '_elementor_page_settings', [ 'default_styles' => 'two' ] );
		do_action( 'wp_after_execute_ability', 'elementor/manage-default-styles', $input, [ 'status' => 'ok' ], $this->ability( false ) );

		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows );
		self::assertSame( (string) self::KIT, $rows[0]['resource_id'] );
		self::assertSame( 'elementor', $rows[0]['family'] );
	}

	public function test_a_write_without_a_derivable_post_is_audited_without_a_snapshot(): void {
		$input = [ 'title' => 'New' ];
		do_action( 'wp_before_execute_ability', 'elementor/publish-document', $input, $this->ability( false ) );
		do_action( 'wp_after_execute_ability', 'elementor/publish-document', $input, [ 'ok' => true ], $this->ability( false ) );

		self::assertSame( [], $this->ledger_rows() );
		self::assertCount( 1, $this->audit_rows( 'elementor/publish-document' ) );
	}

	public function test_a_page_created_by_the_call_is_recorded_from_the_result(): void {
		$input = [ 'title' => 'Fresh' ];
		do_action( 'wp_before_execute_ability', 'elementor/create-page', $input, $this->ability( false ) );
		$this->make_post( 77, [ 'post_title' => 'Fresh', 'post_status' => 'draft' ] );
		$this->set_meta( 77, '_elementor_data', '[]' );
		do_action( 'wp_after_execute_ability', 'elementor/create-page', $input, [ 'post_id' => 77 ], $this->ability( false ) );

		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows );
		self::assertSame( '77', $rows[0]['resource_id'] );
		self::assertStringStartsWith( 'Created ', (string) $rows[0]['summary'] );
	}

	public function test_reads_and_other_plugins_are_ignored(): void {
		$input = [ 'post_id' => self::PAGE ];
		do_action( 'wp_before_execute_ability', 'elementor/get-page-structure', $input, $this->ability( true ) );
		do_action( 'wp_after_execute_ability', 'elementor/get-page-structure', $input, [ 'elements' => [] ], $this->ability( true ) );
		do_action( 'wp_before_execute_ability', 'elementor/list-posts', $input );
		do_action( 'wp_after_execute_ability', 'elementor/list-posts', $input, [ 'posts' => [] ] );
		do_action( 'wp_before_execute_ability', 'acme/update-page', $input, $this->ability( false ) );
		do_action( 'wp_after_execute_ability', 'acme/update-page', $input, [ 'ok' => true ], $this->ability( false ) );

		self::assertSame( [], Backup::list_snapshots( self::PAGE ) );
		self::assertSame( [], $this->ledger_rows() );
		self::assertSame( [], $this->all_audit_rows() );
	}

	public function test_a_call_that_comes_through_native_execute_is_not_recorded_twice(): void {
		$input = [ 'post_id' => self::PAGE ];
		NativeObserver::mark_native_execute(
			function () use ( $input ): void {
				do_action( 'wp_before_execute_ability', 'elementor/build-composition', $input, $this->ability( false ) );
				$this->set_meta( self::PAGE, '_elementor_data', '[{"id":"changed"}]' );
				do_action( 'wp_after_execute_ability', 'elementor/build-composition', $input, [ 'success' => true ], $this->ability( false ) );
			}
		);

		self::assertSame( [], Backup::list_snapshots( self::PAGE ) );
		self::assertSame( [], $this->ledger_rows() );
		self::assertSame( [], $this->all_audit_rows() );
	}

	public function test_a_call_that_fails_leaves_nothing_that_confuses_the_next_one(): void {
		$first  = [ 'post_id' => self::PAGE ];
		$second = [ 'post_id' => self::KIT ];
		// Core fires no after action when the call fails, so the first call never closes.
		do_action( 'wp_before_execute_ability', 'elementor/build-composition', $first, $this->ability( false ) );

		do_action( 'wp_before_execute_ability', 'elementor/update-page-settings', $second, $this->ability( false ) );
		$this->set_meta( self::KIT, '_elementor_page_settings', [ 'default_styles' => 'three' ] );
		do_action( 'wp_after_execute_ability', 'elementor/update-page-settings', $second, [ 'success' => true ], $this->ability( false ) );

		$rows = $this->ledger_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'elementor/update-page-settings', $rows[0]['ability'] );
		self::assertSame( (string) self::KIT, $rows[0]['resource_id'] );
	}

	public function test_a_write_ability_is_recognised_without_the_ability_object_by_its_contract_and_name(): void {
		$input = [ 'post_id' => self::PAGE ];
		do_action( 'wp_before_execute_ability', 'elementor/build-composition', $input );
		$this->set_meta( self::PAGE, '_elementor_data', '[{"id":"changed"}]' );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', $input, [ 'success' => true ] );

		self::assertCount( 1, $this->ledger_rows() );
		self::assertCount( 1, Backup::list_snapshots( self::PAGE ) );
	}

	public function test_it_never_throws_into_the_call_it_observes(): void {
		$broken = new class() {
			public function get_meta(): array {
				throw new \RuntimeException( 'private detail' );
			}
		};
		$input = [ 'post_id' => self::PAGE ];

		do_action( 'wp_before_execute_ability', 'elementor/build-composition', $input, $broken );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', $input, [ 'success' => true ], $broken );
		do_action( 'wp_before_execute_ability', 'elementor/build-composition', 'not an array', null );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', 'not an array', new \WP_Error( 'x', 'y' ), null );
		do_action( 'wp_before_execute_ability', 'elementor/build-composition', [ 'post_id' => 999999 ], $this->ability( false ) );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', [ 'post_id' => 999999 ], [ 'success' => true ], $this->ability( false ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_it_changes_nothing_about_the_result_and_opens_no_rescue_frame(): void {
		$input  = [ 'post_id' => self::PAGE ];
		$result = [ 'success' => true, 'post_id' => self::PAGE ];
		$before = $result;

		do_action( 'wp_before_execute_ability', 'elementor/build-composition', $input, $this->ability( false ) );
		do_action( 'wp_after_execute_ability', 'elementor/build-composition', $input, $result, $this->ability( false ) );

		self::assertSame( $before, $result );
		// A frame opened here and never left would swallow the next ability's rescue entries.
		self::assertNull( RescueGuard::arm_post_write( self::PAGE, 'sn-probe' ), 'no frame is open after the observed call' );
	}

	/** @return list<array<string,mixed>> */
	private function all_audit_rows(): array {
		$rows = [];
		foreach ( $this->db->tables as $table => $table_rows ) {
			if ( str_contains( (string) $table, 'audit' ) ) {
				$rows = array_merge( $rows, $table_rows );
			}
		}
		return $rows;
	}

	/** @return list<array<string,mixed>> */
	private function audit_rows( string $ability ): array {
		return array_values( array_filter( $this->all_audit_rows(), static fn( array $row ): bool => ( $row['ability_name'] ?? '' ) === $ability ) );
	}

	private function ability( bool $readonly ): object {
		return new class( $readonly ) {
			public function __construct( private bool $readonly ) {}

			/** @return array<string,mixed> */
			public function get_meta(): array {
				return [ 'annotations' => [ 'readonly' => $this->readonly, 'destructive' => ! $this->readonly, 'idempotent' => $this->readonly ] ];
			}
		};
	}
}

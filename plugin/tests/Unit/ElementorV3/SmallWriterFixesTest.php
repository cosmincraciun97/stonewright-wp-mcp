<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BackupPage;
use Stonewright\WpMcp\Abilities\ElementorV3\ContainerSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\DesignMirrorExport;
use Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema;
use Stonewright\WpMcp\Abilities\ElementorV3\LegacyDebtMigrate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings;
use Stonewright\WpMcp\Abilities\ElementorV3\WireLoop;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure;
use Stonewright\WpMcp\Abilities\ElementorV3\KitBatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Small contract fixes of the Elementor V3 abilities: backup-page reports a snapshot that failed and arms no
 * rescue, wire-loop and legacy-debt-migrate ask for a token on every write, design-mirror-export is a read,
 * elementor-schema redirects with an input container-schema accepts, and update-page-settings stores what it was sent.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BackupPage
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\WireLoop
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\LegacyDebtMigrate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\DesignMirrorExport
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdatePageSettings
 */
final class SmallWriterFixesTest extends TestCase {

	private const PAGE = 780;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [
			self::PAGE => (object) [
				'ID'           => self::PAGE,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'target',
				'meta'         => [
					'_elementor_data'          => '[{"id":"root","elType":"container","settings":{},"elements":[]}]',
					'_elementor_edit_mode'     => 'builder',
					'_elementor_page_settings' => [ 'hide_title' => 'yes' ],
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_types']      = [ 'elementor_library' => (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ] ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'publish_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
	}

	protected function tearDown(): void {
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_post_types']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	/** @return array<string, mixed> The audit meta of the last row recorded for an ability. */
	private static function audit_meta( string $ability ): array {
		$meta = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( ( $insert['data']['ability_name'] ?? '' ) !== $ability ) {
				continue;
			}
			$decoded = json_decode( (string) ( $insert['data']['sanitized_args'] ?? '' ), true );
			$meta    = is_array( $decoded ) && is_array( $decoded['_meta'] ?? null ) ? $decoded['_meta'] : [];
		}
		return $meta;
	}

	// ---- backup-page ---------------------------------------------------------

	public function test_backup_page_reports_a_snapshot_that_could_not_be_stored(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new BackupPage() )->execute( [ 'post_id' => self::PAGE ] );

		self::assertInstanceOf( \WP_Error::class, $result, 'An empty snapshot id is no success.' );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
	}

	public function test_backup_page_returns_the_snapshot_id_of_a_stored_snapshot(): void {
		$result = ( new BackupPage() )->execute( [ 'post_id' => self::PAGE ] );

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['snapshot_id'] );
	}

	public function test_backup_page_arms_no_rescue_because_it_changes_nothing(): void {
		( new BackupPage() )->execute( [ 'post_id' => self::PAGE ] );

		self::assertSame( [], ChangeJournal::recent(), 'A manual snapshot writes nothing, so there is nothing to probe or roll back.' );
	}

	// ---- wire-loop -----------------------------------------------------------

	public function test_wire_loop_with_an_existing_template_needs_a_token_for_a_write_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$GLOBALS['stonewright_test_posts'][781]                  = (object) [ 'ID' => 781, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Loop item', 'post_content' => '', 'post_excerpt' => '', 'meta' => [] ];

		$result = ( new WireLoop() )->execute(
			[
				'post_id'         => self::PAGE,
				'parent_id'       => 'root',
				'display'         => 'grid',
				'post_type'       => 'post',
				'template_id'     => 781,
				'idempotency_key' => 'loop-write-0001',
				'dry_run'         => false,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_wire_loop_dry_run_needs_no_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$GLOBALS['stonewright_test_posts'][781]                  = (object) [ 'ID' => 781, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Loop item', 'post_content' => '', 'post_excerpt' => '', 'meta' => [] ];

		$result = ( new WireLoop() )->execute(
			[
				'post_id'         => self::PAGE,
				'parent_id'       => 'root',
				'display'         => 'grid',
				'post_type'       => 'post',
				'template_id'     => 781,
				'idempotency_key' => 'loop-plan-0001',
				'dry_run'         => true,
			]
		);

		if ( $result instanceof \WP_Error ) {
			self::assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		} else {
			self::assertArrayHasKey( 'ok', $result );
		}
	}

	// ---- legacy-debt-migrate ---------------------------------------------------

	public function test_legacy_debt_apply_is_an_audited_write_that_needs_a_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new LegacyDebtMigrate() )->execute(
			[
				'post_id'            => self::PAGE,
				'element_id'         => 'root',
				'paths'              => [ 'legacy_key' ],
				'action'             => 'apply',
				'expected_tree_hash' => str_repeat( 'a', 64 ),
				'approved_plan_hash' => str_repeat( 'b', 64 ),
				'idempotency_key'    => 'migrate-apply-1',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 'write', self::audit_meta( 'stonewright/elementor-v3-legacy-debt-migrate' )['operation_kind'] ?? null, 'The refusal is recorded as a write attempt.' );
	}

	public function test_legacy_debt_apply_that_is_refused_for_its_input_does_not_ask_for_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new LegacyDebtMigrate() )->execute( [ 'post_id' => 999999, 'element_id' => 'root', 'paths' => [ 'legacy_key' ], 'action' => 'apply' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_not_found', $result->get_error_code(), 'A call refused for its input never spends a token.' );
	}

	public function test_legacy_debt_dry_run_is_a_read_that_needs_no_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new LegacyDebtMigrate() )->execute( [ 'post_id' => self::PAGE, 'element_id' => 'root', 'paths' => [ 'legacy_key' ], 'action' => 'dry_run' ] );

		self::assertNotSame( 'stonewright_confirmation_required', $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 'read', self::audit_meta( 'stonewright/elementor-v3-legacy-debt-migrate' )['operation_kind'] ?? null );
	}

	// ---- design-mirror-export ----------------------------------------------------

	public function test_design_mirror_export_is_a_read_and_needs_no_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new DesignMirrorExport() )->execute( [ 'post_ids' => [ self::PAGE ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 'read', self::audit_meta( 'stonewright/design-mirror-export' )['operation_kind'] ?? null );
	}

	// ---- elementor-schema ----------------------------------------------------------

	public function test_the_container_redirect_of_elementor_schema_is_an_input_container_schema_accepts(): void {
		$result = ( new ElementorSchema() )->execute( [ 'mode' => 'summary', 'widget_type' => 'section' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$redirect = $result->get_error_data()['redirect'];
		self::assertSame( 'stonewright/elementor-v3-container-schema', $redirect['ability'] );
		$accepted = array_keys( ( new ContainerSchema() )->input_schema()['properties'] );
		self::assertSame( [], array_values( array_diff( array_keys( $redirect['input'] ), $accepted ) ), 'container-schema refuses any other input.' );
	}

	// ---- get-page-structure and schemas -------------------------------------------------

	public function test_the_structure_hash_is_the_hash_every_writer_takes_as_expected_tree_hash(): void {
		$result = ( new GetPageStructure() )->execute( [ 'post_id' => self::PAGE ] );

		self::assertIsArray( $result );
		self::assertSame( TreeHasher::hash( ElementorData::read( self::PAGE ) ), $result['hash'] );
	}

	/**
	 * @return iterable<string, array{0:object}>
	 */
	public static function writers_that_read_a_custom_code_grant(): iterable {
		yield 'add-container' => [ new AddContainer() ];
		yield 'add-widget' => [ new AddWidget() ];
		yield 'update-element' => [ new UpdateElement() ];
		yield 'batch-mutate' => [ new BatchMutate() ];
		yield 'kit-batch-mutate' => [ new KitBatchMutate() ];
		yield 'update-page-settings' => [ new UpdatePageSettings() ];
	}

	/**
	 * @dataProvider writers_that_read_a_custom_code_grant
	 */
	public function test_a_writer_that_reads_a_custom_code_grant_declares_it( object $ability ): void {
		self::assertSame( 'string', $ability->input_schema()['properties']['custom_code_grant']['type'] );
	}

	// ---- update-page-settings --------------------------------------------------------

	public function test_update_page_settings_stores_a_value_with_a_backslash_unchanged(): void {
		$result = ( new UpdatePageSettings() )->execute( [ 'post_id' => self::PAGE, 'settings' => [ 'page_title_selector' => 'h1 \\ h2' ], 'mode' => 'merge' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . $result->get_error_message() : '' );
		self::assertSame( 'h1 \\ h2', $GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta['_elementor_page_settings']['page_title_selector'] );
	}
}

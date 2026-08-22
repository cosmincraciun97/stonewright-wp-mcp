<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\DirectionActivate;
use Stonewright\WpMcp\Abilities\Design\DirectionGet;
use Stonewright\WpMcp\Abilities\Design\DirectionList;
use Stonewright\WpMcp\Abilities\Design\DirectionRestore;
use Stonewright\WpMcp\Abilities\Design\DirectionSave;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Contract tests for the five typed design direction abilities.
 *
 * These assert the security envelope rather than the lifecycle rules: which
 * permission helper gates each ability, which abilities the registry forces a
 * task context token on, when production-safe confirmation blocks a write,
 * that oversized payloads never reach validation, that writes are audited, and
 * that every write result carries a compact summary plus the contract hash.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionList
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionGet
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionSave
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionActivate
 * @covers \Stonewright\WpMcp\Abilities\Design\DirectionRestore
 */
final class DirectionAbilitiesTest extends TestCase {

	private const ACTIVE_OPTION = 'stonewright_active_design_direction_id';

	private DirectionAbilityRepository $repository;

	private DesignDirectionService $service;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];

		$this->repository = new DirectionAbilityRepository();
		$this->service    = new DesignDirectionService( $this->repository );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	// -------------------------------------------------------------------------
	// Names and registration.
	// -------------------------------------------------------------------------

	public function test_abilities_use_the_documented_slugs(): void {
		$this->assertSame( 'stonewright/design-direction-list', ( new DirectionList( $this->service ) )->name() );
		$this->assertSame( 'stonewright/design-direction-get', ( new DirectionGet( $this->service ) )->name() );
		$this->assertSame( 'stonewright/design-direction-save', ( new DirectionSave( $this->service ) )->name() );
		$this->assertSame( 'stonewright/design-direction-activate', ( new DirectionActivate( $this->service ) )->name() );
		$this->assertSame( 'stonewright/design-direction-restore', ( new DirectionRestore( $this->service ) )->name() );
	}

	public function test_registry_publishes_all_five_direction_abilities(): void {
		$names = array_column( AbilityRegistry::all_abilities(), 'name' );

		foreach ( [ 'list', 'get', 'save', 'activate', 'restore' ] as $verb ) {
			$this->assertContains( 'stonewright/design-direction-' . $verb, $names );
		}
	}

	public function test_writes_require_the_task_context_token_and_reads_do_not(): void {
		$schemas = [];
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			$schemas[ $ability['name'] ] = $ability['input_schema'];
		}

		foreach ( [ 'save', 'activate', 'restore' ] as $verb ) {
			$schema = $schemas[ 'stonewright/design-direction-' . $verb ] ?? [];
			$this->assertArrayHasKey( 'stonewright_context_token', $schema['properties'] ?? [], $verb );
			$this->assertContains( 'stonewright_context_token', $schema['required'] ?? [], $verb );
		}

		foreach ( [ 'list', 'get' ] as $verb ) {
			$schema = $schemas[ 'stonewright/design-direction-' . $verb ] ?? [];
			$this->assertArrayNotHasKey( 'stonewright_context_token', $schema['properties'] ?? [], $verb );
		}
	}

	public function test_design_profile_exposes_reads_but_not_writes_by_default(): void {
		$tools = ToolProfile::profile_tools( 'elementor-design' );

		$this->assertContains( 'stonewright/design-direction-list', $tools );
		$this->assertContains( 'stonewright/design-direction-get', $tools );
		$this->assertNotContains( 'stonewright/design-direction-save', $tools );
		$this->assertNotContains( 'stonewright/design-direction-activate', $tools );
		$this->assertNotContains( 'stonewright/design-direction-restore', $tools );
	}

	public function test_design_system_intent_adds_the_direction_write_tools(): void {
		$tools = ToolProfile::profile_tools(
			'elementor-design',
			'Update the design system tokens for the site',
			'elementor',
			'design system'
		);

		$this->assertContains( 'stonewright/design-direction-save', $tools );
		$this->assertContains( 'stonewright/design-direction-activate', $tools );
		$this->assertContains( 'stonewright/design-direction-restore', $tools );
	}

	// -------------------------------------------------------------------------
	// Read abilities.
	// -------------------------------------------------------------------------

	public function test_read_abilities_gate_on_the_read_permission(): void {
		$list = new DirectionList( $this->service );
		$get  = new DirectionGet( $this->service );

		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$this->assertFalse( $list->permission_callback( [] ) );
		$this->assertFalse( $get->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true ];
		$this->assertTrue( $list->permission_callback( [] ) );
		$this->assertTrue( $get->permission_callback( [] ) );
	}

	public function test_list_returns_compact_rows_with_the_contract_hash(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );

		$result = ( new DirectionList( $this->service ) )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 0, $result['active_id'] );

		$row = $result['directions'][0];
		$this->assertSame( 'quarry', $row['slug'] );
		$this->assertSame( 'Quarry', $row['name'] );
		$this->assertSame( 'draft', $row['status'] );
		$this->assertSame( 1, $row['revision'] );
		$this->assertSame( $saved['hash_after'], $row['contract_hash'] );
		$this->assertFalse( $row['ready'] );
		$this->assertSame( 1, $row['issue_count'] );
		$this->assertArrayNotHasKey( 'contract', $row );
	}

	public function test_list_filters_by_status_and_reports_the_active_pointer(): void {
		$this->service->save( $this->input( 'Quarry' ), 7 );
		$ready = $this->service->save( $this->ready_input( 'Basalt' ), 7 );
		$this->assertIsArray( $ready );
		update_option( self::ACTIVE_OPTION, (int) $ready['id'] );

		$result = ( new DirectionList( $this->service ) )->execute( [ 'status' => 'ready' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'basalt', $result['directions'][0]['slug'] );
		$this->assertTrue( $result['directions'][0]['active'] );
		$this->assertSame( (int) $ready['id'], $result['active_id'] );
	}

	public function test_list_rejects_an_unsupported_status_filter(): void {
		$result = ( new DirectionList( $this->service ) )->execute( [ 'status' => 'published' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_get_returns_the_contract_for_an_id(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );

		$result = ( new DirectionGet( $this->service ) )->execute( [ 'id' => (int) $saved['id'] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'quarry', $result['direction']['slug'] );
		$this->assertSame( $saved['hash_after'], $result['direction']['contract_hash'] );
		$this->assertSame( 'Quarry', $result['direction']['contract']['identity']['name'] );
		$this->assertSame( [ 'brief' => 'brief:12' ], $result['direction']['source_refs'] );
		$this->assertSame( [], $result['versions'] );
	}

	public function test_get_resolves_a_slug(): void {
		$this->service->save( $this->input(), 7 );

		$result = ( new DirectionGet( $this->service ) )->execute( [ 'slug' => 'quarry' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'quarry', $result['direction']['slug'] );
	}

	public function test_get_returns_history_without_full_contracts(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );
		$changed = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$this->service->save( $changed, 7 );

		$result = ( new DirectionGet( $this->service ) )->execute(
			[
				'id'               => (int) $saved['id'],
				'include_versions' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 2, 1 ], array_column( $result['versions'], 'revision' ) );
		$this->assertArrayNotHasKey( 'contract', $result['versions'][0] );
		$this->assertArrayHasKey( 'contract_hash', $result['versions'][0] );
	}

	public function test_get_reports_a_missing_direction(): void {
		$result = ( new DirectionGet( $this->service ) )->execute( [ 'id' => 404 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_get_requires_an_id_or_slug(): void {
		$result = ( new DirectionGet( $this->service ) )->execute( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Save.
	// -------------------------------------------------------------------------

	public function test_write_abilities_gate_on_the_design_write_permission(): void {
		$abilities = [
			new DirectionSave( $this->service ),
			new DirectionActivate( $this->service ),
			new DirectionRestore( $this->service ),
		];

		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		foreach ( $abilities as $ability ) {
			$this->assertFalse( $ability->permission_callback( [] ), $ability->name() );
		}

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		foreach ( $abilities as $ability ) {
			$this->assertFalse( $ability->permission_callback( [] ), $ability->name() );
		}

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		foreach ( $abilities as $ability ) {
			$this->assertTrue( $ability->permission_callback( [] ), $ability->name() );
		}
	}

	public function test_save_returns_a_compact_summary_and_the_contract_hash(): void {
		$result = ( new DirectionSave( $this->service ) )->execute( $this->input() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'quarry', $result['slug'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 1, $result['revision'] );
		$this->assertTrue( $result['versioned'] );
		$this->assertSame( '', $result['previous_contract_hash'] );
		$this->assertSame( 64, strlen( (string) $result['contract_hash'] ) );
		$this->assertSame( $result['contract_hash'], $result['after_sha256'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertArrayNotHasKey( 'contract', $result );
	}

	public function test_save_verifies_the_stored_contract_hash_on_readback(): void {
		$result = ( new DirectionSave( $this->service ) )->execute( $this->input() );

		$this->assertIsArray( $result );
		$stored = $this->repository->get( (int) $result['id'] );
		$this->assertIsArray( $stored );
		$this->assertSame( $result['contract_hash'], $stored['contract_hash'] );
		$this->assertSame( 'verified', $result['verification_status'] );
	}

	public function test_save_rejects_an_oversized_contract_before_validation(): void {
		$input                                     = $this->input();
		$input['contract']['identity']['summary'] = str_repeat( 'a', 300000 );

		$result = ( new DirectionSave( $this->service ) )->execute( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_payload_too_large', $result->get_error_code() );
		$this->assertSame( [], $this->repository->records );
	}

	public function test_save_propagates_a_contract_validation_error(): void {
		$input             = $this->input();
		$input['contract'] = [ 'schema_version' => '1.0' ];

		$result = ( new DirectionSave( $this->service ) )->execute( $input );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_save_records_an_audit_event(): void {
		( new DirectionSave( $this->service ) )->execute( $this->input() );

		$this->assertContains(
			'stonewright/design-direction-save',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	public function test_save_audit_event_redacts_the_contract_payload(): void {
		( new DirectionSave( $this->service ) )->execute( $this->input() );

		$rows = $this->audit_rows();
		$this->assertNotSame( [], $rows );
		$this->assertStringNotContainsString( 'Stone and precision.', (string) $rows[0]['sanitized_args'] );
	}

	// -------------------------------------------------------------------------
	// Activate.
	// -------------------------------------------------------------------------

	public function test_activate_moves_the_pointer_and_reports_the_previous_id(): void {
		$first = $this->service->save( $this->ready_input( 'Quarry' ), 7 );
		$this->assertIsArray( $first );
		update_option( self::ACTIVE_OPTION, (int) $first['id'] );
		$second = $this->service->save( $this->ready_input( 'Basalt' ), 7 );
		$this->assertIsArray( $second );

		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => (int) $second['id'] ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( (int) $second['id'], $result['active_id'] );
		$this->assertSame( (int) $first['id'], $result['previous_active_id'] );
		$this->assertSame( (int) $second['id'], (int) get_option( self::ACTIVE_OPTION, 0 ) );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( 64, strlen( (string) $result['contract_hash'] ) );
	}

	public function test_activate_refuses_a_direction_that_is_not_ready(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );

		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => (int) $saved['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_ready', $result->get_error_code() );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_is_blocked_without_a_token_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );

		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => (int) $saved['id'] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_accepts_a_token_bound_to_the_same_direction(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$id    = (int) $saved['id'];
		$token = ConfirmationToken::issue( 'stonewright/design-direction-activate', [ 'id' => $id ] );

		$result = ( new DirectionActivate( $this->service ) )->execute(
			[
				'id'                 => $id,
				'confirmation_token' => $token,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_rejects_a_token_issued_for_another_direction(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$first = $this->service->save( $this->ready_input( 'Quarry' ), 7 );
		$other = $this->service->save( $this->ready_input( 'Basalt' ), 7 );
		$this->assertIsArray( $first );
		$this->assertIsArray( $other );
		$token = ConfirmationToken::issue(
			'stonewright/design-direction-activate',
			[ 'id' => (int) $first['id'] ]
		);

		$result = ( new DirectionActivate( $this->service ) )->execute(
			[
				'id'                 => (int) $other['id'],
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_records_an_audit_event(): void {
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		( new DirectionActivate( $this->service ) )->execute( [ 'id' => (int) $saved['id'] ] );

		$this->assertContains(
			'stonewright/design-direction-activate',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	public function test_activate_id_zero_deactivates_the_current_direction(): void {
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 7 );

		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => 0 ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 0, $result['active_id'] );
		$this->assertSame( (int) $saved['id'], $result['previous_active_id'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertSame( 'verified', $result['verification_status'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( '', $result['slug'] );
		$this->assertSame( '', $result['contract_hash'] );
		$this->assertSame( 'design_direction.deactivate', $result['operation_class'] );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_id_zero_is_idempotent_when_none_is_active(): void {
		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => 0 ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, $result['id'] );
		$this->assertSame( 0, $result['active_id'] );
		$this->assertSame( 0, $result['previous_active_id'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_id_zero_is_blocked_without_a_token_in_production_safe_mode(): void {
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 7 );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => 0 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertSame( (int) $saved['id'], (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_id_zero_rejects_a_token_issued_for_another_direction(): void {
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 7 );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$token = ConfirmationToken::issue(
			'stonewright/design-direction-activate',
			[ 'id' => (int) $saved['id'] ]
		);

		$result = ( new DirectionActivate( $this->service ) )->execute(
			[
				'id'                 => 0,
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		$this->assertSame( (int) $saved['id'], (int) get_option( self::ACTIVE_OPTION, 0 ) );
	}

	public function test_activate_id_zero_accepts_a_token_bound_to_zero(): void {
		$saved = $this->service->save( $this->ready_input(), 7 );
		$this->assertIsArray( $saved );
		$this->service->activate( (int) $saved['id'], 7 );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$token = ConfirmationToken::issue( 'stonewright/design-direction-activate', [ 'id' => 0 ] );

		$result = ( new DirectionActivate( $this->service ) )->execute(
			[
				'id'                 => 0,
				'confirmation_token' => $token,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 0, (int) get_option( self::ACTIVE_OPTION, 0 ) );
		$this->assertSame( (int) $saved['id'], $result['previous_active_id'] );
	}

	public function test_activate_rejects_a_negative_id(): void {
		$result = ( new DirectionActivate( $this->service ) )->execute( [ 'id' => -1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Restore.
	// -------------------------------------------------------------------------

	public function test_restore_writes_a_new_revision_and_names_the_restored_one(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );
		$changed = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$second = $this->service->save( $changed, 7 );
		$this->assertIsArray( $second );

		$result = ( new DirectionRestore( $this->service ) )->execute(
			[
				'id'       => (int) $saved['id'],
				'revision' => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['revision'] );
		$this->assertSame( 1, $result['restored_revision'] );
		$this->assertTrue( $result['versioned'] );
		$this->assertSame( $saved['hash_after'], $result['contract_hash'] );
		$this->assertSame( $second['hash_after'], $result['previous_contract_hash'] );
		$this->assertTrue( $result['effect_verified'] );
	}

	public function test_restore_is_blocked_without_a_token_in_production_safe_mode(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new DirectionRestore( $this->service ) )->execute(
			[
				'id'       => (int) $saved['id'],
				'revision' => 1,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertSame( 1, (int) $this->repository->records[ (int) $saved['id'] ]['revision'] );
	}

	public function test_restore_rejects_a_token_issued_for_another_revision(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );
		$changed = $this->input();
		$changed['contract']['identity']['summary'] = 'Sharper edges.';
		$this->service->save( $changed, 7 );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$token = ConfirmationToken::issue(
			'stonewright/design-direction-restore',
			[
				'id'       => (int) $saved['id'],
				'revision' => 2,
			]
		);

		$result = ( new DirectionRestore( $this->service ) )->execute(
			[
				'id'                 => (int) $saved['id'],
				'revision'           => 1,
				'confirmation_token' => $token,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_restore_reports_an_unknown_revision(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );

		$result = ( new DirectionRestore( $this->service ) )->execute(
			[
				'id'       => (int) $saved['id'],
				'revision' => 9,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_not_found', $result->get_error_code() );
	}

	public function test_restore_requires_a_positive_revision(): void {
		$result = ( new DirectionRestore( $this->service ) )->execute(
			[
				'id'       => 1,
				'revision' => 0,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_restore_records_an_audit_event(): void {
		$saved = $this->service->save( $this->input(), 7 );
		$this->assertIsArray( $saved );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		( new DirectionRestore( $this->service ) )->execute(
			[
				'id'       => (int) $saved['id'],
				'revision' => 1,
			]
		);

		$this->assertContains(
			'stonewright/design-direction-restore',
			array_column( $this->audit_rows(), 'ability_name' )
		);
	}

	// -------------------------------------------------------------------------
	// Fixtures.
	// -------------------------------------------------------------------------

	/**
	 * Audit-log rows captured by the test wpdb double.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function audit_rows(): array {
		$rows = [];

		foreach ( (array) ( $GLOBALS['stonewright_test_wpdb_inserts'] ?? [] ) as $insert ) {
			$data = is_array( $insert['data'] ?? null ) ? $insert['data'] : $insert;
			if ( isset( $data['ability_name'] ) ) {
				$rows[] = $data;
			}
		}

		return $rows;
	}

	/**
	 * A draft save payload.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function input( string $name = 'Quarry' ): array {
		return [
			'slug'        => sanitize_title( $name ),
			'status'      => 'draft',
			'source_type' => 'manual',
			'source_refs' => [ 'brief' => 'brief:12' ],
			'contract'    => $this->contract( $name ),
		];
	}

	/**
	 * A save payload whose contract is ready for activation.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function ready_input( string $name = 'Quarry' ): array {
		$input                                   = $this->input( $name );
		$input['status']                         = 'ready';
		$input['contract']['readiness']          = [
			'ready'      => true,
			'sync_ready' => false,
			'issues'     => [],
		];

		return $input;
	}

	/**
	 * A valid contract.
	 *
	 * @param string $name Direction identity name.
	 * @return array<string,mixed>
	 */
	private function contract( string $name ): array {
		return [
			'schema_version' => '1.0',
			'identity'       => [
				'name'    => $name,
				'summary' => 'Stone and precision.',
			],
			'tokens'         => [
				'colors'     => [ 'brand' => '#1f2933' ],
				'typography' => [
					'body' => [
						'family' => 'Inter',
						'size'   => '1rem',
					],
				],
				'spacing'    => [ 'md' => '1rem' ],
				'radii'      => [ 'sm' => '2px' ],
				'elevation'  => [ 'low' => '0 1px 2px rgba(0,0,0,0.12)' ],
				'motion'     => [ 'fast' => 120 ],
			],
			'components'     => [],
			'dials'          => [
				'variance' => 20,
				'density'  => 40,
				'motion'   => 10,
			],
			'guidance'       => [
				'do'    => [ 'Keep surfaces quiet.' ],
				'avoid' => [ 'Decorative gradients.' ],
			],
			'provenance'     => [
				'tokens.colors.brand' => [
					'source'    => 'brief',
					'reference' => 'brief:12',
				],
			],
			'waivers'        => [],
			'readiness'      => [
				'ready'      => false,
				'sync_ready' => false,
				'issues'     => [ 'Component coverage incomplete.' ],
			],
		];
	}
}

/**
 * In-memory repository double for the ability contract tests.
 *
 * Kept separate from the service test's double so both files can run in the
 * same process without a class-name collision.
 */
final class DirectionAbilityRepository extends DesignDirectionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	private int $next_version_id = 1;

	public function __construct() {
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return list<array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		$records = array_values( $this->records );

		if ( isset( $filters['status'] ) ) {
			$records = array_values(
				array_filter(
					$records,
					static fn( array $record ): bool => $record['status'] === $filters['status']
				)
			);
		}

		return $records;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 * @return int|\WP_Error
	 */
	public function save( array $record ) {
		$id = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;

		$record['id']           = $id;
		$record['created_at'] ??= '2026-07-24 09:00:00';
		$record['updated_at']   = '2026-07-24 09:00:00';
		$this->records[ $id ]   = $record;

		return $id;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return int|\WP_Error
	 */
	public function add_version( array $snapshot ) {
		$snapshot['id']         = $this->next_version_id++;
		$snapshot['created_at'] = '2026-07-24 09:00:00';
		$this->version_rows[]   = $snapshot;

		return (int) $snapshot['id'];
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function versions( int $id ): array {
		$rows = array_values(
			array_filter(
				$this->version_rows,
				static fn( array $row ): bool => (int) $row['direction_id'] === $id
			)
		);

		usort( $rows, static fn( array $a, array $b ): int => (int) $b['revision'] <=> (int) $a['revision'] );

		return $rows;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function version( int $id, int $revision ): ?array {
		foreach ( $this->version_rows as $row ) {
			if ( (int) $row['direction_id'] === $id && (int) $row['revision'] === $revision ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function archive( int $id ) {
		if ( ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'stonewright_direction_write_failed', 'Missing record.' );
		}

		$this->records[ $id ]['status'] = 'archived';

		return true;
	}

	public function begin_transaction(): void {
	}

	public function commit_transaction(): void {
	}

	public function rollback_transaction(): void {
	}
}

<?php
/**
 * An adapter that only has a restore function (the contract of the families that restore through one entry point)
 * plugs into the registry through CallableRollbackFamily, and the engine still applies every gate.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Rollback\CallableRollbackFamily;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;

/**
 * @covers \Stonewright\WpMcp\Security\Rollback\CallableRollbackFamily
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 */
final class CallableRollbackFamilyTest extends RollbackTestCase {

	/** @var list<array{id:string,options:array<string,mixed>}> */
	private array $calls = [];

	private string $status = 'succeeded';

	protected function setUp(): void {
		parent::setUp();
		$this->calls  = [];
		$this->status = 'succeeded';
		RollbackFamilies::register(
			new CallableRollbackFamily(
				[ 'media', 'user', 'plugin' ],
				function ( string $change_id, array $options ): array {
					$this->calls[] = [ 'id' => $change_id, 'options' => $options ];
					if ( 'succeeded' !== $this->status ) {
						return [ 'status' => $this->status, 'detail' => 'adapter_said_no', 'limits' => [], 'rollback_change_id' => null ];
					}
					$parent = ChangeLedger::get( $change_id );
					$child  = ChangeLedger::record(
						[
							'ability'       => 'stonewright/change-rollback',
							'family'        => $parent['family'],
							'resource_type' => $parent['resource_type'],
							'resource_id'   => $parent['resource_id'],
							'kind'          => (string) ( $options['kind'] ?? 'rollback' ),
							'parent_id'     => $change_id,
							'before'        => [ 'fields' => [ 'alt' => 'New' ] ],
						]
					);
					ChangeLedger::settle( $child['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'alt' => 'Old' ] ] ] );
					return [ 'status' => 'succeeded', 'detail' => '', 'limits' => [ 'the file itself is not restored' ], 'rollback_change_id' => $child['change_id'] ];
				}
			)
		);
	}

	private function media_change( string $family = 'media' ): string {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/media-update', 'family' => $family, 'resource_type' => $family, 'resource_id' => '9', 'before' => [ 'fields' => [ 'alt' => 'Old' ] ] ] );
		self::assertIsArray( $row );
		ChangeLedger::settle( $row['change_id'], [ 'status' => 'verified', 'after' => [ 'fields' => [ 'alt' => 'New' ] ] ] );
		return $row['change_id'];
	}

	public function test_the_plan_says_the_live_state_cannot_be_compared_and_does_not_call_the_adapter(): void {
		$id = $this->media_change();

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertFalse( $plan['drift_known'] );
		self::assertFalse( $plan['drift'] );
		self::assertSame( 'no_live', $plan['diff']['status'] );
		self::assertStringContainsString( 'cannot compare', implode( ' ', $plan['warnings'] ) );
		self::assertSame( [], $this->calls );
	}

	public function test_a_run_calls_the_adapter_with_the_row_and_the_options_and_keeps_the_row_it_wrote(): void {
		$id = $this->media_change();

		$result = ChangeRollback::run( $id );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertCount( 1, $this->calls );
		self::assertSame( $id, $this->calls[0]['id'] );
		self::assertSame( 'rollback', $this->calls[0]['options']['kind'] );
		self::assertFalse( $this->calls[0]['options']['permanent'] );
		self::assertSame( [ 'the file itself is not restored' ], $result['limits'] );
		$children = ChangeLedger::children( $id );
		self::assertCount( 1, $children, 'The adapter wrote the row; the engine wrote none.' );
		self::assertSame( $children[0]['change_id'], $result['rollback_change_id'] );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'] );
		self::assertSame( 'verified', $this->row( $children[0]['change_id'] )['status'] );
	}

	public function test_a_redo_is_asked_for_on_the_rollback_row(): void {
		$id   = $this->media_change();
		$undo = ChangeRollback::run( $id );
		self::assertIsArray( $undo );

		$redo = ChangeRollback::run( $undo['rollback_change_id'] );

		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( 'redo', $this->calls[1]['options']['kind'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
	}

	public function test_permanent_is_passed_on_only_when_asked_for_and_is_part_of_what_a_token_covers(): void {
		$id = $this->media_change();
		$this->production_safe();

		$plain = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [] ) );
		$this->assert_refused( ChangeRollback::run( $id, [ 'permanent' => true, 'confirmation_token' => $plain ] ), 'stonewright_confirmation_args_mismatch' );
		self::assertSame( [], $this->calls );

		$token  = ConfirmationToken::issue( ChangeRollback::ABILITY, ChangeRollback::confirmation_args( $id, [ 'permanent' => true ] ) );
		$result = ChangeRollback::run( $id, [ 'permanent' => true, 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $this->calls[0]['options']['permanent'] );
	}

	public function test_the_engine_applies_the_gates_the_adapter_does_not(): void {
		$id = $this->media_change();
		$this->production_safe();
		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_confirmation_required' );
		$this->as_user_without_manage_options();
		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_forbidden' );
		self::assertSame( [], $this->calls, 'The adapter only checks a capability; the engine stopped the run first.' );
	}

	public function test_a_row_the_adapter_recorded_as_not_restorable_is_refused_before_the_adapter_is_called(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/plugin-delete', 'family' => 'plugin', 'resource_type' => 'plugin', 'resource_id' => 'example/example.php', 'before' => null, 'restorable' => false, 'restorable_reason' => 'plugin_deleted' ] );
		self::assertIsArray( $row );

		$error = $this->assert_refused( ChangeRollback::run( $row['change_id'] ), 'stonewright_change_not_restorable' );

		self::assertSame( 'plugin_deleted', $error->get_error_data()['reason'] );
		self::assertSame( [], $this->calls );
	}

	public function test_a_failed_adapter_is_reported_with_its_detail(): void {
		$id           = $this->media_change();
		$this->status = 'failed';

		$error = ChangeRollback::run( $id );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_change_rollback_failed', $error->get_error_code() );
		self::assertSame( 'adapter_said_no', $error->get_error_data()['detail'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
	}

	public function test_the_family_can_ask_for_a_person(): void {
		RollbackFamilies::register( new CallableRollbackFamily( [ 'user' ], static fn ( string $id, array $o ): array => [ 'status' => 'succeeded', 'detail' => '' ], null, true ) );
		$id = $this->media_change( 'user' );

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_rescue_approval_required' );
	}
}

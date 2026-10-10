<?php
/**
 * Skills and design directions in the change ledger: a row links to the revision the stores already keep.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\Adapters\RevisionLinkAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\DirectionRepositoryDouble;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\SkillsAndLedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\RevisionLinkAdapter
 */
final class RevisionLinkAdapterTest extends OtherFamilyLedgerTestCase {

	private const FIRST = "# Guide\n\nFirst text of the site guide.\n";

	private const SECOND = "# Guide\n\nSecond text of the site guide.\n";

	private SkillsAndLedgerWpdb $both;

	private DirectionRepositoryDouble $directions;

	private DesignDirectionService $direction_service;

	protected function setUp(): void {
		parent::setUp();
		$this->both = new SkillsAndLedgerWpdb();
		$this->both->unique[ $this->both->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$this->db                                                          = $this->both;
		$GLOBALS['wpdb']                                                   = $this->both;
		$GLOBALS['stonewright_test_user_caps']                             = [ 'manage_options' => true ];
		$this->directions                                                  = new DirectionRepositoryDouble();
		$this->direction_service                                           = new DesignDirectionService( $this->directions );
		RevisionLinkAdapter::use_direction_service_for_tests( $this->direction_service );
	}

	protected function tearDown(): void {
		RevisionLinkAdapter::use_direction_service_for_tests( null );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function skill_input( string $content = self::FIRST ): array {
		return [ 'slug' => 'site-guide', 'title' => 'Site guide', 'description' => 'Use when testing the ledger.', 'content' => $content, 'enabled' => true, 'enable_agentic' => true, 'enable_prompt' => true ];
	}

	/** @return array<string, string|null> */
	private function skill_row(): array {
		return $this->both->skills->skills[1];
	}

	public function test_a_new_skill_is_a_created_row_and_a_save_links_the_revision_without_a_copy_of_the_text(): void {
		$service = SkillLibraryService::open();

		self::assertSame( 1, $service->save_skill( $this->skill_input() ) );
		self::assertSame( 1, $service->save_skill( $this->skill_input( self::SECOND ) ) );

		$rows = $this->ledger_rows();
		self::assertCount( 2, $rows );
		self::assertSame( [ 'skill', 'site-guide', 'stonewright/skills-save' ], [ $rows[0]['resource_type'], $rows[0]['resource_id'], $rows[0]['ability'] ] );
		self::assertStringStartsWith( 'Created skill', $rows[0]['summary'] );
		self::assertTrue( $rows[0]['restorable'] );
		self::assertStringStartsWith( 'Updated skill', $rows[1]['summary'] );
		$before = ChangeLedger::read_image( $rows[1]['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $rows[1]['change_id'], 'after' );
		self::assertSame( [ 1, 'site-guide' ], [ $before['revision'], $before['slug'] ] );
		self::assertSame( 2, $after['revision'] );
		self::assertSame( hash( 'sha256', self::FIRST ), $before['content_sha256'] );
		self::assertSame( hash( 'sha256', self::SECOND ), $after['content_sha256'] );
		$stored = $this->stored_text();
		self::assertStringNotContainsString( 'First text of the site guide', $stored, 'The ledger keeps no second copy of the text.' );
		self::assertStringNotContainsString( 'Second text of the site guide', $stored );
	}

	public function test_restore_of_a_save_goes_through_the_revision_the_skill_store_keeps(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input() );
		$service->save_skill( $this->skill_input( self::SECOND ) );
		$change = $this->ledger_rows()[1];

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( self::FIRST, $this->skill_row()['content'] );
		self::assertSame( '3', $this->skill_row()['revision'], 'History moves forward: the restore is a new revision.' );
		$rollback = $this->rows_of_kind( 'rollback' )[0];
		self::assertSame( $change['change_id'], $rollback['parent_id'] );
		self::assertSame( 'noop', OtherFamilies::restore( $change['change_id'] )['status'] );
	}

	public function test_the_undo_of_a_created_skill_moves_it_to_the_trash(): void {
		SkillLibraryService::open()->save_skill( $this->skill_input() );
		$change = $this->ledger_rows()[0];

		$undo = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertSame( 'trashed', $this->skill_row()['status'] );
		self::assertSame( 'noop', OtherFamilies::restore( $change['change_id'] )['status'] );
	}

	public function test_a_toggle_has_no_revision_and_is_recorded_as_not_restorable_with_its_reason(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input() );

		self::assertTrue( $service->set_enabled( 1, false ) );

		$row = $this->ledger_rows()[1];
		self::assertSame( [ 'stonewright/skills-toggle', false, 'no_revision_stored' ], [ $row['ability'], $row['restorable'], $row['restorable_reason'] ] );
		self::assertSame( 'failed', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_a_permanent_delete_is_not_restorable_because_the_history_goes_with_it(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input() );
		$service->move_to_trash( 1 );

		self::assertTrue( $service->erase_skill( 1 ) );

		$rows = $this->ledger_rows();
		self::assertSame( [ 'stonewright/skills-trash', 'stonewright/skills-destroy' ], array_slice( array_column( $rows, 'ability' ), 1 ) );
		self::assertSame( [ false, 'skill_history_deleted' ], [ $rows[2]['restorable'], $rows[2]['restorable_reason'] ] );
	}

	public function test_a_refused_skill_write_records_nothing(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input() );
		$before = count( $this->ledger_rows() );

		$stale = $service->save_skill( $this->skill_input( self::SECOND ) + [ 'revision' => 9 ] );

		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertCount( $before, $this->ledger_rows() );
	}

	public function test_a_skill_restore_needs_the_manage_options_capability(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input() );
		$service->save_skill( $this->skill_input( self::SECOND ) );
		$change                                        = $this->ledger_rows()[1];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'manage_options' !== $cap;

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( self::SECOND, $this->skill_row()['content'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function direction_input( string $summary = 'Stone and precision.' ): array {
		return [
			'slug'        => 'quarry',
			'status'      => 'draft',
			'source_type' => 'manual',
			'source_refs' => [ 'brief' => 'brief:12' ],
			'contract'    => [
				'schema_version' => '1.0',
				'identity'       => [ 'name' => 'Quarry', 'summary' => $summary ],
				'tokens'         => [
					'colors'     => [ 'brand' => '#1f2933' ],
					'typography' => [ 'body' => [ 'family' => 'Inter', 'size' => '1rem' ] ],
					'spacing'    => [ 'md' => '1rem' ],
					'radii'      => [ 'sm' => '2px' ],
					'elevation'  => [ 'low' => '0 1px 2px rgba(0,0,0,0.12)' ],
					'motion'     => [ 'fast' => 120 ],
				],
				'components'     => [],
				'dials'          => [ 'variance' => 20, 'density' => 40, 'motion' => 10 ],
				'guidance'       => [ 'do' => [ 'Keep surfaces quiet.' ], 'avoid' => [ 'Decorative gradients.' ] ],
				'provenance'     => [ 'tokens.colors.brand' => [ 'source' => 'brief', 'reference' => 'brief:12' ] ],
				'waivers'        => [],
				'readiness'      => [ 'ready' => false, 'sync_ready' => false, 'issues' => [ 'Component coverage incomplete.' ] ],
			],
		];
	}

	public function test_a_direction_save_links_the_revision_and_keeps_no_copy_of_the_contract(): void {
		$this->direction_service->save( $this->direction_input(), 7 );
		$this->direction_service->save( $this->direction_input( 'Sharper edges.' ), 7 );

		$rows = $this->ledger_rows();
		self::assertCount( 2, $rows );
		self::assertSame( [ 'design_direction', '1', 'stonewright/design-direction-save' ], [ $rows[0]['resource_type'], $rows[0]['resource_id'], $rows[0]['ability'] ] );
		self::assertSame( [ 'design_direction', true ], [ $rows[0]['family'], $rows[0]['restorable'] ] );
		self::assertStringStartsWith( 'Created design direction', $rows[0]['summary'] );
		$before = ChangeLedger::read_image( $rows[1]['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $rows[1]['change_id'], 'after' );
		self::assertSame( [ 1, 'quarry' ], [ $before['revision'], $before['slug'] ] );
		self::assertSame( 2, $after['revision'] );
		self::assertSame( 64, strlen( $after['contract_hash'] ) );
		self::assertNotSame( $before['contract_hash'], $after['contract_hash'] );
		self::assertStringNotContainsString( 'Sharper edges', $this->stored_text() );
		self::assertStringNotContainsString( 'Stone and precision', $this->stored_text() );
	}

	public function test_a_byte_identical_direction_save_records_nothing(): void {
		$this->direction_service->save( $this->direction_input(), 7 );
		$this->direction_service->save( $this->direction_input(), 7 );

		self::assertCount( 1, $this->ledger_rows() );
	}

	public function test_restore_of_a_direction_change_goes_through_the_service_revision(): void {
		$this->direction_service->save( $this->direction_input(), 7 );
		$this->direction_service->save( $this->direction_input( 'Sharper edges.' ), 7 );
		$change = $this->ledger_rows()[1];

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'Stone and precision.', $this->directions->records[1]['contract']['identity']['summary'] );
		self::assertSame( 3, $this->directions->records[1]['revision'], 'History is append-only: the restore is revision 3.' );
		self::assertSame( $change['change_id'], $this->rows_of_kind( 'rollback' )[0]['parent_id'] );
	}

	public function test_the_undo_of_a_created_direction_archives_it(): void {
		$this->direction_service->save( $this->direction_input(), 7 );

		$undo = OtherFamilies::restore( $this->ledger_rows()[0]['change_id'] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertSame( 'archived', $this->directions->records[1]['status'] );
	}

	public function test_activating_and_clearing_the_active_direction_are_recorded_and_restored(): void {
		$ready                                      = $this->direction_input();
		$ready['status']                            = 'ready';
		$ready['contract']['readiness']             = [ 'ready' => true, 'sync_ready' => false, 'issues' => [] ];
		$this->direction_service->save( $ready, 7 );
		$before_rows = count( $this->ledger_rows() );

		$this->direction_service->activate( 1, 7 );

		$rows = $this->ledger_rows();
		self::assertCount( $before_rows + 1, $rows );
		$row = $rows[ $before_rows ];
		self::assertSame( [ 'design_direction_pointer', 'active', 'stonewright/design-direction-activate', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['restorable'] ] );
		self::assertSame( 0, ChangeLedger::read_image( $row['change_id'], 'before' )['active_id'] );
		self::assertSame( 1, ChangeLedger::read_image( $row['change_id'], 'after' )['active_id'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 0, (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) );

		$this->direction_service->activate( 1, 7 );
		$this->direction_service->deactivate( 7 );
		$last = $this->ledger_rows();
		$last = $last[ count( $last ) - 1 ];
		self::assertSame( 1, ChangeLedger::read_image( $last['change_id'], 'before' )['active_id'] );
		OtherFamilies::restore( $last['change_id'] );
		self::assertSame( 1, (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) );
	}

	public function test_a_direction_restore_needs_the_design_capability(): void {
		$this->direction_service->save( $this->direction_input(), 7 );
		$this->direction_service->save( $this->direction_input( 'Sharper edges.' ), 7 );
		$change                                        = $this->ledger_rows()[1];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => false;

		$restore = OtherFamilies::restore( $change['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( 2, $this->directions->records[1]['revision'] );
	}
}

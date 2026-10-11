<?php
/**
 * The rollback engine against skills and design directions, whose rows link to the revisions their own stores keep:
 * an undo and a redo through the engine, and drift from the live revision.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Security\Adapters\RevisionLinkAdapter;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\DirectionRepositoryDouble;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\SkillsAndLedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\OtherRollbackFamilies
 * @covers \Stonewright\WpMcp\Security\Adapters\RevisionLinkAdapter
 */
final class ChangeRollbackRevisionFamiliesTest extends OtherRollbackTestCase {

	private const FIRST = "# Guide\n\nFirst text of the site guide.\n";

	private const SECOND = "# Guide\n\nSecond text of the site guide.\n";

	private SkillsAndLedgerWpdb $both;

	private DirectionRepositoryDouble $directions;

	private DesignDirectionService $direction_service;

	protected function setUp(): void {
		parent::setUp();
		$this->both = new SkillsAndLedgerWpdb();
		$this->both->unique[ $this->both->prefix . 'stonewright_changes' ] = [ 'change_id' ];
		$this->both->unique[ $this->both->prefix . 'options' ]             = [ 'option_name' ];
		$this->db                                                          = $this->both;
		$GLOBALS['wpdb']                                                   = $this->both;
		$this->directions                                                  = new DirectionRepositoryDouble();
		$this->direction_service                                           = new DesignDirectionService( $this->directions );
		RevisionLinkAdapter::use_direction_service_for_tests( $this->direction_service );
	}

	protected function tearDown(): void {
		RevisionLinkAdapter::use_direction_service_for_tests( null );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	private function ok( array|\WP_Error $result ): array {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		return $result;
	}

	/** @return array<string, mixed> */
	private function skill_input( string $content ): array {
		return [ 'slug' => 'site-guide', 'title' => 'Site guide', 'description' => 'Use when testing the ledger.', 'content' => $content, 'enabled' => true, 'enable_agentic' => true, 'enable_prompt' => true ];
	}

	/** @return array<string, mixed> */
	private function direction_input( string $summary ): array {
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

	public function test_a_skill_save_is_undone_and_redone_through_the_revision_store(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input( self::FIRST ) );
		$service->save_skill( $this->skill_input( self::SECOND ) );
		$id = $this->ledger_rows()[1]['change_id'];

		$plan = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		self::assertTrue( $plan['drift_known'] );
		self::assertFalse( $plan['drift'] );
		self::assertSame( 'ledger', $plan['path'] );

		$undo = $this->ok( ChangeRollback::run( $id ) );
		self::assertSame( self::FIRST, $this->both->skills->skills[1]['content'] );
		self::assertSame( 'rollback', $this->row( $undo['rollback_change_id'] )['kind'] );

		$redo = $this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertSame( self::SECOND, $this->both->skills->skills[1]['content'], 'The redo brings the second text back.' );
		self::assertSame( 'redo', $this->row( $redo['rollback_change_id'] )['kind'] );
		self::assertSame( 'verified', $this->row( $id )['status'] );
	}

	public function test_a_skill_saved_again_after_the_change_is_drift(): void {
		$service = SkillLibraryService::open();
		$service->save_skill( $this->skill_input( self::FIRST ) );
		$service->save_skill( $this->skill_input( self::SECOND ) );
		$id = $this->ledger_rows()[1]['change_id'];
		$service->save_skill( $this->skill_input( "# Guide\n\nA third text, written by a person.\n" ) );

		$plan = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		self::assertTrue( $plan['drift'] );

		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );
	}

	public function test_a_direction_save_is_undone_and_redone_through_the_direction_service(): void {
		$this->direction_service->save( $this->direction_input( 'Stone and precision.' ), 7 );
		$this->direction_service->save( $this->direction_input( 'Sharper edges.' ), 7 );
		$id = $this->ledger_rows()[1]['change_id'];

		$plan = $this->ok( ChangeRollback::run( $id, [ 'dry_run' => true ] ) );
		self::assertFalse( $plan['drift'] );

		$undo = $this->ok( ChangeRollback::run( $id ) );
		self::assertSame( 'Stone and precision.', $this->directions->records[1]['contract']['identity']['summary'] );

		$redo = $this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertSame( 'Sharper edges.', $this->directions->records[1]['contract']['identity']['summary'] );
		self::assertSame( 'redo', $this->row( $redo['rollback_change_id'] )['kind'] );
	}
}

<?php
/**
 * The code adapter's recording calls: they record and settle, and never fail the write they wrap.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 * @covers \Stonewright\WpMcp\Security\ChangeLedger::sync_status
 * @covers \Stonewright\WpMcp\Security\RescueGuard::armed_id_for
 */
final class CodeAdapterCoreTest extends TestCase {
	use CodeAdapterHarness;

	protected function setUp(): void {
		$this->set_up_harness();
	}

	protected function tearDown(): void {
		$this->tear_down_harness();
	}

	/** @return array<string, mixed> */
	private function spec( array $override = [] ): array {
		return array_merge(
			[
				'ability_fallback' => 'stonewright/sandbox-write',
				'family'           => 'sandbox',
				'resource_type'    => 'sandbox_draft',
				'resource_id'      => 'demo.php',
				'before'           => "<?php\n// a\n",
			],
			$override
		);
	}

	public function test_begin_records_an_armed_row_and_finish_settles_it_with_the_after_image(): void {
		$id = CodeAdapter::begin( $this->spec() );

		self::assertNotNull( $id );
		self::assertSame( 'armed', ChangeLedger::get( $id )['status'] );

		CodeAdapter::finish( $id, 'verified', "<?php\n// b\n", 'Wrote demo.php.' );

		$row = ChangeLedger::get( $id );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( "<?php\n// a\n", ChangeLedger::read_image( $id, 'before' ) );
		self::assertSame( "<?php\n// b\n", ChangeLedger::read_image( $id, 'after' ) );
		self::assertSame( 'stonewright/sandbox-write', $row['ability'] );
		self::assertSame( 'Wrote demo.php.', $row['summary'] );
	}

	public function test_a_change_that_created_its_resource_is_restorable_without_a_before_image(): void {
		$id = CodeAdapter::begin( $this->spec( [ 'before' => null, 'created' => true ] ) );

		$row = ChangeLedger::get( (string) $id );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['before_ref'] );
	}

	public function test_a_missing_before_image_without_the_created_flag_is_not_restorable(): void {
		$id = CodeAdapter::begin( $this->spec( [ 'before' => null ] ) );

		$row = ChangeLedger::get( (string) $id );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'no_before_image', $row['restorable_reason'] );
	}

	public function test_the_ability_of_the_open_call_wins_over_the_fallback(): void {
		RescueGuard::enter( 'stonewright/sandbox-edit' );
		$id = CodeAdapter::begin( $this->spec() );
		RescueGuard::reset_for_tests();

		self::assertSame( 'stonewright/sandbox-edit', ChangeLedger::get( (string) $id )['ability'] );
	}

	public function test_a_ledger_that_cannot_record_gives_no_id_and_throws_nothing(): void {
		$this->break_ledger();

		self::assertNull( CodeAdapter::begin( $this->spec() ) );
		CodeAdapter::finish( null, 'verified', 'x' );
		CodeAdapter::finish( 'cs-' . str_repeat( 'b', 24 ), 'verified', 'x' );
		$this->addToAssertionCount( 1 );
	}

	public function test_a_ledger_that_throws_gives_no_id_and_throws_nothing(): void {
		$this->explode_ledger();

		self::assertNull( CodeAdapter::begin( $this->spec() ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_an_unusable_spec_gives_no_id_and_throws_nothing(): void {
		self::assertNull( CodeAdapter::begin( $this->spec( [ 'resource_id' => '' ] ) ) );
		self::assertNull( CodeAdapter::begin( $this->spec( [ 'family' => 'post' ] ) ), 'The code adapter records only the code families.' );
	}

	public function test_a_refused_resource_keeps_no_content_and_no_hash(): void {
		$id = CodeAdapter::begin( $this->spec( [ 'resource_type' => 'sandbox_draft', 'resource_id' => 'wp-config.php', 'before' => 'DB_PASSWORD-synthetic' ] ) );

		$row = ChangeLedger::get( (string) $id );
		self::assertSame( 'secret_file', $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'] );
		self::assertSame( '', $row['before_sha256'] );
		self::assertSame( [], $this->blob_files() );
	}

	public function test_a_journal_id_that_has_a_row_already_is_not_reused(): void {
		$first  = CodeAdapter::begin( $this->spec() );
		$second = CodeAdapter::begin( $this->spec( [ 'change_id' => $first ] ) );

		self::assertNotNull( $second );
		self::assertNotSame( $first, $second );
	}

	public function test_a_journal_id_without_a_row_becomes_the_row_id(): void {
		$id = 'cs-' . str_repeat( 'c', 24 );

		self::assertSame( $id, CodeAdapter::begin( $this->spec( [ 'change_id' => $id ] ) ) );
	}

	public function test_a_write_made_while_restoring_is_a_rollback_row_under_the_change(): void {
		$parent = (string) CodeAdapter::begin( $this->spec() );

		$child = CodeAdapter::as_rollback(
			$parent,
			function (): ?string {
				return CodeAdapter::begin( $this->spec() );
			}
		);

		$row = ChangeLedger::get( (string) $child );
		self::assertSame( 'rollback', $row['kind'] );
		self::assertSame( $parent, $row['parent_id'] );
		self::assertSame( 'change', ChangeLedger::get( (string) CodeAdapter::begin( $this->spec() ) )['kind'], 'The context ends with the call.' );
	}

	public function test_the_journal_outcome_is_mirrored_to_the_row_and_an_unknown_id_is_ignored(): void {
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/sandbox-activate',
				'resource_type' => 'sandbox',
				'resource_key'  => 'demo.php',
				'recipe'        => [ 'type' => 'sandbox_file', 'ref' => 'demo.php' ],
			]
		);
		$id = CodeAdapter::begin( $this->spec( [ 'change_id' => $entry['id'] ] ) );
		self::assertSame( $entry['id'], $id );

		ChangeJournal::settle( $entry['id'], 'verified' );
		self::assertSame( 'verified', ChangeLedger::get( $entry['id'] )['status'] );

		ChangeJournal::settle( $entry['id'], 'rolled_back' );
		self::assertSame( 'rolled_back', ChangeLedger::get( $entry['id'] )['status'] );

		ChangeLedger::sync_status( 'cs-' . str_repeat( 'd', 24 ), 'verified' );
		ChangeLedger::sync_status( 'not-an-id', 'verified' );
		$this->addToAssertionCount( 1 );
	}

	public function test_the_armed_journal_entry_of_a_resource_is_found_inside_the_call(): void {
		RescueGuard::enter( 'stonewright/sandbox-activate' );
		$armed = RescueGuard::arm_sandbox_write( 'demo.php' );

		self::assertSame( $armed, RescueGuard::armed_id_for( 'sandbox', 'demo.php' ) );
		self::assertNull( RescueGuard::armed_id_for( 'sandbox', 'other.php' ) );
		self::assertNull( RescueGuard::armed_id_for( 'custom_code', 'demo.php' ) );

		RescueGuard::reset_for_tests();
		self::assertNull( RescueGuard::armed_id_for( 'sandbox', 'demo.php' ), 'Outside a call there is nothing to find.' );
	}

	public function test_restore_of_an_unknown_family_or_row_is_not_available(): void {
		self::assertSame( 'failed', CodeAdapter::restore( 'cs-' . str_repeat( 'e', 24 ) )['status'] );
		self::assertSame( 'failed', CodeAdapter::restore( 'nope' )['status'] );
	}
}

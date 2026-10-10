<?php
/**
 * Site memory in the change ledger: a hard delete keeps the full row, and a restore puts it back.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Memory\MemoryDelete;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\Adapters\MemoryAdapter;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\MemoryAdapter
 */
final class MemoryAdapterTest extends OtherFamilyLedgerTestCase {

	private function table(): string {
		return $this->db->prefix . 'stonewright_memory';
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function seed( int $id = 9, array $overrides = [] ): void {
		$this->db->tables[ $this->table() ][] = array_merge(
			[
				'id'                  => $id,
				'scope'               => 'site',
				'type'                => 'feedback',
				'name'                => 'Brand voice',
				'memory_key'          => 'brand-voice-' . $id,
				'value_json'          => '{"tone":"plain","avoid":["jargon"]}',
				'confidence'          => '0.9000',
				'topic'               => 'copy',
				'version_fingerprint' => '',
				'expires_at'          => null,
				'status'              => 'active',
				'precedence'          => '5',
				'created_by'          => '7',
				'created_at'          => '2026-01-02 03:04:05',
				'updated_at'          => '2026-01-03 03:04:05',
				'last_retrieved_at'   => null,
			],
			$overrides
		);
		$this->db->unique[ $this->table() ] = [ 'id' ];
	}

	/** @return list<array<string, mixed>> */
	private function memory_rows(): array {
		return $this->db->tables[ $this->table() ] ?? [];
	}

	public function test_a_delete_records_the_full_row_and_restore_puts_it_back_with_its_id(): void {
		$this->seed();

		$result = ( new MemoryDelete() )->execute( [ 'id' => 9 ] );

		self::assertSame( [ 'ok' => true, 'deleted' => true ], $result );
		self::assertSame( [], $this->memory_rows() );
		$row = $this->row_of( 'memory' );
		self::assertSame( [ 'memory', '9', 'stonewright/memory-delete', 'verified', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringStartsWith( 'Deleted memory entry', $row['summary'] );
		self::assertSame( '', $row['after_ref'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertEquals( [ 'site', 'brand-voice-9', 'Brand voice', [ 'tone' => 'plain', 'avoid' => [ 'jargon' ] ] ], [ $before['entry']['scope'], $before['entry']['memory_key'], $before['entry']['name'], $before['entry']['value'] ] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		$back = Memory::get_by_id( 9 );
		self::assertSame( [ 'site', 'brand-voice-9', 'Brand voice', 'feedback', 'copy', 'active', 5 ], [ $back['scope'], $back['memory_key'], $back['name'], $back['type'], $back['topic'], $back['status'], $back['precedence'] ] );
		self::assertEquals( [ 'tone' => 'plain', 'avoid' => [ 'jargon' ] ], $back['value'] );
		self::assertSame( '2026-01-02 03:04:05', $back['created_at'] );
		$rollback = $this->rows_of_kind( 'rollback' )[0];
		self::assertSame( [ $row['change_id'], '9' ], [ $rollback['parent_id'], $rollback['resource_id'] ] );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_a_delete_from_an_admin_screen_or_a_route_is_recorded_too(): void {
		$this->seed();

		self::assertTrue( Memory::delete_by_id( 9 ) );

		$row = $this->row_of( 'memory' );
		self::assertSame( 'stonewright/memory-delete', $row['ability'], 'Outside an ability call the row names the delete itself.' );
		self::assertTrue( $row['restorable'] );
	}

	public function test_the_delete_by_scope_and_key_is_recorded_too(): void {
		$this->seed();

		Memory::delete( 'site', 'brand-voice-9' );

		self::assertSame( [], $this->memory_rows() );
		$row = $this->row_of( 'memory' );
		self::assertSame( '9', $row['resource_id'] );
		self::assertTrue( $row['restorable'] );
		Memory::delete( 'site', 'no-such-key' );
		self::assertCount( 1, $this->ledger_rows() );
	}

	public function test_deleting_a_row_that_is_not_there_records_nothing(): void {
		self::assertFalse( Memory::delete_by_id( 404 ) );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_restore_refuses_when_the_key_or_the_id_is_taken_since(): void {
		$this->seed();
		Memory::delete_by_id( 9 );
		$row = $this->row_of( 'memory' );
		$this->seed( 10, [ 'memory_key' => 'brand-voice-9' ] );
		$this->db->unique[ $this->table() ] = [ 'id' ];

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'memory_key_in_use' ], [ $restore['status'], $restore['detail'] ] );
		self::assertCount( 1, $this->memory_rows() );
		self::assertSame( [], $this->rows_of_kind( 'rollback' ) );
	}

	public function test_a_value_with_a_credential_is_masked_and_not_restorable_and_never_stored(): void {
		$this->seed( 9, [ 'value_json' => '"deploy with api_key: sk_live_abcd1234efgh5678"' ] );

		Memory::delete_by_id( 9 );

		$row = $this->row_of( 'memory' );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'sk_live_abcd1234efgh5678', $this->stored_text() );
		self::assertSame( 'failed', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_restore_needs_the_manage_options_capability(): void {
		$this->seed();
		Memory::delete_by_id( 9 );
		$row                                           = $this->row_of( 'memory' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'manage_options' !== $cap;

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( [], $this->memory_rows() );
	}

	public function test_a_ledger_that_fails_never_changes_the_delete(): void {
		$this->seed();
		$db         = new class() extends PostLedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'database gone' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$db->tables      = $this->db->tables;
		$db->unique      = $this->db->unique;
		$GLOBALS['wpdb'] = $db;

		$result = ( new MemoryDelete() )->execute( [ 'id' => 9 ] );

		self::assertSame( [ 'ok' => true, 'deleted' => true ], $result );
		self::assertSame( [], $db->tables[ $db->prefix . 'stonewright_memory' ] );
	}

	public function test_the_image_is_the_entry_without_its_id(): void {
		$this->seed();

		$image = MemoryAdapter::image( 'memory', '9' );

		self::assertSame( [ 'entry', 'v' ], array_keys( $image ) );
		self::assertArrayNotHasKey( 'id', $image['entry'] );
		self::assertNull( MemoryAdapter::image( 'memory', '404' ) );
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class FamilyTransitionTest extends TestCase {

	private const T = 1700000000;
	private const DEADLINE = 1701209600;

	/** Lineage refresh-0 -> refresh-1 -> refresh-2 -> refresh-3, consumed at T, T+100 and T+200. */
	private static function chain( array $changes = [] ): FamilyState {
		return SyntheticFamilies::initial(
			array_replace(
				[
					'current_refresh_key' => 'refresh-3',
					'revision' => 3,
					'credential_history' => [
						'refresh-0' => [ 'expires_at' => self::DEADLINE, 'consumed' => true, 'consumed_at' => self::T, 'successor_key' => 'refresh-1' ],
						'refresh-1' => [ 'expires_at' => self::DEADLINE, 'consumed' => true, 'consumed_at' => self::T + 100, 'successor_key' => 'refresh-2' ],
						'refresh-2' => [ 'expires_at' => self::DEADLINE, 'consumed' => true, 'consumed_at' => self::T + 200, 'successor_key' => 'refresh-3' ],
						'refresh-3' => [ 'expires_at' => self::DEADLINE, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ],
					],
				],
				$changes
			)
		);
	}

	public function test_rows_without_consumption_times_or_counter_read_as_unknown_and_round_trip(): void {
		$legacy = SyntheticFamilies::initial(
			[
				'current_refresh_key' => 'refresh-1',
				'revision' => 1,
				'credential_history' => [
					'refresh-0' => [ 'expires_at' => self::DEADLINE, 'consumed' => true, 'successor_key' => 'refresh-1' ],
					'refresh-1' => [ 'expires_at' => self::DEADLINE, 'consumed' => false, 'successor_key' => null ],
				],
			]
		)->to_array();
		self::assertNull( $legacy['credential_history']['refresh-0']['consumed_at'] );
		self::assertNull( $legacy['credential_history']['refresh-1']['consumed_at'] );
		self::assertSame( 0, $legacy['compacted_entries'] );
		self::assertSame( $legacy, FamilyState::from_array( $legacy )->to_array() );
	}

	/** @dataProvider invalid_consumption_times */
	public function test_consumption_time_is_validated( string $key, mixed $consumed_at ): void {
		$data = self::chain()->to_array();
		$data['credential_history'][ $key ]['consumed_at'] = $consumed_at;
		$this->expectException( \InvalidArgumentException::class );
		FamilyState::from_array( $data );
	}

	public function invalid_consumption_times(): array {
		return [
			'unconsumed entry with a time' => [ 'refresh-3', self::T + 300 ],
			'zero' => [ 'refresh-0', 0 ],
			'negative' => [ 'refresh-0', -1 ],
			'after the family deadline' => [ 'refresh-0', self::DEADLINE + 1 ],
			'numeric string' => [ 'refresh-0', (string) self::T ],
			'float' => [ 'refresh-0', (float) self::T ],
		];
	}

	public function test_consumption_time_may_equal_the_family_deadline_or_stay_unknown(): void {
		$data = self::chain()->to_array();
		$data['credential_history']['refresh-0']['consumed_at'] = self::DEADLINE;
		$data['credential_history']['refresh-1']['consumed_at'] = null;
		$history = FamilyState::from_array( $data )->to_array()['credential_history'];
		self::assertSame( self::DEADLINE, $history['refresh-0']['consumed_at'] );
		self::assertNull( $history['refresh-1']['consumed_at'] );
	}

	public function test_negative_compaction_counter_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		self::chain( [ 'compacted_entries' => -1 ] );
	}

	public function test_a_new_consumption_must_record_its_time(): void {
		$history = [
			'refresh-0' => [ 'expires_at' => self::DEADLINE, 'consumed' => true, 'consumed_at' => null, 'successor_key' => 'refresh-1' ],
			'refresh-1' => [ 'expires_at' => self::DEADLINE, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ],
		];
		$this->expectException( \InvalidArgumentException::class );
		SyntheticFamilies::initial()->advance( [ 'credential_history' => $history, 'current_refresh_key' => 'refresh-1', 'revision' => 1 ] );
	}

	public function test_recorded_consumption_time_cannot_be_rewritten(): void {
		$data = self::chain()->to_array();
		$data['credential_history']['refresh-0']['consumed_at'] = self::T + 1;
		$this->expectException( \InvalidArgumentException::class );
		self::chain()->advance( [ 'credential_history' => $data['credential_history'], 'revision' => 4 ] );
	}

	public function test_compaction_counter_cannot_change_through_an_ordinary_transition(): void {
		$this->expectException( \InvalidArgumentException::class );
		SyntheticFamilies::initial()->advance( [ 'compacted_entries' => 3, 'revision' => 1 ] );
	}

	public function test_revocation_closes_an_active_family_once(): void {
		$revoked = self::chain()->revoke();
		self::assertSame( 'revoked', $revoked->to_array()['phase'] );
		self::assertSame( 4, $revoked->to_array()['revision'] );
		self::assertSame( self::chain()->to_array()['credential_history'], $revoked->to_array()['credential_history'] );
		self::assertSame( $revoked->to_array(), $revoked->revoke()->to_array() );
	}

	public function test_an_expired_family_stays_expired_when_revoked(): void {
		$expired = self::chain()->advance( [ 'phase' => 'expired', 'revision' => 4 ] );
		self::assertSame( $expired->to_array(), $expired->revoke()->to_array() );
	}

	public function test_compaction_drops_old_ancestors_but_keeps_the_head_and_its_predecessor(): void {
		$compacted = self::chain()->compact( self::T + 1000, 60 )->to_array();
		self::assertSame( [ 'refresh-2', 'refresh-3' ], array_keys( $compacted['credential_history'] ) );
		self::assertSame( self::chain()->to_array()['credential_history']['refresh-2'], $compacted['credential_history']['refresh-2'] );
		self::assertSame( 2, $compacted['compacted_entries'] );
		self::assertSame( 4, $compacted['revision'] );
		self::assertSame( 'refresh-3', $compacted['current_refresh_key'] );
		self::assertSame( $compacted, FamilyState::from_array( $compacted )->to_array() );
	}

	public function test_compaction_stops_at_the_first_ancestor_consumed_inside_the_window(): void {
		$compacted = self::chain()->compact( self::T + 130, 60 )->to_array();
		self::assertSame( [ 'refresh-1', 'refresh-2', 'refresh-3' ], array_keys( $compacted['credential_history'] ) );
		self::assertSame( 1, $compacted['compacted_entries'] );
	}

	public function test_compaction_counts_unknown_consumption_times_as_old(): void {
		$data = self::chain()->to_array();
		$data['credential_history']['refresh-0']['consumed_at'] = null;
		$compacted = FamilyState::from_array( $data )->compact( self::T + 10, 60 )->to_array();
		self::assertSame( [ 'refresh-1', 'refresh-2', 'refresh-3' ], array_keys( $compacted['credential_history'] ) );
	}

	public function test_compaction_without_eligible_entries_changes_nothing(): void {
		$family = self::chain();
		self::assertSame( $family->to_array(), $family->compact( self::T + 10, 60 )->to_array() );
		$young = SyntheticFamilies::initial();
		self::assertSame( $young->to_array(), $young->compact( self::T + 86400, 0 )->to_array() );
		$this->expectException( \InvalidArgumentException::class );
		$family->compact( self::T + 1000, -1 );
	}

	public function test_only_the_unconsumed_unexpired_head_of_an_active_family_is_live(): void {
		$family = self::chain();
		self::assertTrue( $family->credential_active( 'refresh-3', self::T + 300 ) );
		self::assertFalse( $family->credential_active( 'refresh-2', self::T + 300 ) );
		self::assertFalse( $family->credential_active( 'refresh-missing', self::T + 300 ) );
		self::assertFalse( $family->credential_active( 'refresh-3', self::DEADLINE ) );
		self::assertFalse( $family->revoke()->credential_active( 'refresh-3', self::T + 300 ) );
		$data = $family->to_array();
		$data['credential_history']['refresh-3']['expires_at'] = self::T + 500;
		$idle = FamilyState::from_array( $data );
		self::assertTrue( $idle->credential_active( 'refresh-3', self::T + 499 ) );
		self::assertFalse( $idle->credential_active( 'refresh-3', self::T + 500 ) );
	}
}

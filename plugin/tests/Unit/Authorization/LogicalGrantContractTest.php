<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class LogicalGrantContractTest extends TestCase {

	public function test_serialization_preserves_history_and_the_original_deadline(): void {
		$family = SyntheticFamilies::initial();
		self::assertSame( $family->to_array(), FamilyState::from_array( $family->to_array() )->to_array() );
		$this->expectException( \InvalidArgumentException::class );
		$family->advance( [ 'family_deadline' => 1701209601, 'revision' => 1 ] );
	}

	public function test_closed_family_cannot_reopen(): void {
		$closed = SyntheticFamilies::initial()->advance( [ 'phase' => 'revoked', 'revision' => 1 ] );
		$this->expectException( \InvalidArgumentException::class );
		$closed->advance( [ 'phase' => 'active', 'revision' => 2 ] );
	}

	public function test_two_live_successors_are_rejected_even_after_round_trip(): void {
		$data = SyntheticFamilies::initial()->to_array();
		$data['credential_history']['refresh-other'] = [ 'expires_at' => 1701209600, 'consumed' => false, 'successor_key' => null ];
		$this->expectException( \InvalidArgumentException::class );
		FamilyState::from_array( $data );
	}

	/** @dataProvider corrupt_histories */
	public function test_consumed_history_must_be_one_chain_ending_at_current_credential( array $history ): void {
		$data = SyntheticFamilies::initial()->to_array();
		$data['credential_history'] = $history;
		$this->expectException( \InvalidArgumentException::class );
		FamilyState::from_array( $data );
	}

	/** @dataProvider rejected_advances */
	public function test_advance_cannot_rewrite_consumed_history_or_fork_the_lineage( string $phase, array $changes ): void {
		$family = self::rotated( $phase );
		$this->expectException( \InvalidArgumentException::class );
		$family->advance( $changes + [ 'revision' => 2 ] );
	}

	public function rejected_advances(): array {
		$fresh = static fn ( ?string $next = null ): array => [ 'expires_at' => 1701209600, 'consumed' => false, 'successor_key' => $next ];
		$used = static fn ( ?string $next ): array => [ 'expires_at' => 1701209600, 'consumed' => true, 'successor_key' => $next ];
		$timed = static fn ( ?string $next ): array => [ 'expires_at' => 1701209600, 'consumed' => true, 'consumed_at' => 1700000000, 'successor_key' => $next ];
		return [
			'un-consume the parent' => [ 'active', [ 'credential_history' => [ 'refresh-0' => $fresh(), 'refresh-1' => $fresh() ] ] ],
			'un-consume but keep the successor link' => [ 'active', [ 'credential_history' => [ 'refresh-0' => $fresh( 'refresh-1' ), 'refresh-1' => $fresh() ] ] ],
			're-head to a consumed key' => [ 'active', [ 'current_refresh_key' => 'refresh-0' ] ],
			'two added entries' => [ 'active', [ 'current_refresh_key' => 'refresh-3', 'credential_history' => [ 'refresh-0' => $used( 'refresh-1' ), 'refresh-1' => $timed( 'refresh-2' ), 'refresh-2' => $timed( 'refresh-3' ), 'refresh-3' => $fresh() ] ] ],
			'successor plus a prepended ancestor' => [ 'active', [ 'current_refresh_key' => 'refresh-2', 'credential_history' => [ 'refresh-prior' => $timed( 'refresh-0' ), 'refresh-0' => $used( 'refresh-1' ), 'refresh-1' => $timed( 'refresh-2' ), 'refresh-2' => $fresh() ] ] ],
			'self successor' => [ 'active', [ 'current_refresh_key' => 'refresh-2', 'credential_history' => [ 'refresh-0' => $used( 'refresh-1' ), 'refresh-1' => $used( 'refresh-1' ), 'refresh-2' => $fresh() ] ] ],
			'unknown successor' => [ 'active', [ 'current_refresh_key' => 'refresh-2', 'credential_history' => [ 'refresh-0' => $used( 'refresh-1' ), 'refresh-1' => $used( 'refresh-missing' ), 'refresh-2' => $fresh() ] ] ],
			'chain end is not the current key' => [ 'revoked', [ 'current_refresh_key' => 'refresh-0' ] ],
			'consumed entry dropped' => [ 'active', [ 'credential_history' => [ 'refresh-1' => $fresh() ] ] ],
			'credential expiry rewritten' => [ 'active', [ 'credential_history' => [ 'refresh-0' => $used( 'refresh-1' ), 'refresh-1' => [ 'expires_at' => 1701209599, 'consumed' => false, 'successor_key' => null ] ] ] ],
		];
	}

	private static function rotated( string $phase ): FamilyState {
		return SyntheticFamilies::initial(
			[
				'phase' => $phase,
				'current_refresh_key' => 'refresh-1',
				'revision' => 1,
				'credential_history' => [
					'refresh-0' => [ 'expires_at' => 1701209600, 'consumed' => true, 'successor_key' => 'refresh-1' ],
					'refresh-1' => [ 'expires_at' => 1701209600, 'consumed' => false, 'successor_key' => null ],
				],
			]
		);
	}

	public function corrupt_histories(): array {
		$fresh = [ 'expires_at' => 1701209600, 'consumed' => false, 'successor_key' => null ];
		$consumed = static fn( ?string $next ): array => [ 'expires_at' => 1701209600, 'consumed' => true, 'successor_key' => $next ];
		return [
			[ [ 'refresh-0' => $fresh, 'old-a' => $consumed( 'old-b' ), 'old-b' => $consumed( 'old-a' ) ] ],
			[ [ 'refresh-0' => $fresh, 'old-a' => $consumed( 'refresh-0' ), 'old-b' => $consumed( 'refresh-0' ) ] ],
			[ [ 'refresh-0' => $fresh, 'old-a' => $consumed( null ) ] ],
		];
	}
}

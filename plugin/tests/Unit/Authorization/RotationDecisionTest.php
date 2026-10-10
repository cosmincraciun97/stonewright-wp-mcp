<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class RotationDecisionTest extends TestCase {

	private const T = 1700000000;
	private const DAY = 86400;

	/** Strict single-use rotation: the earlier baseline expectations under a zero window. */
	private static function strict(): RotationDecision {
		return new RotationDecision( new RefreshPolicy( 0 ) );
	}

	private static function continuity(): RotationDecision {
		return new RotationDecision( new RefreshPolicy() );
	}

	private static function demand( string $refresh, ?array $scopes = null, array $resources = [ 'https://example.test/mcp' ] ): RotationDemand {
		return new RotationDemand( 'family-a', $refresh, 'client-a', $resources, $scopes );
	}

	/** A family started at T under the default lifetimes. */
	private static function issued(): FamilyState {
		return SyntheticFamilies::initial(
			[
				'family_deadline' => self::T + 90 * self::DAY,
				'credential_history' => [ 'refresh-0' => [ 'expires_at' => self::T + 30 * self::DAY, 'consumed' => false, 'successor_key' => null ] ],
			]
		);
	}

	/** Rotation of refresh-0 to refresh-1 at T under the default window. */
	private static function rotated(): FamilyState {
		return self::continuity()->decide( self::issued(), self::demand( 'refresh-0' ), self::T, 'access-1', 'refresh-1' )->state;
	}

	public function test_rotation_preserves_deadline_and_original_consent_while_reducing_access_scope(): void {
		$demand = new RotationDemand( 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], [ 'read' ] );
		$outcome = self::strict()->decide( SyntheticFamilies::initial(), $demand, 1700000000, 'access-1', 'refresh-1' );
		self::assertNull( $outcome->fault );
		self::assertSame( 1700003600, $outcome->issuance->access_deadline );
		self::assertSame( 1701209600, $outcome->issuance->refresh_deadline );
		self::assertSame( [ 'read' ], $outcome->issuance->access_scopes );
		self::assertSame( [ 'mcp', 'read' ], $outcome->state->to_array()['consented_scopes'] );
		self::assertTrue( $outcome->state->to_array()['credential_history']['refresh-0']['consumed'] );
		self::assertSame( 'refresh-1', $outcome->state->to_array()['current_refresh_key'] );
	}

	public function test_replayed_parent_revokes_without_creating_a_second_successor(): void {
		$demand = new RotationDemand( 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], null );
		$decision = self::strict();
		$first = $decision->decide( SyntheticFamilies::initial(), $demand, 1700000000, 'access-1', 'refresh-1' );
		$replay = $decision->decide( $first->state, $demand, 1700000001, 'access-2', 'refresh-2' );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertNull( $replay->issuance );
		self::assertSame( 'revoked', $replay->state->to_array()['phase'] );
		self::assertArrayNotHasKey( 'refresh-2', $replay->state->to_array()['credential_history'] );
	}

	/** @dataProvider invalid_bindings */
	public function test_foreign_or_unknown_use_cannot_revoke_the_owners_family( string $family, string $refresh, string $client, array $resources, ?array $scopes ): void {
		$initial = SyntheticFamilies::initial();
		$outcome = self::strict()->decide( $initial, new RotationDemand( $family, $refresh, $client, $resources, $scopes ), 1700000000, 'access-1', 'refresh-1' );
		self::assertNotNull( $outcome->fault );
		self::assertNull( $outcome->issuance );
		self::assertSame( $initial->to_array(), $outcome->state->to_array() );
	}

	public function invalid_bindings(): array {
		return [ [ 'other', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], null ], [ 'family-a', 'unknown', 'client-a', [ 'https://example.test/mcp' ], null ], [ 'family-a', 'refresh-0', 'other', [ 'https://example.test/mcp' ], null ], [ 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/other' ], null ], [ 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], [ 'write' ] ] ];
	}

	public function test_identifier_failure_is_a_server_error_with_http_500_and_no_transition(): void {
		$initial = SyntheticFamilies::initial();
		$outcome = self::strict()->decide( $initial, new RotationDemand( 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], null ), 1700000000, 'access-1', 'refresh-0' );
		self::assertSame( 'server_error', $outcome->fault->error() );
		self::assertSame( 500, $outcome->fault->status() );
		self::assertSame( $initial->to_array(), $outcome->state->to_array() );
	}

	public function test_empty_requested_scope_list_is_treated_as_omitted(): void {
		$outcome = self::strict()->decide( SyntheticFamilies::initial(), new RotationDemand( 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], [] ), 1700000000, 'access-1', 'refresh-1' );
		self::assertNull( $outcome->fault );
		self::assertSame( [ 'mcp', 'read' ], $outcome->issuance->access_scopes );
	}

	public function test_expiry_is_closed_at_the_effect_boundary_and_stays_closed_after_clock_regression(): void {
		$demand = new RotationDemand( 'family-a', 'refresh-0', 'client-a', [ 'https://example.test/mcp' ], null );
		$decision = self::strict();
		$expired = $decision->decide( SyntheticFamilies::initial(), $demand, 1701209600, 'access-1', 'refresh-1' );
		self::assertSame( 'expired', $expired->state->to_array()['phase'] );
		self::assertNull( $expired->issuance );
		$earlier = $decision->decide( $expired->state, $demand, 1700000000, 'access-2', 'refresh-2' );
		self::assertNull( $earlier->issuance );
		self::assertSame( 'expired', $earlier->state->to_array()['phase'] );
	}

	public function test_rotation_records_its_time_and_issues_policy_lifetimes(): void {
		$now = self::T + 10 * self::DAY;
		$outcome = self::continuity()->decide( self::issued(), self::demand( 'refresh-0' ), $now, 'access-1', 'refresh-1' );
		$history = $outcome->state->to_array()['credential_history'];
		self::assertSame( $now, $history['refresh-0']['consumed_at'] );
		self::assertSame( [ 'expires_at' => $now + 30 * self::DAY, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ], $history['refresh-1'] );
		self::assertSame( $now + 3600, $outcome->issuance->access_deadline );
		self::assertSame( $now + 30 * self::DAY, $outcome->issuance->refresh_deadline );
		self::assertSame( 'refresh-1', $outcome->issuance->refresh_key );
		self::assertSame( 1, $outcome->issuance->revision );
		self::assertFalse( $outcome->issuance->redelivery );
		self::assertSame( self::T + 90 * self::DAY, $outcome->state->to_array()['family_deadline'] );
	}

	public function test_refresh_near_the_family_deadline_expires_with_the_family(): void {
		$deadline = self::T + 90 * self::DAY;
		$late = SyntheticFamilies::initial( [ 'family_deadline' => $deadline, 'credential_history' => [ 'refresh-0' => [ 'expires_at' => $deadline, 'consumed' => false, 'successor_key' => null ] ] ] );
		$outcome = self::continuity()->decide( $late, self::demand( 'refresh-0' ), self::T + 89 * self::DAY, 'access-1', 'refresh-1' );
		self::assertSame( $deadline, $outcome->issuance->refresh_deadline );
		self::assertSame( $deadline, $outcome->state->to_array()['credential_history']['refresh-1']['expires_at'] );
		self::assertSame( self::T + 89 * self::DAY + 3600, $outcome->issuance->access_deadline );
	}

	public function test_immediate_predecessor_inside_the_window_redelivers_the_head_without_a_state_change(): void {
		$rotated = self::rotated();
		$outcome = self::continuity()->decide( $rotated, self::demand( 'refresh-0', [ 'read' ] ), self::T + 10, 'access-2', 'refresh-2' );
		self::assertNull( $outcome->fault );
		self::assertSame( $rotated->to_array(), $outcome->state->to_array() );
		self::assertTrue( $outcome->issuance->redelivery );
		self::assertSame( 'refresh-1', $outcome->issuance->refresh_key );
		self::assertSame( 'access-2', $outcome->issuance->access_key );
		self::assertSame( $rotated->to_array()['credential_history']['refresh-1']['expires_at'], $outcome->issuance->refresh_deadline );
		self::assertSame( self::T + 10 + 3600, $outcome->issuance->access_deadline );
		self::assertSame( [ 'read' ], $outcome->issuance->access_scopes );
		self::assertSame( 1, $outcome->issuance->revision );
	}

	/** @dataProvider window_edges */
	public function test_window_edge_and_backwards_clock( int $now, bool $duplicate ): void {
		$outcome = self::continuity()->decide( self::rotated(), self::demand( 'refresh-0' ), $now, 'access-2', 'refresh-2' );
		self::assertSame( $duplicate, null !== $outcome->issuance );
		self::assertSame( $duplicate ? 'active' : 'revoked', $outcome->state->to_array()['phase'] );
	}

	public function window_edges(): array {
		return [ 'last second inside' => [ self::T + 59, true ], 'exactly at the end' => [ self::T + 60, false ], 'after the end' => [ self::T + 61, false ], 'clock moved backwards' => [ self::T - 3600, true ] ];
	}

	public function test_zero_window_makes_every_non_head_presentation_a_replay(): void {
		$rotated = self::strict()->decide( self::issued(), self::demand( 'refresh-0' ), self::T, 'access-1', 'refresh-1' )->state;
		$outcome = self::strict()->decide( $rotated, self::demand( 'refresh-0' ), self::T, 'access-2', 'refresh-2' );
		self::assertNull( $outcome->issuance );
		self::assertSame( 'revoked', $outcome->state->to_array()['phase'] );
	}

	/** @dataProvider out_of_consent_replays */
	public function test_replay_is_classified_before_narrowing_and_still_revokes( ?array $scopes, array $resources ): void {
		$outcome = self::continuity()->decide( self::rotated(), self::demand( 'refresh-0', $scopes, $resources ), self::T + 61, 'access-2', 'refresh-2' );
		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( 'revoked', $outcome->state->to_array()['phase'] );
		self::assertSame( 2, $outcome->state->to_array()['revision'] );
	}

	public function out_of_consent_replays(): array {
		return [ 'scope outside consent' => [ [ 'write' ], [ 'https://example.test/mcp' ] ], 'resource outside consent' => [ null, [ 'https://example.test/other' ] ] ];
	}

	/** @dataProvider rejected_duplicate_narrowing */
	public function test_duplicate_narrowing_fails_without_a_state_change( ?array $scopes, array $resources, string $error ): void {
		$rotated = self::rotated();
		$outcome = self::continuity()->decide( $rotated, self::demand( 'refresh-0', $scopes, $resources ), self::T + 10, 'access-2', 'refresh-2' );
		self::assertSame( $error, $outcome->fault->error() );
		self::assertNull( $outcome->issuance );
		self::assertSame( $rotated->to_array(), $outcome->state->to_array() );
	}

	public function rejected_duplicate_narrowing(): array {
		return [ 'scope outside consent' => [ [ 'read', 'write' ], [ 'https://example.test/mcp' ], 'invalid_scope' ], 'resource outside consent' => [ null, [ 'https://example.test/other' ], 'invalid_target' ] ];
	}

	public function test_predecessor_with_unknown_consumption_time_is_a_replay(): void {
		$data = self::rotated()->to_array();
		$data['credential_history']['refresh-0']['consumed_at'] = null;
		$outcome = self::continuity()->decide( FamilyState::from_array( $data ), self::demand( 'refresh-0' ), self::T + 1, 'access-2', 'refresh-2' );
		self::assertNull( $outcome->issuance );
		self::assertSame( 'revoked', $outcome->state->to_array()['phase'] );
	}

	public function test_older_ancestor_inside_its_window_is_a_replay(): void {
		$twice = self::continuity()->decide( self::rotated(), self::demand( 'refresh-1' ), self::T + 5, 'access-2', 'refresh-2' )->state;
		$outcome = self::continuity()->decide( $twice, self::demand( 'refresh-0' ), self::T + 10, 'access-3', 'refresh-3' );
		self::assertNull( $outcome->issuance );
		self::assertSame( 'revoked', $outcome->state->to_array()['phase'] );
		self::assertFalse( $outcome->state->to_array()['credential_history']['refresh-2']['consumed'] );
	}

	public function test_idle_expired_head_refuses_a_duplicate_without_revocation(): void {
		$data = self::rotated()->to_array();
		$data['credential_history']['refresh-1']['expires_at'] = self::T + 10;
		$family = FamilyState::from_array( $data );
		$outcome = self::continuity()->decide( $family, self::demand( 'refresh-0' ), self::T + 20, 'access-2', 'refresh-2' );
		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( $family->to_array(), $outcome->state->to_array() );
	}

	public function test_family_deadline_inside_the_window_expires_the_family(): void {
		$short = SyntheticFamilies::initial( [ 'family_deadline' => self::T + 30, 'credential_history' => [ 'refresh-0' => [ 'expires_at' => self::T + 30, 'consumed' => false, 'successor_key' => null ] ] ] );
		$rotated = self::continuity()->decide( $short, self::demand( 'refresh-0' ), self::T, 'access-1', 'refresh-1' )->state;
		$outcome = self::continuity()->decide( $rotated, self::demand( 'refresh-0' ), self::T + 30, 'access-2', 'refresh-2' );
		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( 'expired', $outcome->state->to_array()['phase'] );
	}

	public function test_credential_missing_after_compaction_is_a_replay_but_unknown_otherwise(): void {
		$rotated = self::rotated();
		$twice = self::continuity()->decide( $rotated, self::demand( 'refresh-1' ), self::T + 5, 'access-2', 'refresh-2' )->state;
		$compacted = $twice->compact( self::T + 1000, 60 );
		self::assertArrayNotHasKey( 'refresh-0', $compacted->to_array()['credential_history'] );
		$outcome = self::continuity()->decide( $compacted, self::demand( 'refresh-0' ), self::T + 1001, 'access-3', 'refresh-3' );
		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( 'revoked', $outcome->state->to_array()['phase'] );
		$unknown = self::continuity()->decide( $rotated, self::demand( 'refresh-never-issued' ), self::T + 1, 'access-3', 'refresh-3' );
		self::assertSame( $rotated->to_array(), $unknown->state->to_array() );
	}

	public function test_duplicate_identifier_failure_is_a_server_error_without_a_state_change(): void {
		$rotated = self::rotated();
		$outcome = self::continuity()->decide( $rotated, self::demand( 'refresh-0' ), self::T + 1, 'refresh-1', 'refresh-2' );
		self::assertSame( 'server_error', $outcome->fault->error() );
		self::assertSame( 500, $outcome->fault->status() );
		self::assertSame( $rotated->to_array(), $outcome->state->to_array() );
	}
}

<?php
/**
 * Scheduled OAuth clean-up.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Support\Logger;

/**
 * Daily clean-up on the stonewright_oauth_gc hook (the name the earlier version
 * scheduled, so its events find this handler). It removes:
 * - expired codes, keeping used codes until expiry plus USED_CODE_RETENTION so a late
 *   replay still revokes the family it created;
 * - expired pending consents and expired access rows;
 * - every row of families whose deadline passed more than FAMILY_MARGIN ago;
 * - dynamically registered clients unused for 30 days without a live family;
 * - rate-limit rows older than RATE_LIMIT_RETENTION;
 * and compacts consumed refresh history, keeping the current credential and its
 * immediate predecessor. Each step runs on its own; a failing step is logged.
 */
final class Housekeeping {

	public const HOOK = 'stonewright_oauth_gc';
	public const FAMILY_MARGIN = 86400;
	public const RATE_LIMIT_RETENTION = 172800;

	private const COMPACTION_SCAN = 5000;

	public function __construct( private Database $db, private FamilyStore $families, private ClientStore $clients, private Clock $clock, private int $duplicate_window ) {}

	/** @return array<string, int> Rows removed (or families compacted) per category. */
	public function run(): array {
		$now = $this->clock->now();
		$at = Database::datetime( $now );
		$family_cutoff = Database::datetime( $now - self::FAMILY_MARGIN );
		$rate_cutoff = Database::datetime( $now - self::RATE_LIMIT_RETENTION );
		return [
			'codes'          => $this->step( 'codes', fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'auth_codes' ) . ' WHERE expires_at < %s AND (revoked = 0 OR expires_at < %s)', [ $at, Database::datetime( $now - CodeGrantState::USED_CODE_RETENTION ) ] ) ),
			'consents'       => $this->step( 'consents', fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'consents' ) . ' WHERE expires_at <= %s', [ $at ] ) ),
			'access_tokens'  => $this->step( 'access_tokens', fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'access_tokens' ) . ' WHERE expires_at <= %s', [ $at ] ) ),
			'refresh_tokens' => $this->step( 'refresh_tokens', fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE family_expires_at < %s OR (family_expires_at IS NULL AND expires_at < %s)', [ $family_cutoff, $family_cutoff ] ) ),
			'families'       => $this->step( 'families', fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'families' ) . ' WHERE family_expires_at < %s', [ $family_cutoff ] ) ),
			'clients'        => $this->step( 'clients', fn (): int => $this->clients->prune( $now ) ),
			'rate_limits'    => $this->step(
				'rate_limits',
				fn (): int => $this->db->execute( 'DELETE FROM ' . $this->db->table( 'rate_limits' ) . ' WHERE updated_at < %s', [ $rate_cutoff ] )
					+ $this->db->execute( 'DELETE FROM ' . $this->db->table( 'rate_metrics' ) . ' WHERE updated_at < %s', [ $rate_cutoff ] )
			),
			'compacted'      => $this->step( 'compacted', fn (): int => $this->compact( $now ) ),
		];
	}

	/** Compact families with at least two consumed credentials older than the duplicate window. */
	private function compact( int $now ): int {
		$hashes = $this->db->column( 'SELECT grant_family_hash FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE consumed_at IS NOT NULL AND consumed_at <= %s LIMIT ' . self::COMPACTION_SCAN, [ Database::datetime( $now - $this->duplicate_window ) ] );
		$compacted = 0;
		foreach ( array_count_values( $hashes ) as $hash => $consumed ) {
			if ( $consumed < 2 ) {
				continue;
			}
			try {
				if ( $this->families->compact( (string) $hash, $this->duplicate_window ) ) {
					++$compacted;
				}
			} catch ( OAuthFault | StorageFailure $skipped ) {
				continue;
			}
		}
		return $compacted;
	}

	/** @param \Closure(): int $work */
	private function step( string $name, \Closure $work ): int {
		try {
			return $work();
		} catch ( StorageFailure $failure ) {
			Logger::warning( 'oauth_cleanup_step_failed', [ 'step' => $name ] );
			return 0;
		}
	}
}

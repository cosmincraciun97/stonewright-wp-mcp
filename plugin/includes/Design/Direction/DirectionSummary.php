<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

/**
 * Compact projections of stored direction records.
 *
 * MCP responses and the admin list both need the same small answer: what a
 * direction is called, where it stands, and which contract it currently holds.
 * Full contracts are large, so they are returned only when a caller asks for a
 * single direction. Keeping the projection here means list responses, history
 * responses, and write receipts cannot drift apart.
 */
final class DirectionSummary {

	/**
	 * A single list row.
	 *
	 * @param array<string,mixed> $record    Stored direction record.
	 * @param int                 $active_id Id held by the active-direction option.
	 * @return array<string,mixed>
	 */
	public static function row( array $record, int $active_id ): array {
		$contract  = self::sub_array( $record, 'contract' );
		$identity  = self::sub_array( $contract, 'identity' );
		$readiness = self::sub_array( $contract, 'readiness' );
		$issues    = self::sub_array( $readiness, 'issues' );
		$id        = (int) ( $record['id'] ?? 0 );

		return [
			'id'            => $id,
			'slug'          => self::text( $record, 'slug' ),
			'name'          => self::text( $identity, 'name' ),
			'status'        => self::text( $record, 'status' ),
			'revision'      => (int) ( $record['revision'] ?? 0 ),
			'contract_hash' => self::text( $record, 'contract_hash' ),
			'source_type'   => self::text( $record, 'source_type' ),
			'ready'         => true === ( $readiness['ready'] ?? false ),
			'sync_ready'    => true === ( $readiness['sync_ready'] ?? false ),
			'issue_count'   => count( $issues ),
			'updated_at'    => self::text( $record, 'updated_at' ),
			'active'        => $id > 0 && $id === $active_id,
		];
	}

	/**
	 * Version history without the stored contracts.
	 *
	 * The hash is enough to tell revisions apart and to prove which contract a
	 * revision holds, so history stays cheap to return.
	 *
	 * @param list<array<string,mixed>> $versions Version rows, newest first.
	 * @return list<array<string,mixed>>
	 */
	public static function history( array $versions ): array {
		$rows = [];

		foreach ( $versions as $version ) {
			$rows[] = [
				'revision'      => (int) ( $version['revision'] ?? 0 ),
				'status'        => self::text( $version, 'status' ),
				'contract_hash' => self::text( $version, 'contract_hash' ),
				'source_type'   => self::text( $version, 'source_type' ),
				'created_at'    => self::text( $version, 'created_at' ),
			];
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $source Parent array.
	 * @param string              $key    Key holding a nested array.
	 * @return array<string,mixed>
	 */
	private static function sub_array( array $source, string $key ): array {
		$value = $source[ $key ] ?? null;

		return is_array( $value ) ? $value : [];
	}

	/**
	 * @param array<string,mixed> $source Parent array.
	 * @param string              $key    Key holding a scalar.
	 */
	private static function text( array $source, string $key ): string {
		$value = $source[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

use Stonewright\WpMcp\Support\Json;

/** Bundled immutable packs plus site-local versioned overrides and scorecards. */
final class ExpertiseStore {
	/** @var list<array<string, mixed>>|null */
	private static ?array $cache = null;

	/** @return list<array<string, mixed>> */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$packs = [];
		foreach ( BundledPacks::all() as $pack ) {
			$packs[ (string) $pack['id'] ] = $pack;
		}
		foreach ( self::site_overrides() as $override ) {
			if ( PackValidator::is_valid( $override ) ) {
				$packs[ (string) $override['id'] ] = $override;
			}
		}
		self::$cache = array_values( $packs );
		return self::$cache;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		$id = sanitize_key( $id );
		foreach ( self::all() as $pack ) {
			if ( $id === (string) $pack['id'] ) {
				return $pack;
			}
		}
		return null;
	}

	/** @param array<string, mixed> $pack */
	public static function save_override( array $pack ): bool {
		global $wpdb;
		if ( ! PackValidator::is_valid( $pack ) ) {
			return false;
		}
		$pack['hash'] = BundledPacks::hash( $pack );
		$row = [
			'pack_id'      => sanitize_key( (string) $pack['id'] ),
			'pack_version' => sanitize_text_field( (string) $pack['version'] ),
			'status'       => sanitize_key( (string) $pack['status'] ),
			'pack_hash'    => (string) $pack['hash'],
			'pack_json'    => Json::encode( $pack ),
			'source'       => 'site',
			'created_by'   => get_current_user_id(),
		];
		$table = ExpertiseTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE pack_id = %s", $row['pack_id'] ) );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$saved = false !== $wpdb->update( $table, $row, [ 'id' => (int) $existing ] );
			self::$cache = null;
			return $saved;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$saved = false !== $wpdb->insert( $table, $row );
		self::$cache = null;
		return $saved;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/** @param array<string, mixed> $scorecard */
	public static function record_scorecard( array $scorecard ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			ExpertiseTable::scorecard_table_name(),
			[
				'pack_id'             => sanitize_key( (string) ( $scorecard['pack_id'] ?? '' ) ),
				'pack_hash'           => (string) ( $scorecard['pack_hash'] ?? '' ),
				'runtime_fingerprint' => (string) ( $scorecard['runtime_fingerprint'] ?? '' ),
				'score'               => (float) ( $scorecard['score'] ?? 0 ),
				'critical_failures'   => (int) ( $scorecard['critical_failures'] ?? 0 ),
				'metrics_json'        => Json::encode( $scorecard ),
				'created_by'          => get_current_user_id(),
			]
		);
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/** @return list<array<string, mixed>> */
	public static function scorecards( string $pack_id ): array {
		global $wpdb;
		$table = ExpertiseTable::scorecard_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT metrics_json FROM {$table} WHERE pack_id = %s ORDER BY id DESC LIMIT 50", sanitize_key( $pack_id ) ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			try {
				$value = json_decode( (string) $row['metrics_json'], true, 512, JSON_THROW_ON_ERROR );
				if ( is_array( $value ) ) {
					$out[] = $value;
				}
			} catch ( \JsonException ) {
				continue;
			}
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private static function site_overrides(): array {
		global $wpdb;
		$table = ExpertiseTable::table_name();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT pack_json FROM {$table} ORDER BY pack_id ASC", ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			try {
				$pack = json_decode( (string) $row['pack_json'], true, 512, JSON_THROW_ON_ERROR );
				if ( is_array( $pack ) ) {
					$out[] = $pack;
				}
			} catch ( \JsonException ) {
				continue;
			}
		}
		return $out;
	}
}

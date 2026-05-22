<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Json;

/**
 * Creates revisions and post-meta snapshots before write abilities mutate state.
 */
final class Backup {

	private const META_KEY = '_stonewright_backups';

	public static function snapshot_post( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$snapshot_id = self::new_snapshot_id();
		$payload     = [
			'snapshot_id'  => $snapshot_id,
			'created_at'   => time(),
			'post_title'   => $post->post_title,
			'post_status'  => $post->post_status,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'meta'         => self::collect_meta( $post_id ),
		];

		$snapshots                 = get_post_meta( $post_id, self::META_KEY, true );
		$snapshots                 = is_array( $snapshots ) ? $snapshots : [];
		$snapshots[ $snapshot_id ] = $payload;
		$snapshots                 = self::trim( $snapshots );
		update_post_meta( $post_id, self::META_KEY, $snapshots );

		if ( post_type_supports( $post->post_type, 'revisions' ) ) {
			wp_save_post_revision( $post_id );
		}

		return $snapshot_id;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_snapshots( int $post_id ): array {
		$snapshots = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $snapshots ) ? $snapshots : [];
	}

	public static function get_snapshot( int $post_id, string $snapshot_id ): ?array {
		$snapshots = self::list_snapshots( $post_id );
		return $snapshots[ $snapshot_id ] ?? null;
	}

	public static function restore( int $post_id, string $snapshot_id ): bool {
		$snapshot = self::get_snapshot( $post_id, $snapshot_id );
		if ( ! $snapshot ) {
			return false;
		}

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => $snapshot['post_title'],
				'post_status'  => $snapshot['post_status'],
				'post_content' => $snapshot['post_content'],
				'post_excerpt' => $snapshot['post_excerpt'],
			]
		);

		foreach ( $snapshot['meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return true;
	}

	private static function new_snapshot_id(): string {
		return 'snap_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function collect_meta( int $post_id ): array {
		$keys = [ '_elementor_data', '_elementor_page_settings', '_elementor_version', '_elementor_edit_mode', '_wp_page_template' ];
		$out  = [];
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, array<string, mixed>> $snapshots
	 * @return array<string, array<string, mixed>>
	 */
	private static function trim( array $snapshots ): array {
		$limit = (int) apply_filters( 'stonewright_backup_history_limit', 10 );
		if ( count( $snapshots ) <= $limit ) {
			return $snapshots;
		}
		return array_slice( $snapshots, -$limit, null, true );
	}
}

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
		$payload['meta_absent'] = array_values( array_diff( self::tracked_meta_keys(), array_keys( $payload['meta'] ) ) );

		$snapshots                 = get_post_meta( $post_id, self::META_KEY, true );
		$snapshots                 = is_array( $snapshots ) ? $snapshots : [];
		$snapshots[ $snapshot_id ] = $payload;
		$snapshots                 = self::trim( $snapshots );
		self::update_meta( $post_id, self::META_KEY, $snapshots );

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
			self::update_meta( $post_id, $key, $value );
		}
		foreach ( (array) ( $snapshot['meta_absent'] ?? [] ) as $key ) {
			if ( is_string( $key ) && in_array( $key, self::tracked_meta_keys(), true ) ) {
				self::delete_meta( $post_id, $key );
			}
		}

		return true;
	}

	/**
	 * Alias of restore() for API clarity on change-timeline abilities.
	 */
	public static function restore_snapshot( int $post_id, string $snapshot_id ): bool {
		return self::restore( $post_id, $snapshot_id );
	}

	/**
	 * Compact timeline rows (no heavy post_content / meta payloads).
	 *
	 * @return list<array{
	 *   snapshot_id: string,
	 *   post_id: int,
	 *   post_title: string,
	 *   post_status: string,
	 *   created_at: int,
	 *   meta_keys: list<string>
	 * }>
	 */
	public static function list_timeline( int $limit = 50, int $post_id = 0 ): array {
		$limit = max( 1, min( 200, $limit ) );
		$rows  = [];

		$post_ids = $post_id > 0
			? [ $post_id ]
			: self::post_ids_with_snapshots( $limit * 5 );

		foreach ( $post_ids as $id ) {
			$post = get_post( $id );
			foreach ( self::list_snapshots( $id ) as $snapshot_id => $payload ) {
				if ( ! is_array( $payload ) ) {
					continue;
				}
				$rows[] = [
					'snapshot_id' => (string) $snapshot_id,
					'post_id'     => (int) $id,
					'post_title'  => (string) ( $payload['post_title'] ?? ( $post->post_title ?? '' ) ),
					'post_status' => (string) ( $payload['post_status'] ?? ( $post->post_status ?? '' ) ),
					'created_at'  => (int) ( $payload['created_at'] ?? 0 ),
					'meta_keys'   => array_values( array_map( 'strval', array_keys( (array) ( $payload['meta'] ?? [] ) ) ) ),
				];
			}
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => ( $b['created_at'] <=> $a['created_at'] )
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * @return list<int>
	 */
	private static function post_ids_with_snapshots( int $max_posts ): array {
		$posts = get_posts(
			[
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => $max_posts,
				'meta_key'               => self::META_KEY,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		if ( is_array( $posts ) && [] !== $posts ) {
			$ids = [];
			foreach ( $posts as $item ) {
				if ( is_object( $item ) && isset( $item->ID ) ) {
					$ids[] = (int) $item->ID;
				} else {
					$ids[] = (int) $item;
				}
			}
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
			if ( [] !== $ids ) {
				return $ids;
			}
		}

		// Test bootstrap / hosts without meta_key query support: scan known posts.
		$fallback = [];
		foreach ( array_keys( $GLOBALS['stonewright_test_posts'] ?? [] ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 && [] !== self::list_snapshots( $id ) ) {
				$fallback[] = $id;
			}
		}
		return array_slice( $fallback, 0, $max_posts );
	}

	private static function new_snapshot_id(): string {
		return 'snap_' . bin2hex( random_bytes( 8 ) );
	}

	private static function update_meta( int $post_id, string $key, mixed $value ): int|bool {
		if ( 'revision' === get_post_type( $post_id ) ) {
			return update_metadata( 'post', $post_id, $key, $value );
		}

		return update_post_meta( $post_id, $key, $value );
	}

	private static function delete_meta( int $post_id, string $key ): bool {
		if ( 'revision' === get_post_type( $post_id ) ) {
			return delete_metadata( 'post', $post_id, $key );
		}

		return delete_post_meta( $post_id, $key );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function collect_meta( int $post_id ): array {
		$out  = [];
		foreach ( self::tracked_meta_keys() as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** @return list<string> */
	private static function tracked_meta_keys(): array {
		return [ '_elementor_data', '_elementor_page_settings', '_elementor_version', '_elementor_edit_mode', '_wp_page_template' ];
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

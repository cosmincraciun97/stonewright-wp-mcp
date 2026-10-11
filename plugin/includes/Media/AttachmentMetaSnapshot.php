<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Media;

use Stonewright\WpMcp\Support\Json;

/**
 * A bounded history of an attachment's metadata (`_wp_attachment_metadata`: dimensions, generated sizes,
 * image meta) taken before an ability rewrites it. Backup::snapshot_post() does not track that meta key.
 */
final class AttachmentMetaSnapshot {

	public const META_KEY = '_stonewright_attachment_meta_snapshots';

	/** Snapshots kept for one attachment; the oldest are dropped first. */
	public const LIMIT = 5;

	/**
	 * Store the current attachment metadata and read it back.
	 *
	 * @return string The snapshot id, or '' when the snapshot could not be stored and read back.
	 */
	public static function take( int $attachment_id ): string {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];

		$id       = 'mmeta_' . bin2hex( random_bytes( 8 ) );
		$payload  = [
			'snapshot_id' => $id,
			'created_at'  => time(),
			'metadata'    => $metadata,
		];
		$all      = self::all( $attachment_id );
		$all[ $id ] = $payload;
		if ( count( $all ) > self::LIMIT ) {
			$all = array_slice( $all, -self::LIMIT, null, true );
		}

		update_post_meta( $attachment_id, self::META_KEY, wp_slash( $all ) );
		$readback = self::get( $attachment_id, $id );

		return is_array( $readback ) && hash_equals( Json::hash( $payload ), Json::hash( $readback ) ) ? $id : '';
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all( int $attachment_id ): array {
		$stored = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $attachment_id, string $snapshot_id ): ?array {
		$all = self::all( $attachment_id );
		return isset( $all[ $snapshot_id ] ) && is_array( $all[ $snapshot_id ] ) ? $all[ $snapshot_id ] : null;
	}

	/**
	 * Regenerated metadata laid over the existing metadata: keys the new metadata does not mention stay, and the
	 * generated sizes are added to the existing ones instead of replacing them.
	 *
	 * @param array<string, mixed> $existing  Metadata before the rewrite.
	 * @param array<string, mixed> $generated Metadata the core generator returned.
	 * @return array<string, mixed>
	 */
	public static function merge( array $existing, array $generated ): array {
		$merged = $existing;
		foreach ( $generated as $key => $value ) {
			if ( 'sizes' === $key && is_array( $value ) && isset( $existing['sizes'] ) && is_array( $existing['sizes'] ) ) {
				$merged['sizes'] = array_merge( $existing['sizes'], $value );
				continue;
			}
			$merged[ $key ] = $value;
		}
		return $merged;
	}
}

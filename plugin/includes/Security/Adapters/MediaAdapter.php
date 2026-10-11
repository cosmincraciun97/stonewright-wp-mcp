<?php
/**
 * The media family in the change ledger: attachment fields and metadata, with an upload as a create.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\Permissions;

/**
 * The image of an attachment holds its title, caption (the excerpt), description (the content), slug,
 * parent, status and mime type; the alt text, the attachment metadata (sizes and image data) and the stock
 * image attribution; and the path and size of its file. It never holds the file itself.
 *
 * A restore writes the title, caption, description, slug, parent, alt text, metadata and attribution back.
 * It never writes the path of the file, and it brings back no file bytes. media-optimize regenerates the
 * sizes of an image from the original file, which it leaves as it is, and does not keep the size files it
 * replaces: its row says "metadata only" and a restore puts the earlier metadata back.
 *
 * An upload is a create. Its undo deletes the attachment and its files: with MEDIA_TRASH on the attachment
 * goes to the trash, and otherwise the delete is permanent, so the caller must ask for it with
 * options['permanent'].
 */
final class MediaAdapter extends FamilyAdapter {

	public const FAMILY = 'media';

	public const RECIPE = 'media';

	protected const ABILITIES = [
		'stonewright/media-set-alt',
		'stonewright/media-optimize',
		'stonewright/media-upload',
		'stonewright/stock-image-import',
		'stonewright/design-normalize-assets',
		'stonewright/design-apply-to-post',
		'stonewright/design-spec-to-elementor-v3',
	];

	/** Fields of the attachment row, and whether the value is a number. */
	private const POST_FIELDS = [
		'post_title'     => false,
		'post_excerpt'   => false,
		'post_content'   => false,
		'post_name'      => false,
		'post_parent'    => true,
		'post_status'    => false,
		'post_mime_type' => false,
	];

	private const RESTORED_FIELDS = [ 'post_title', 'post_excerpt', 'post_content', 'post_name', 'post_parent' ];

	/** Meta keys that are part of an image. The path of the file is imaged and never restored. */
	private const META_KEYS = [
		'_wp_attachment_image_alt',
		'_wp_attachment_metadata',
		'_wp_attached_file',
		'_stonewright_stock_provider',
		'_stonewright_stock_id',
		'_stonewright_stock_license',
		'_stonewright_stock_license_url',
		'_stonewright_stock_landing_url',
		'_stonewright_stock_creator',
		'_stonewright_stock_attribution',
	];

	private const NOT_COMPARED = [ 'file', 'meta_absent', 'meta._wp_attached_file', 'post.post_status', 'post.post_mime_type' ];

	public static function types(): array {
		return [ 'attachment' ];
	}

	public static function ledger_family( string $type ): string {
		return self::FAMILY;
	}

	public static function image( string $type, string $id ): ?array {
		if ( 'attachment' !== $type || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
			return null;
		}
		$post = get_post( (int) $id );
		if ( ! is_object( $post ) || 'attachment' !== (string) $post->post_type ) {
			return null;
		}
		$fields = [];
		foreach ( self::POST_FIELDS as $field => $numeric ) {
			$value            = $post->{$field} ?? ( $numeric ? 0 : '' );
			$fields[ $field ] = $numeric ? (int) $value : (string) $value;
		}
		$meta   = [];
		$absent = [];
		foreach ( self::META_KEYS as $key ) {
			if ( self::meta_exists( (int) $id, $key ) ) {
				$meta[ $key ] = get_post_meta( (int) $id, $key, true );
			} else {
				$absent[] = $key;
			}
		}
		ksort( $meta, SORT_STRING );
		sort( $absent );

		$image = [
			'v'           => self::IMAGE_VERSION,
			'post'        => $fields,
			'meta'        => $meta,
			'meta_absent' => $absent,
		];
		$file = self::file_facts( $meta['_wp_attached_file'] ?? '' );
		if ( [] !== $file ) {
			$image['file'] = $file;
		}
		ksort( $image, SORT_STRING );
		return $image;
	}

	public static function watch( string $ability, array $args ): array {
		if ( ! in_array( $ability, [ 'stonewright/media-set-alt', 'stonewright/media-optimize' ], true ) ) {
			return [];
		}
		$id = isset( $args['id'] ) && is_numeric( $args['id'] ) ? (int) $args['id'] : 0;
		return $id > 0 ? [ [ 'type' => 'attachment', 'id' => (string) $id, 'before' => self::image( 'attachment', (string) $id ) ] ] : [];
	}

	public static function discover( string $ability, array $context, mixed $result ): array {
		if ( ! is_array( $result ) ) {
			return [];
		}
		$ids = [];
		if ( in_array( $ability, [ 'stonewright/media-upload', 'stonewright/stock-image-import' ], true ) ) {
			$ids[] = $result['id'] ?? 0;
		} elseif ( 'stonewright/design-normalize-assets' === $ability && is_array( $result['attachments'] ?? null ) ) {
			$ids = array_values( $result['attachments'] );
		} elseif ( in_array( $ability, [ 'stonewright/design-apply-to-post', 'stonewright/design-spec-to-elementor-v3' ], true ) && is_array( $result['sideloaded_assets'] ?? null ) ) {
			$ids = array_values( $result['sideloaded_assets'] );
		}
		$found = [];
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$found[] = [ 'type' => 'attachment', 'id' => (string) (int) $id ];
			}
		}
		return $found;
	}

	public static function limits( string $ability, string $type, ?array $before, ?array $after ): array {
		if ( 'stonewright/media-optimize' === $ability && null !== $before && null !== $after ) {
			return [ 'metadata only; size files are not restored' ];
		}
		return [];
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		$title = is_array( $image['post'] ?? null ) ? (string) ( $image['post']['post_title'] ?? '' ) : '';
		return 'media ' . $id . ( '' === $title ? '' : ': ' . FamilyLedger::clip( $title, 40 ) );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return Permissions::upload_files();
	}

	protected static function removed( string $type, ?array $live ): bool {
		return null === $live || 'trash' === ( $live['post']['post_status'] ?? '' );
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		if ( null === $target ) {
			return self::undo_upload( $id, ! empty( $options['permanent'] ) );
		}
		if ( null === $live ) {
			return self::refused( 'attachment_missing' );
		}

		$update = [ 'ID' => (int) $id ];
		foreach ( self::RESTORED_FIELDS as $field ) {
			if ( isset( $target['post'][ $field ] ) ) {
				$update[ $field ] = self::POST_FIELDS[ $field ] ? (int) $target['post'][ $field ] : (string) $target['post'][ $field ];
			}
		}
		$written = wp_update_post( wp_slash( $update ), true );
		if ( $written instanceof \WP_Error ) {
			return self::refused( self::short_code( $written ) );
		}
		$meta = is_array( $target['meta'] ?? null ) ? $target['meta'] : [];
		foreach ( self::META_KEYS as $key ) {
			if ( '_wp_attached_file' === $key ) {
				continue;
			}
			if ( array_key_exists( $key, $meta ) ) {
				update_post_meta( (int) $id, $key, wp_slash( $meta[ $key ] ) );
			} elseif ( self::meta_exists( (int) $id, $key ) ) {
				delete_post_meta( (int) $id, $key );
			}
		}

		$differences = self::differences( $target, self::image( 'attachment', $id ), self::NOT_COMPARED );
		return self::applied( [] === $differences, [] === $differences ? 'restored' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ) );
	}

	/**
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function undo_upload( string $id, bool $permanent ): array {
		$trash = defined( 'MEDIA_TRASH' ) && (bool) constant( 'MEDIA_TRASH' );
		if ( ! $trash && ! $permanent ) {
			return self::refused( 'permanent_delete_not_confirmed' );
		}
		if ( ! function_exists( 'wp_delete_attachment' ) ) {
			return self::refused( 'delete_unavailable' );
		}
		wp_delete_attachment( (int) $id, ! $trash );
		$after = self::image( 'attachment', $id );
		$ok    = $trash ? null !== $after && 'trash' === ( $after['post']['post_status'] ?? '' ) : null === $after;
		return self::applied( $ok, $trash ? 'trashed' : 'deleted' );
	}

	/**
	 * The path and size of the file of an attachment.
	 *
	 * @return array<string, mixed>
	 */
	private static function file_facts( mixed $relative ): array {
		if ( ! is_string( $relative ) || '' === $relative ) {
			return [];
		}
		$facts   = [ 'path' => $relative ];
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [];
		$base    = is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		if ( '' !== $base && ! str_contains( $relative, '..' ) && is_file( rtrim( $base, '/\\' ) . '/' . ltrim( $relative, '/' ) ) ) {
			$facts['bytes'] = (int) filesize( rtrim( $base, '/\\' ) . '/' . ltrim( $relative, '/' ) );
		}
		return $facts;
	}

	private static function meta_exists( int $post_id, string $key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $key );
		}
		$all = get_post_meta( $post_id );
		return is_array( $all ) && array_key_exists( $key, $all );
	}
}

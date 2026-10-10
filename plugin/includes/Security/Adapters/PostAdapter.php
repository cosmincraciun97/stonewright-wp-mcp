<?php
/**
 * The post family in the change ledger: the image of a post, and the restore that writes one back.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Abilities\Seo\SeoAdapter;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\Logger;

/**
 * Posts, pages, Elementor documents, Gutenberg content, FSE templates, template parts, navigation, global
 * styles, patterns, Theme Builder templates and the kit are all rows of the posts table. This adapter
 * describes them to the ledger in one shape, an image, and writes an image back.
 *
 * An image holds: the title, status, content, excerpt, slug, parent, menu order and dates; the featured
 * image; the terms of every taxonomy of the post type; and the meta keys on the allowlist. That is the
 * Elementor keys, the page template, the section record of a built page, the keys of the supported SEO
 * plugins, and the ACF values with their field references. Keys named by the caller join it when they
 * are plain custom fields. The post password and every other key stay out. The ledger masks credentials
 * in an image before it stores it (ChangeImage) and marks such an image not restorable.
 *
 * record_before() and record_create() put a row in the ledger before a write, capture_after() reads the
 * image the write produced, and settle() closes the row. Every one of them swallows its own failure: a
 * ledger that cannot record never changes a write. restore() writes an image back and reads the post to
 * confirm it; trash_created() is the undo of a post a change created; undo() picks between the two for
 * a ledger row. restore(), trash_created() and undo() check no permission, token or newer change: the
 * code that calls them does.
 */
final class PostAdapter {

	public const IMAGE_VERSION = 1;

	/** Start of the summary of a row that created its post. */
	public const CREATED_SUMMARY = 'Created ';

	/** Fields of the post row, and whether the value is a number. */
	private const POST_FIELDS = [
		'post_title'    => false,
		'post_status'   => false,
		'post_content'  => false,
		'post_excerpt'  => false,
		'post_name'     => false,
		'post_parent'   => true,
		'menu_order'    => true,
		'post_date'     => false,
		'post_date_gmt' => false,
	];

	/** Meta keys that are part of every image. */
	private const TRACKED_META = [
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_version',
		'_elementor_edit_mode',
		'_elementor_conditions',
		'_stonewright_spec_sections',
		'_wp_page_template',
	];

	private const SEO_PLUGINS = [ 'yoast', 'rankmath', 'aioseo', 'seopress' ];

	/** Post types that other adapters describe, or that are not content. */
	private const EXCLUDED_TYPES = [ 'revision', 'custom_css', 'nav_menu_item', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request' ];

	/** Abilities that take a snapshot and write nothing. */
	private const READ_ONLY_ABILITIES = [ 'stonewright/site-backup-page', 'stonewright/elementor-v3-backup-page' ];

	private const SUMMARY_MAX = 120;

	/** @var bool|null Whether the ledger table is usable; only a yes is remembered. */
	private static ?bool $ledger_ready = null;

	public static function reset_for_tests(): void {
		self::$ledger_ready = null;
	}

	// -----------------------------------------------------------------------
	// Image.
	// -----------------------------------------------------------------------

	/**
	 * The image of a post as it is now.
	 *
	 * @param list<string> $extra_meta_keys Custom fields to include besides the allowlist.
	 * @return array<string, mixed>|null Null when the post does not exist.
	 */
	public static function image( int $post_id, array $extra_meta_keys = [] ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! is_object( $post ) ) {
			return null;
		}
		$post_type = (string) $post->post_type;

		$fields = [];
		foreach ( self::POST_FIELDS as $field => $numeric ) {
			$value            = $post->{$field} ?? ( $numeric ? 0 : '' );
			$fields[ $field ] = $numeric ? (int) $value : (string) $value;
		}

		$wanted = array_merge( self::allowlisted_keys(), self::acf_keys( $post_id ), self::clean_extra_keys( $extra_meta_keys ) );
		$meta   = [];
		$absent = [];
		foreach ( array_values( array_unique( $wanted ) ) as $key ) {
			if ( self::meta_exists( $post_id, $key ) ) {
				$meta[ $key ] = get_post_meta( $post_id, $key, true );
			} else {
				$absent[] = $key;
			}
		}
		sort( $absent );
		ksort( $meta, SORT_STRING );

		$thumbnail = self::meta_exists( $post_id, '_thumbnail_id' ) ? (int) get_post_meta( $post_id, '_thumbnail_id', true ) : 0;

		return [
			'v'              => self::IMAGE_VERSION,
			'post_type'      => $post_type,
			'post'           => $fields,
			'featured_image' => max( 0, $thumbnail ),
			'terms'          => self::terms_of( $post_id, $post_type ),
			'meta'           => $meta,
			'meta_absent'    => $absent,
		];
	}

	/**
	 * The ledger family of a post change: from the post type when it says what the post is, otherwise from
	 * the ability that writes it.
	 */
	public static function ledger_family( string $ability, string $post_type ): string {
		$by_type = match ( $post_type ) {
			'wp_template', 'wp_template_part', 'wp_navigation' => 'fse',
			'wp_global_styles'                                 => 'global_styles',
			'elementor_library'                                => 'elementor',
			'wp_block'                                         => 'gutenberg',
			default                                            => '',
		};
		if ( '' !== $by_type ) {
			return $by_type;
		}
		$name = strtolower( (string) substr( strrchr( '/' . $ability, '/' ) ?: '', 1 ) );
		foreach ( [
			'elementor-'     => 'elementor',
			'theme-builder-' => 'elementor',
			'section-reuse-' => 'elementor',
			'blocks-'        => 'gutenberg',
			'gutenberg-'     => 'gutenberg',
			'patterns-'      => 'gutenberg',
			'fse-'           => 'fse',
		] as $prefix => $family ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return $family;
			}
		}
		return 'post';
	}

	/**
	 * The custom fields a write names that its image may cover: the ones the write itself is allowed to
	 * touch. A protected key is never imaged.
	 *
	 * @param list<mixed> $keys
	 * @return list<string>
	 */
	public static function writable_meta_keys( int $post_id, array $keys ): array {
		$out = [];
		foreach ( self::clean_extra_keys( $keys ) as $key ) {
			if ( ! Permissions::can_edit_post_meta( $post_id, $key ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// Ledger.
	// -----------------------------------------------------------------------

	/**
	 * Record a post that is about to change, with its image as it is now.
	 *
	 * @param string       $change_id      The journal id of the same change, or '' for an id of the ledger's own.
	 * @param list<string> $extra_meta_keys Custom fields the write names.
	 * @return string The change id, or '' when nothing was recorded.
	 */
	public static function record_before( string $ability, int $post_id, string $change_id = '', array $extra_meta_keys = [] ): string {
		try {
			$image = self::recordable_image( $ability, $post_id, $extra_meta_keys );
			if ( null === $image ) {
				return '';
			}
			$spec = [
				'ability'       => $ability,
				'family'        => self::ledger_family( $ability, (string) $image['post_type'] ),
				'resource_type' => 'post',
				'resource_id'   => (string) $post_id,
				'before'        => $image,
				'summary'       => self::summary( $image ),
			];
			if ( '' !== $change_id ) {
				$spec['change_id'] = $change_id;
			}
			return self::stored_id( ChangeLedger::record( $spec ), $ability );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
			return '';
		}
	}

	/**
	 * Record a post that a change has just created. It has no before image; its undo is the trash.
	 *
	 * @return string The change id, or '' when nothing was recorded.
	 */
	public static function record_create( string $ability, int $post_id ): string {
		try {
			$image = self::recordable_image( $ability, $post_id, [] );
			if ( null === $image ) {
				return '';
			}
			return self::stored_id(
				ChangeLedger::record(
					[
						'ability'       => $ability,
						'family'        => self::ledger_family( $ability, (string) $image['post_type'] ),
						'resource_type' => 'post',
						'resource_id'   => (string) $post_id,
						'before'        => null,
						'restorable'    => true,
						'summary'       => self::CREATED_SUMMARY . self::summary( $image ),
					]
				),
				$ability
			);
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
			return '';
		}
	}

	/**
	 * The image of a post after a write, to settle a row with.
	 *
	 * @param list<string> $extra_meta_keys The keys the row was recorded with.
	 * @return array<string, mixed>|null
	 */
	public static function capture_after( int $post_id, array $extra_meta_keys = [] ): ?array {
		try {
			return self::image( $post_id, $extra_meta_keys );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_capture_failed', [ 'error' => $failure::class ] );
			return null;
		}
	}

	/**
	 * Close a recorded row with its status and the image the write produced.
	 *
	 * @param array<string, mixed>|null $after
	 */
	public static function settle( string $change_id, ?array $after, string $status ): bool {
		try {
			$result = [ 'status' => $status ];
			if ( null !== $after ) {
				$result['after'] = $after;
			}
			$row = ChangeLedger::settle( $change_id, $result );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_ledger_settle_failed', [ 'code' => $row->get_error_code() ] );
				return false;
			}
			return true;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_settle_failed', [ 'error' => $failure::class ] );
			return false;
		}
	}

	/**
	 * Whether a post, as it is after a write, differs from the before image of its row. Without an image
	 * to compare it is taken to differ.
	 *
	 * @param array<string, mixed>|null $after
	 */
	public static function changed( string $change_id, ?array $after ): bool {
		$row = null === $after ? null : ChangeLedger::get( $change_id );
		if ( null === $row || '' === (string) $row['before_sha256'] ) {
			return true;
		}
		return ChangeImage::hash_of( $after, 'post', (string) $row['resource_id'] ) !== $row['before_sha256'];
	}

	/** Whether a ledger row is the creation of its post. */
	public static function is_created_row( array $row ): bool {
		return 'post' === ( $row['resource_type'] ?? '' )
			&& true === ( $row['restorable'] ?? false )
			&& '' === (string) ( $row['before_ref'] ?? 'x' )
			&& '' === (string) ( $row['before_sha256'] ?? 'x' )
			&& str_starts_with( (string) ( $row['summary'] ?? '' ), self::CREATED_SUMMARY );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	/**
	 * Write an image back onto its post, then read the post to confirm.
	 *
	 * The fields, the featured image, the terms and the meta of the image are written; a meta key on the
	 * allowlist that the image lists as absent is deleted, and so is an ACF value that the image does not
	 * hold. A term or a featured image that no longer exists is skipped and reported. An image that was
	 * masked, or is not an image, or belongs to another post type, is refused and nothing is written.
	 *
	 * @param array<string, mixed> $image
	 * @return array{ok:bool,post_id:int,skipped:list<string>,differences:list<string>}|\WP_Error ok is true only when the post now equals the image.
	 */
	public static function restore( int $post_id, array $image ): array|\WP_Error {
		if ( self::IMAGE_VERSION !== ( $image['v'] ?? null ) || ! is_array( $image['post'] ?? null ) || ! array_key_exists( 'post_title', $image['post'] ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not a post image this version can restore.', 'stonewright' ) );
		}
		if ( self::has_mask( $image ) ) {
			return new \WP_Error( 'stonewright_image_masked', __( 'The image had credentials masked out of it, so it cannot be written back.', 'stonewright' ) );
		}
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'stonewright_post_missing', __( 'The post no longer exists.', 'stonewright' ) );
		}
		if ( isset( $image['post_type'] ) && (string) $image['post_type'] !== (string) $post->post_type ) {
			return new \WP_Error( 'stonewright_post_type_mismatch', __( 'The image is of another post type than the post.', 'stonewright' ) );
		}

		$update = [ 'ID' => $post_id ];
		foreach ( self::POST_FIELDS as $field => $numeric ) {
			if ( ! array_key_exists( $field, $image['post'] ) ) {
				continue;
			}
			if ( in_array( $field, [ 'post_date', 'post_date_gmt' ], true ) && '' === (string) $image['post'][ $field ] ) {
				continue;
			}
			$update[ $field ] = $numeric ? (int) $image['post'][ $field ] : (string) $image['post'][ $field ];
		}
		if ( isset( $update['post_date'] ) ) {
			$update['edit_date'] = true;
		}
		$written = wp_update_post( wp_slash( $update ), true );
		if ( $written instanceof \WP_Error ) {
			return $written;
		}

		$skipped = [];
		$meta    = is_array( $image['meta'] ?? null ) ? $image['meta'] : [];
		$absent  = array_values( array_filter( (array) ( $image['meta_absent'] ?? [] ), 'is_string' ) );
		foreach ( $meta as $key => $value ) {
			if ( ! self::may_restore_key( (string) $key, $meta ) ) {
				$skipped[] = 'meta.' . $key;
				continue;
			}
			update_post_meta( $post_id, (string) $key, wp_slash( $value ) );
		}
		foreach ( $absent as $key ) {
			if ( ! self::may_restore_key( $key, $meta ) ) {
				$skipped[] = 'meta.' . $key;
				continue;
			}
			if ( self::meta_exists( $post_id, $key ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
		foreach ( self::acf_keys( $post_id ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				delete_post_meta( $post_id, $key );
			}
		}

		$thumbnail = (int) ( $image['featured_image'] ?? 0 );
		if ( $thumbnail > 0 ) {
			$attachment = get_post( $thumbnail );
			if ( is_object( $attachment ) && 'attachment' === (string) $attachment->post_type ) {
				update_post_meta( $post_id, '_thumbnail_id', $thumbnail );
			} else {
				$skipped[] = 'featured_image';
			}
		} elseif ( self::meta_exists( $post_id, '_thumbnail_id' ) ) {
			delete_post_meta( $post_id, '_thumbnail_id' );
		}

		foreach ( (array) ( $image['terms'] ?? [] ) as $taxonomy => $terms ) {
			$ids = [];
			foreach ( (array) $terms as $term ) {
				$term_id = is_array( $term ) ? (int) ( $term['term_id'] ?? 0 ) : 0;
				if ( $term_id < 1 ) {
					continue;
				}
				if ( function_exists( 'term_exists' ) && ! term_exists( $term_id, (string) $taxonomy ) ) {
					$skipped[] = 'terms.' . $taxonomy . '.' . $term_id;
					continue;
				}
				$ids[] = $term_id;
			}
			if ( $ids === array_values( array_map( 'intval', array_column( self::terms_of( $post_id, (string) ( $image['post_type'] ?? '' ) )[ $taxonomy ] ?? [], 'term_id' ) ) ) ) {
				continue;
			}
			if ( wp_set_object_terms( $post_id, $ids, (string) $taxonomy, false ) instanceof \WP_Error ) {
				$skipped[] = 'terms.' . $taxonomy;
			}
		}

		if ( array_key_exists( '_elementor_data', $meta ) || in_array( '_elementor_data', $absent, true ) ) {
			PostCacheInvalidator::invalidate( $post_id );
		}

		$live        = self::image( $post_id, array_merge( array_keys( $meta ), $absent ) );
		$differences = null === $live ? [ 'post' ] : self::differences( $image, $live );
		return [
			'ok'          => [] === $differences,
			'post_id'     => $post_id,
			'skipped'     => $skipped,
			'differences' => $differences,
		];
	}

	/**
	 * Move a post that a change created to the trash. Never deletes it, and never when the site keeps no trash.
	 *
	 * @return array{ok:bool,post_id:int,action:string}|\WP_Error
	 */
	public static function trash_created( int $post_id ): array|\WP_Error {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'stonewright_post_missing', __( 'The post no longer exists.', 'stonewright' ) );
		}
		if ( 'trash' === (string) $post->post_status ) {
			return [ 'ok' => true, 'post_id' => $post_id, 'action' => 'already_trashed' ];
		}
		$days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) constant( 'EMPTY_TRASH_DAYS' ) : 30;
		if ( $days < 1 || ! function_exists( 'wp_trash_post' ) ) {
			return new \WP_Error( 'stonewright_trash_disabled', __( 'This site keeps no trash, so the post is not removed. Delete it in wp-admin if it is not wanted.', 'stonewright' ) );
		}
		wp_trash_post( $post_id );
		return [ 'ok' => 'trash' === (string) get_post_status( $post_id ), 'post_id' => $post_id, 'action' => 'trashed' ];
	}

	/**
	 * Undo one ledger row of a post: trash the post a change created, or write the before image back.
	 *
	 * @return array<string, mixed>|\WP_Error The result of trash_created() or restore().
	 */
	public static function undo( string $change_id ): array|\WP_Error {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return new \WP_Error( 'stonewright_change_not_found', __( 'The change is not recorded.', 'stonewright' ) );
		}
		if ( 'post' !== $row['resource_type'] ) {
			return new \WP_Error( 'stonewright_change_not_a_post', __( 'The change is not a change of a post.', 'stonewright' ) );
		}
		if ( ! $row['restorable'] ) {
			return new \WP_Error( 'stonewright_change_not_restorable', __( 'The change cannot be undone from the ledger.', 'stonewright' ), [ 'reason' => $row['restorable_reason'] ] );
		}
		$post_id = (int) $row['resource_id'];
		if ( self::is_created_row( $row ) ) {
			return self::trash_created( $post_id );
		}
		$image = ChangeLedger::read_image( $change_id, 'before' );
		if ( $image instanceof \WP_Error ) {
			return $image;
		}
		if ( ! is_array( $image ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The stored image is not a post image.', 'stonewright' ) );
		}
		return self::restore( $post_id, $image );
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * The image to record, or null when this write is not recorded: the ledger is not usable, the post is
	 * of a type that another adapter describes, or the ability writes nothing.
	 *
	 * @param list<string> $extra_meta_keys
	 * @return array<string, mixed>|null
	 */
	private static function recordable_image( string $ability, int $post_id, array $extra_meta_keys ): ?array {
		if ( in_array( $ability, self::READ_ONLY_ABILITIES, true ) || ! self::ledger_ready() ) {
			return null;
		}
		$image = self::image( $post_id, $extra_meta_keys );
		if ( null === $image || in_array( (string) $image['post_type'], self::EXCLUDED_TYPES, true ) || 'auto-draft' === ( $image['post']['post_status'] ?? '' ) ) {
			return null;
		}
		return $image;
	}

	private static function ledger_ready(): bool {
		if ( true === self::$ledger_ready ) {
			return true;
		}
		if ( ChangeLedger::table_schema_ok() ) {
			self::$ledger_ready = true;
			return true;
		}
		return false;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $row
	 */
	private static function stored_id( array|\WP_Error $row, string $ability ): string {
		if ( $row instanceof \WP_Error ) {
			Logger::warning( 'change_ledger_record_failed', [ 'code' => $row->get_error_code(), 'ability' => $ability ] );
			return '';
		}
		return (string) $row['change_id'];
	}

	/**
	 * @param array<string, mixed> $image
	 */
	private static function summary( array $image ): string {
		$title = (string) ( $image['post']['post_title'] ?? '' );
		$text  = (string) $image['post_type'] . ( '' === $title ? '' : ': ' . $title );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SUMMARY_MAX ) : substr( $text, 0, self::SUMMARY_MAX );
	}

	/** @return list<string> */
	private static function allowlisted_keys(): array {
		$keys = self::TRACKED_META;
		foreach ( self::SEO_PLUGINS as $plugin ) {
			foreach ( SeoAdapter::meta_keys( $plugin ) as $key ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Custom fields a caller names: plain keys only. Keys that start with an underscore are protected by
	 * WordPress and are imaged only when they are on the allowlist.
	 *
	 * @param list<mixed> $keys
	 * @return list<string>
	 */
	private static function clean_extra_keys( array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key && '_' !== $key[0] && strlen( $key ) <= 191 ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The ACF values of a post: every key that has a companion key with an ACF field reference, which is
	 * how ACF stores a value, and that companion key.
	 *
	 * @return list<string>
	 */
	private static function acf_keys( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return [];
		}
		$keys = [];
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] ) {
				continue;
			}
			$reference = $all[ '_' . $key ] ?? null;
			$reference = is_array( $reference ) ? ( $reference[0] ?? '' ) : $reference;
			if ( is_string( $reference ) && 1 === preg_match( '/^field_[A-Za-z0-9]+$/D', $reference ) ) {
				$keys[] = $key;
				$keys[] = '_' . $key;
			}
		}
		return $keys;
	}

	/**
	 * Whether a meta key may be written or deleted by a restore: on the allowlist, one half of an ACF
	 * value in the image, or a plain custom field.
	 *
	 * @param array<mixed> $meta The meta of the image.
	 */
	private static function may_restore_key( string $key, array $meta ): bool {
		if ( '' === $key ) {
			return false;
		}
		if ( in_array( $key, self::allowlisted_keys(), true ) ) {
			return true;
		}
		if ( '_' !== $key[0] ) {
			return true;
		}
		$base      = substr( $key, 1 );
		$reference = $meta[ $key ] ?? null;
		return '' !== $base && array_key_exists( $base, $meta ) && is_string( $reference ) && 1 === preg_match( '/^field_[A-Za-z0-9]+$/D', $reference );
	}

	/**
	 * @return array<string, list<array{term_id:int,slug:string,name:string}>>
	 */
	private static function terms_of( int $post_id, string $post_type ): array {
		$out = [];
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'get_the_terms' ) ) {
			return $out;
		}
		foreach ( (array) get_object_taxonomies( $post_type ) as $taxonomy ) {
			$found = get_the_terms( $post_id, (string) $taxonomy );
			if ( $found instanceof \WP_Error ) {
				continue;
			}
			$list = [];
			foreach ( is_array( $found ) ? $found : [] as $term ) {
				if ( is_object( $term ) ) {
					$list[] = [
						'term_id' => (int) $term->term_id,
						'slug'    => (string) $term->slug,
						'name'    => (string) $term->name,
					];
				}
			}
			usort( $list, static fn ( array $a, array $b ): int => $a['term_id'] <=> $b['term_id'] );
			$out[ (string) $taxonomy ] = $list;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function meta_exists( int $post_id, string $key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $key );
		}
		$all = get_post_meta( $post_id );
		return is_array( $all ) && array_key_exists( $key, $all );
	}

	/**
	 * Whether any value of the image is a mask the ledger put there.
	 *
	 * @param array<string, mixed> $image
	 */
	private static function has_mask( array $image ): bool {
		return self::value_has_mask( $image['post'] ?? [] ) || self::value_has_mask( $image['meta'] ?? [] );
	}

	private static function value_has_mask( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return ChangeImage::MASK === $value || str_contains( $value, '[masked line ' ) || str_contains( $value, '[masked private key]' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::value_has_mask( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The parts of two images that differ.
	 *
	 * @param array<string, mixed> $wanted
	 * @param array<string, mixed> $live
	 * @return list<string>
	 */
	private static function differences( array $wanted, array $live ): array {
		$out = [];
		foreach ( (array) $wanted['post'] as $field => $value ) {
			if ( ! self::same( $value, $live['post'][ $field ] ?? null ) ) {
				$out[] = 'post.' . $field;
			}
		}
		if ( (int) ( $wanted['featured_image'] ?? 0 ) !== (int) ( $live['featured_image'] ?? 0 ) ) {
			$out[] = 'featured_image';
		}
		foreach ( (array) ( $wanted['terms'] ?? [] ) as $taxonomy => $terms ) {
			$want = array_map( 'intval', array_column( (array) $terms, 'term_id' ) );
			$have = array_map( 'intval', array_column( (array) ( $live['terms'][ $taxonomy ] ?? [] ), 'term_id' ) );
			if ( $want !== $have ) {
				$out[] = 'terms.' . $taxonomy;
			}
		}
		$keys = array_unique( array_merge( array_keys( (array) ( $wanted['meta'] ?? [] ) ), array_keys( (array) ( $live['meta'] ?? [] ) ) ) );
		foreach ( $keys as $key ) {
			$here  = array_key_exists( $key, (array) ( $wanted['meta'] ?? [] ) );
			$there = array_key_exists( $key, (array) ( $live['meta'] ?? [] ) );
			if ( $here !== $there || ( $here && ! self::same( $wanted['meta'][ $key ], $live['meta'][ $key ] ) ) ) {
				$out[] = 'meta.' . $key;
			}
		}
		return $out;
	}

	private static function same( mixed $a, mixed $b ): bool {
		return self::canonical( $a ) === self::canonical( $b );
	}

	private static function canonical( mixed $value ): string {
		$value = self::sorted( $value );
		$json  = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return is_string( $json ) ? $json : '';
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sorted( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}

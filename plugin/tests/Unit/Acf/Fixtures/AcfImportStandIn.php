<?php
/**
 * A stand-in for ACF's field group importer that keeps groups and fields as posts.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Acf\Fixtures;

/**
 * What acf_import_field_group() does with a payload (ACF 6.x, includes/acf-field-group-functions.php):
 *
 * - The fields are flattened, nested ones with `parent` set to the key of their parent field
 *   (acf_prepare_fields_for_import).
 * - When the group carries an ID, the fields the database holds under it are mapped key to ID and the ones
 *   the payload does not name are deleted for good (acf_delete_field calls wp_delete_post with force).
 * - When the group carries no ID, a temporary post gives it one before the fields are saved.
 * - Every field goes through acf_update_field with the ID its key maps to, or a new post, under its parent.
 * - acf_update_field_group then writes the group post: post_name is the key, post_type acf-field-group.
 *
 * acf_get_field_group( $key ) and acf_get_fields( $key ) read the posts: a key resolves to its oldest post,
 * the way acf_get_field_group_post() answers with posts_per_page 1.
 */
final class AcfImportStandIn {

	/** @var list<array<string, mixed>> The payloads the importer received, in order. */
	public static array $received = [];

	/** Installs the stand-in as acf_import_field_group() and makes the ACF readers follow the posts. */
	public static function install(): void {
		self::$received                                  = [];
		$GLOBALS['stonewright_test_acf_import_callback'] = static fn ( array $group ): array => self::import( $group );
		self::sync_from_posts();
	}

	public static function uninstall(): void {
		self::$received = [];
		unset( $GLOBALS['stonewright_test_acf_import_callback'], $GLOBALS['stonewright_test_acf_field_defs'], $GLOBALS['stonewright_test_acf_local_groups'] );
		$GLOBALS['stonewright_test_acf_groups'] = [];
	}

	/**
	 * @param array<string, mixed> $group
	 * @return array<string, mixed>
	 */
	public static function import( array $group ): array {
		self::$received[] = $group;
		$key      = (string) ( $group['key'] ?? '' );
		$group_id = (int) ( $group['ID'] ?? 0 );
		$fields   = self::flatten( (array) ( $group['fields'] ?? [] ), $key );
		$ids      = [];
		if ( $group_id > 0 ) {
			$keys = array_column( $fields, 'key' );
			foreach ( self::field_posts( $group_id ) as $field_key => $field_id ) {
				$ids[ $field_key ] = $field_id;
				if ( ! in_array( $field_key, $keys, true ) ) {
					wp_delete_post( $field_id, true );
				}
			}
		} else {
			$group_id    = (int) wp_insert_post( [ 'post_title' => $key ] );
			$group['ID'] = $group_id;
		}
		$ids[ $key ] = $group_id;
		foreach ( $fields as $index => $field ) {
			$field_key = (string) $field['key'];
			if ( isset( $ids[ $field_key ] ) ) {
				$field['ID'] = $ids[ $field_key ];
			}
			$parent   = (string) ( $field['parent'] ?? '' );
			$settings = $field;
			unset( $settings['ID'], $settings['key'], $settings['label'], $settings['name'], $settings['parent'], $settings['sub_fields'], $settings['layouts'] );
			$save = [
				'post_type'    => 'acf-field',
				'post_status'  => 'publish',
				'post_title'   => (string) ( $field['label'] ?? '' ),
				'post_name'    => $field_key,
				'post_excerpt' => (string) ( $field['name'] ?? '' ),
				'post_parent'  => isset( $ids[ $parent ] ) ? $ids[ $parent ] : (int) $parent,
				'post_content' => serialize( $settings ),
				'menu_order'   => $index,
			];
			$field_id = (int) ( $field['ID'] ?? 0 );
			if ( $field_id > 0 ) {
				wp_update_post( [ 'ID' => $field_id ] + $save );
			} else {
				$field_id = (int) wp_insert_post( $save );
			}
			$ids[ $field_key ] = $field_id;
		}
		$settings = $group;
		unset( $settings['ID'], $settings['key'], $settings['title'], $settings['fields'] );
		wp_update_post(
			[
				'ID'           => $group_id,
				'post_type'    => 'acf-field-group',
				'post_status'  => ( $group['active'] ?? true ) ? 'publish' : 'acf-disabled',
				'post_title'   => (string) ( $group['title'] ?? '' ),
				'post_name'    => $key,
				'post_content' => serialize( $settings ),
			]
		);
		self::sync_from_posts();
		return $group;
	}

	/** Rebuilds what acf_get_field_group() and acf_get_fields() answer from the posts. */
	public static function sync_from_posts(): void {
		$groups = [];
		$defs   = [];
		$posts  = $GLOBALS['stonewright_test_posts'];
		ksort( $posts );
		foreach ( $posts as $post ) {
			$key = (string) ( $post->post_name ?? '' );
			if ( 'acf-field-group' !== (string) ( $post->post_type ?? '' ) || isset( $defs[ $key ] ) ) {
				continue;
			}
			$settings = (array) ( @unserialize( (string) ( $post->post_content ?? '' ) ) ?: [] );
			$groups[] = array_merge(
				$settings,
				[
					'key'    => $key,
					'ID'     => (int) $post->ID,
					'title'  => (string) ( $post->post_title ?? '' ),
					'active' => 'publish' === (string) ( $post->post_status ?? '' ),
				]
			);
			$defs[ $key ] = self::read_fields( (int) $post->ID );
		}
		$GLOBALS['stonewright_test_acf_groups']     = $groups;
		$GLOBALS['stonewright_test_acf_field_defs'] = $defs;
	}

	/**
	 * The field posts under a parent, nested ones included, as key to ID.
	 *
	 * @return array<string, int>
	 */
	public static function field_posts( int $parent_id ): array {
		$map = [];
		foreach ( self::children( $parent_id ) as $post ) {
			$map[ (string) $post->post_name ] = (int) $post->ID;
			foreach ( self::field_posts( (int) $post->ID ) as $key => $id ) {
				$map[ $key ] = $id;
			}
		}
		return $map;
	}

	/**
	 * @param list<array<string, mixed>> $fields
	 * @return list<array<string, mixed>>
	 */
	private static function flatten( array $fields, string $parent ): array {
		$flat = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field['parent'] = $parent;
			$children        = (array) ( $field['sub_fields'] ?? [] );
			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				foreach ( (array) ( is_array( $layout ) ? ( $layout['sub_fields'] ?? [] ) : [] ) as $sub ) {
					$children[] = $sub;
				}
			}
			unset( $field['sub_fields'] );
			$flat[] = $field;
			foreach ( self::flatten( $children, (string) $field['key'] ) as $sub ) {
				$flat[] = $sub;
			}
		}
		return $flat;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function read_fields( int $parent_id ): array {
		$fields = [];
		foreach ( self::children( $parent_id ) as $post ) {
			$settings = (array) ( @unserialize( (string) ( $post->post_content ?? '' ) ) ?: [] );
			$field    = array_merge(
				$settings,
				[
					'ID'         => (int) $post->ID,
					'key'        => (string) $post->post_name,
					'name'       => (string) ( $post->post_excerpt ?? '' ),
					'label'      => (string) ( $post->post_title ?? '' ),
					'parent'     => $parent_id,
					'menu_order' => (int) ( $post->menu_order ?? 0 ),
				]
			);
			$subs     = self::read_fields( (int) $post->ID );
			if ( [] !== $subs ) {
				$field['sub_fields'] = $subs;
			}
			$fields[] = $field;
		}
		return $fields;
	}

	/**
	 * @return list<object>
	 */
	private static function children( int $parent_id ): array {
		$children = [];
		foreach ( $GLOBALS['stonewright_test_posts'] as $post ) {
			if ( 'acf-field' === (string) ( $post->post_type ?? '' ) && (int) ( $post->post_parent ?? 0 ) === $parent_id && 'publish' === (string) ( $post->post_status ?? '' ) ) {
				$children[] = $post;
			}
		}
		usort( $children, static fn ( object $a, object $b ): int => [ (int) ( $a->menu_order ?? 0 ), (int) $a->ID ] <=> [ (int) ( $b->menu_order ?? 0 ), (int) $b->ID ] );
		return $children;
	}
}

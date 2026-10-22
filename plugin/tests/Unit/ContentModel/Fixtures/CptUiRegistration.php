<?php
/**
 * How Custom Post Type UI turns a stored entry into register_post_type() and register_taxonomy() arguments.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Derived from Custom Post Type UI 1.19.3, https://github.com/WebDevStudios/custom-post-type-ui:
 * cptui_register_single_post_type(), cptui_register_single_taxonomy(), cptui_get_preserved_keys() and
 * cptui_get_preserved_labels() from custom-post-type-ui.php, get_disp_boolean() and disp_boolean() from
 * inc/utility.php. Copyright WebDevStudios, GPL-2.0+. Modifications: the functions are static methods of
 * one class, and the two register functions return the arguments instead of registering anything. Every
 * key the plugin reads stays read the same way, so an entry that lacks one raises the same PHP warning.
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ContentModel\Fixtures;

final class CptUiRegistration {

	/**
	 * The arguments CPT UI passes to register_post_type() for an entry of the cptui_post_types option.
	 *
	 * @param array<string, mixed> $post_type
	 * @return array<string, mixed>
	 */
	public static function post_type_args( array $post_type = [] ): array {
		$post_type['map_meta_cap'] = apply_filters( 'cptui_map_meta_cap', true, $post_type['name'], $post_type );

		if ( empty( $post_type['supports'] ) ) {
			$post_type['supports'] = [];
		}

		$user_supports_params = apply_filters( 'cptui_user_supports_params', [], $post_type['name'], $post_type );

		if ( is_array( $user_supports_params ) && ! empty( $user_supports_params ) ) {
			if ( is_array( $post_type['supports'] ) ) {
				$post_type['supports'] = array_merge( $post_type['supports'], $user_supports_params );
			} else {
				$post_type['supports'] = [ $user_supports_params ];
			}
		}

		$yarpp = false;
		if ( ! empty( $post_type['custom_supports'] ) ) {
			$custom = explode( ',', $post_type['custom_supports'] );
			foreach ( $custom as $part ) {
				if ( in_array( $part, [ 'YARPP', 'yarpp' ], true ) ) {
					$yarpp = true;
					continue;
				}
				$post_type['supports'][] = trim( $part );
			}
		}

		if ( isset( $post_type['supports'] ) && is_array( $post_type['supports'] ) && in_array( 'none', $post_type['supports'], true ) ) {
			$post_type['supports'] = false;
		}

		$labels = [
			'name'          => $post_type['label'],
			'singular_name' => $post_type['singular_label'],
		];

		$preserved        = self::preserved_keys( 'post_types' );
		$preserved_labels = self::preserved_labels();
		foreach ( $post_type['labels'] as $key => $label ) {

			if ( ! empty( $label ) ) {
				if ( 'parent' === $key ) {
					$labels['parent_item_colon'] = $label;
				} else {
					$labels[ $key ] = $label;
				}
			} elseif ( empty( $label ) && in_array( $key, $preserved, true ) ) {
				$singular_or_plural = ( in_array( $key, array_keys( $preserved_labels['post_types']['plural'] ) ) ) ? 'plural' : 'singular';
				$label_plurality    = ( 'plural' === $singular_or_plural ) ? $post_type['label'] : $post_type['singular_label'];
				$labels[ $key ]     = sprintf( $preserved_labels['post_types'][ $singular_or_plural ][ $key ], $label_plurality );
			}
		}

		$has_archive = isset( $post_type['has_archive'] ) ? self::get_disp_boolean( $post_type['has_archive'] ) : false;
		if ( $has_archive && ! empty( $post_type['has_archive_string'] ) ) {
			$has_archive = $post_type['has_archive_string'];
		}

		$show_in_menu = self::get_disp_boolean( $post_type['show_in_menu'] );
		if ( ! empty( $post_type['show_in_menu_string'] ) ) {
			$show_in_menu = $post_type['show_in_menu_string'];
		}

		$rewrite = self::get_disp_boolean( $post_type['rewrite'] );
		if ( false !== $rewrite ) {
			$rewrite         = [];
			$rewrite['slug'] = ! empty( $post_type['rewrite_slug'] ) ? $post_type['rewrite_slug'] : $post_type['name'];

			$rewrite['with_front'] = true;
			if ( isset( $post_type['rewrite_withfront'] ) ) {
				$rewrite['with_front'] = 'false' === self::disp_boolean( $post_type['rewrite_withfront'] ) ? false : true;
			}
		}

		$menu_icon            = ! empty( $post_type['menu_icon'] ) ? $post_type['menu_icon'] : null;
		$register_meta_box_cb = ! empty( $post_type['register_meta_box_cb'] ) ? $post_type['register_meta_box_cb'] : null;

		if ( in_array( $post_type['query_var'], [ 'true', 'false', '0', '1' ], true ) ) {
			$post_type['query_var'] = self::get_disp_boolean( $post_type['query_var'] );
		}
		if ( ! empty( $post_type['query_var_slug'] ) ) {
			$post_type['query_var'] = $post_type['query_var_slug'];
		}

		$menu_position = null;
		if ( ! empty( $post_type['menu_position'] ) ) {
			$menu_position = (int) $post_type['menu_position'];
		}

		$delete_with_user = null;
		if ( ! empty( $post_type['delete_with_user'] ) ) {
			$delete_with_user = self::get_disp_boolean( $post_type['delete_with_user'] );
		}

		$capability_type = 'post';
		if ( ! empty( $post_type['capability_type'] ) ) {
			$capability_type = $post_type['capability_type'];
			if ( false !== strpos( $post_type['capability_type'], ',' ) ) {
				$caps = array_map( 'trim', explode( ',', $post_type['capability_type'] ) );
				if ( count( $caps ) > 2 ) {
					$caps = array_slice( $caps, 0, 2 );
				}
				$capability_type = $caps;
			}
		}

		$public = self::get_disp_boolean( $post_type['public'] );
		if ( ! empty( $post_type['exclude_from_search'] ) ) {
			$exclude_from_search = self::get_disp_boolean( $post_type['exclude_from_search'] );
		} else {
			$exclude_from_search = false === $public;
		}

		$queryable = ( ! empty( $post_type['publicly_queryable'] ) && isset( $post_type['publicly_queryable'] ) ) ? self::get_disp_boolean( $post_type['publicly_queryable'] ) : $public;

		if ( empty( $post_type['show_in_nav_menus'] ) ) {
			$post_type['show_in_nav_menus'] = $public;
		}

		if ( empty( $post_type['show_in_rest'] ) ) {
			$post_type['show_in_rest'] = false;
		}

		$rest_base = null;
		if ( ! empty( $post_type['rest_base'] ) ) {
			$rest_base = $post_type['rest_base'];
		}

		$rest_controller_class = null;
		if ( ! empty( $post_type['rest_controller_class'] ) ) {
			$rest_controller_class = $post_type['rest_controller_class'];
		}

		$rest_namespace = null;
		if ( ! empty( $post_type['rest_namespace'] ) ) {
			$rest_namespace = $post_type['rest_namespace'];
		}

		$can_export = null;
		if ( ! empty( $post_type['can_export'] ) ) {
			$can_export = self::get_disp_boolean( $post_type['can_export'] );
		}

		$args = [
			'labels'                => $labels,
			'description'           => $post_type['description'],
			'public'                => self::get_disp_boolean( $post_type['public'] ),
			'publicly_queryable'    => $queryable,
			'show_ui'               => self::get_disp_boolean( $post_type['show_ui'] ),
			'show_in_nav_menus'     => self::get_disp_boolean( $post_type['show_in_nav_menus'] ),
			'has_archive'           => $has_archive,
			'show_in_menu'          => $show_in_menu,
			'delete_with_user'      => $delete_with_user,
			'show_in_rest'          => self::get_disp_boolean( $post_type['show_in_rest'] ),
			'rest_base'             => $rest_base,
			'rest_controller_class' => $rest_controller_class,
			'rest_namespace'        => $rest_namespace,
			'exclude_from_search'   => $exclude_from_search,
			'capability_type'       => $capability_type,
			'map_meta_cap'          => $post_type['map_meta_cap'],
			'hierarchical'          => self::get_disp_boolean( $post_type['hierarchical'] ),
			'can_export'            => $can_export,
			'rewrite'               => $rewrite,
			'menu_position'         => $menu_position,
			'menu_icon'             => $menu_icon,
			'register_meta_box_cb'  => $register_meta_box_cb,
			'query_var'             => $post_type['query_var'],
			'supports'              => $post_type['supports'],
			'taxonomies'            => $post_type['taxonomies'],
		];

		if ( true === $yarpp ) {
			$args['yarpp_support'] = $yarpp;
		}

		return apply_filters( 'cptui_pre_register_post_type', $args, $post_type['name'], $post_type );
	}

	/**
	 * The arguments CPT UI passes to register_taxonomy() for an entry of the cptui_taxonomies option.
	 *
	 * @param array<string, mixed> $taxonomy
	 * @return array<string, mixed>
	 */
	public static function taxonomy_args( array $taxonomy = [] ): array {

		$labels = [
			'name'          => $taxonomy['label'],
			'singular_name' => $taxonomy['singular_label'],
		];

		$description = '';
		if ( ! empty( $taxonomy['description'] ) ) {
			$description = $taxonomy['description'];
		}

		$preserved        = self::preserved_keys( 'taxonomies' );
		$preserved_labels = self::preserved_labels();
		foreach ( $taxonomy['labels'] as $key => $label ) {

			if ( ! empty( $label ) ) {
				$labels[ $key ] = $label;
			} elseif ( in_array( $key, $preserved, true ) ) {
				$singular_or_plural = ( in_array( $key, array_keys( $preserved_labels['taxonomies']['plural'] ) ) ) ? 'plural' : 'singular';
				$label_plurality    = ( 'plural' === $singular_or_plural ) ? $taxonomy['label'] : $taxonomy['singular_label'];
				$labels[ $key ]     = sprintf( $preserved_labels['taxonomies'][ $singular_or_plural ][ $key ], $label_plurality );
			}
		}

		$rewrite = self::get_disp_boolean( $taxonomy['rewrite'] );
		if ( false !== self::get_disp_boolean( $taxonomy['rewrite'] ) ) {
			$rewrite               = [];
			$rewrite['slug']       = ! empty( $taxonomy['rewrite_slug'] ) ? $taxonomy['rewrite_slug'] : $taxonomy['name'];
			$rewrite['with_front'] = true;
			if ( isset( $taxonomy['rewrite_withfront'] ) ) {
				$rewrite['with_front'] = ( 'false' === self::disp_boolean( $taxonomy['rewrite_withfront'] ) ) ? false : true;
			}
			$rewrite['hierarchical'] = false;
			if ( isset( $taxonomy['rewrite_hierarchical'] ) ) {
				$rewrite['hierarchical'] = ( 'true' === self::disp_boolean( $taxonomy['rewrite_hierarchical'] ) ) ? true : false;
			}
		}

		if ( in_array( $taxonomy['query_var'], [ 'true', 'false', '0', '1' ], true ) ) {
			$taxonomy['query_var'] = self::get_disp_boolean( $taxonomy['query_var'] );
		}
		if ( true === $taxonomy['query_var'] && ! empty( $taxonomy['query_var_slug'] ) ) {
			$taxonomy['query_var'] = $taxonomy['query_var_slug'];
		}

		$public             = ( ! empty( $taxonomy['public'] ) && false === self::get_disp_boolean( $taxonomy['public'] ) ) ? false : true;
		$publicly_queryable = ( ! empty( $taxonomy['publicly_queryable'] ) && false === self::get_disp_boolean( $taxonomy['publicly_queryable'] ) ) ? false : true;
		if ( empty( $taxonomy['publicly_queryable'] ) ) {
			$publicly_queryable = $public;
		}

		$show_admin_column = ( ! empty( $taxonomy['show_admin_column'] ) && false !== self::get_disp_boolean( $taxonomy['show_admin_column'] ) ) ? true : false;

		$show_in_menu = ( ! empty( $taxonomy['show_in_menu'] ) && false !== self::get_disp_boolean( $taxonomy['show_in_menu'] ) ) ? true : false;

		if ( empty( $taxonomy['show_in_menu'] ) ) {
			$show_in_menu = self::get_disp_boolean( $taxonomy['show_ui'] );
		}

		$show_in_nav_menus = ( ! empty( $taxonomy['show_in_nav_menus'] ) && false !== self::get_disp_boolean( $taxonomy['show_in_nav_menus'] ) ) ? true : false;
		if ( empty( $taxonomy['show_in_nav_menus'] ) ) {
			$show_in_nav_menus = $public;
		}

		$show_tagcloud = ( ! empty( $taxonomy['show_tagcloud'] ) && false !== self::get_disp_boolean( $taxonomy['show_tagcloud'] ) ) ? true : false;
		if ( empty( $taxonomy['show_tagcloud'] ) ) {
			$show_tagcloud = self::get_disp_boolean( $taxonomy['show_ui'] );
		}

		$show_in_rest = ( ! empty( $taxonomy['show_in_rest'] ) && false !== self::get_disp_boolean( $taxonomy['show_in_rest'] ) ) ? true : false;

		$show_in_quick_edit = ( ! empty( $taxonomy['show_in_quick_edit'] ) && false !== self::get_disp_boolean( $taxonomy['show_in_quick_edit'] ) ) ? true : false;

		$sort = ( ! empty( $taxonomy['sort'] ) && false !== self::get_disp_boolean( $taxonomy['sort'] ) ) ? true : false;

		$rest_base = null;
		if ( ! empty( $taxonomy['rest_base'] ) ) {
			$rest_base = $taxonomy['rest_base'];
		}

		$rest_controller_class = null;
		if ( ! empty( $taxonomy['rest_controller_class'] ) ) {
			$rest_controller_class = $taxonomy['rest_controller_class'];
		}

		$rest_namespace = null;
		if ( ! empty( $taxonomy['rest_namespace'] ) ) {
			$rest_namespace = $taxonomy['rest_namespace'];
		}

		$meta_box_cb = null;
		if ( ! empty( $taxonomy['meta_box_cb'] ) ) {
			$meta_box_cb = ( false !== self::get_disp_boolean( $taxonomy['meta_box_cb'] ) ) ? $taxonomy['meta_box_cb'] : false;
		}
		$default_term = null;
		if ( ! empty( $taxonomy['default_term'] ) ) {
			$term_parts = explode( ',', $taxonomy['default_term'] );
			if ( ! empty( $term_parts[0] ) ) {
				$default_term['name'] = trim( $term_parts[0] );
			}
			if ( ! empty( $term_parts[1] ) ) {
				$default_term['slug'] = trim( $term_parts[1] );
			}
			if ( ! empty( $term_parts[2] ) ) {
				$default_term['description'] = trim( $term_parts[2] );
			}
		}

		$args = [
			'labels'                => $labels,
			'label'                 => $taxonomy['label'],
			'description'           => $description,
			'public'                => $public,
			'publicly_queryable'    => $publicly_queryable,
			'hierarchical'          => self::get_disp_boolean( $taxonomy['hierarchical'] ),
			'show_ui'               => self::get_disp_boolean( $taxonomy['show_ui'] ),
			'show_in_menu'          => $show_in_menu,
			'show_in_nav_menus'     => $show_in_nav_menus,
			'show_tagcloud'         => $show_tagcloud,
			'query_var'             => $taxonomy['query_var'],
			'rewrite'               => $rewrite,
			'show_admin_column'     => $show_admin_column,
			'show_in_rest'          => $show_in_rest,
			'rest_base'             => $rest_base,
			'rest_controller_class' => $rest_controller_class,
			'rest_namespace'        => $rest_namespace,
			'show_in_quick_edit'    => $show_in_quick_edit,
			'sort'                  => $sort,
			'meta_box_cb'           => $meta_box_cb,
			'default_term'          => $default_term,
		];

		$object_type = ! empty( $taxonomy['object_types'] ) ? $taxonomy['object_types'] : '';

		return apply_filters( 'cptui_pre_register_taxonomy', $args, $taxonomy['name'], $taxonomy, $object_type );
	}

	/**
	 * @return list<string>
	 */
	private static function preserved_keys( string $type = '' ): array {

		$preserved_labels = [
			'post_types' => [
				'add_new_item',
				'edit_item',
				'new_item',
				'view_item',
				'view_items',
				'all_items',
				'search_items',
				'not_found',
				'not_found_in_trash',
			],
			'taxonomies' => [
				'search_items',
				'popular_items',
				'all_items',
				'parent_item',
				'parent_item_colon',
				'edit_item',
				'update_item',
				'add_new_item',
				'new_item_name',
				'separate_items_with_commas',
				'add_or_remove_items',
				'choose_from_most_used',
			],
		];
		return ! empty( $type ) ? $preserved_labels[ $type ] : [];
	}

	/**
	 * @return array<string, array<string, array<string, string>>>
	 */
	private static function preserved_labels(): array {
		return [
			'post_types' => [
				'singular' => [
					'add_new_item'  => esc_html__( 'Add new %s', 'custom-post-type-ui' ),
					'edit_item'     => esc_html__( 'Edit %s', 'custom-post-type-ui' ),
					'new_item'      => esc_html__( 'New %s', 'custom-post-type-ui' ),
					'view_item'     => esc_html__( 'View %s', 'custom-post-type-ui' ),
					'template_name' => esc_html__( 'Single item: %s', 'custom-post-type-ui' ),
				],
				'plural'   => [
					'view_items'         => esc_html__( 'View %s', 'custom-post-type-ui' ),
					'all_items'          => esc_html__( 'All %s', 'custom-post-type-ui' ),
					'search_items'       => esc_html__( 'Search %s', 'custom-post-type-ui' ),
					'not_found'          => esc_html__( 'No %s found.', 'custom-post-type-ui' ),
					'not_found_in_trash' => esc_html__( 'No %s found in trash.', 'custom-post-type-ui' ),
				],
			],
			'taxonomies' => [
				'singular' => [
					'parent_item'       => esc_html__( 'Parent %s', 'custom-post-type-ui' ),
					'parent_item_colon' => esc_html__( 'Parent %s:', 'custom-post-type-ui' ),
					'edit_item'         => esc_html__( 'Edit %s', 'custom-post-type-ui' ),
					'update_item'       => esc_html__( 'Update %s', 'custom-post-type-ui' ),
					'add_new_item'      => esc_html__( 'Add new %s', 'custom-post-type-ui' ),
					'new_item_name'     => esc_html__( 'New %s name', 'custom-post-type-ui' ),
					'template_name'     => esc_html__( '%s Archives', 'custom-post-type-ui' ),
				],
				'plural'   => [
					'search_items'               => esc_html__( 'Search %s', 'custom-post-type-ui' ),
					'popular_items'              => esc_html__( 'Popular %s', 'custom-post-type-ui' ),
					'all_items'                  => esc_html__( 'All %s', 'custom-post-type-ui' ),
					'separate_items_with_commas' => esc_html__( 'Separate %s with commas', 'custom-post-type-ui' ),
					'add_or_remove_items'        => esc_html__( 'Add or remove %s', 'custom-post-type-ui' ),
					'choose_from_most_used'      => esc_html__( 'Choose from the most used %s', 'custom-post-type-ui' ),
				],
			],
		];
	}

	private static function get_disp_boolean( mixed $bool_text ): bool {
		$bool_text = (string) $bool_text;
		if ( empty( $bool_text ) || '0' === $bool_text || 'false' === $bool_text ) {
			return false;
		}

		return true;
	}

	private static function disp_boolean( mixed $bool_text ): string {
		$bool_text = (string) $bool_text;
		if ( empty( $bool_text ) || '0' === $bool_text || 'false' === $bool_text ) {
			return 'false';
		}

		return 'true';
	}
}

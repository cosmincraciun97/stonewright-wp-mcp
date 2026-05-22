<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeBuilder;

/**
 * Thin facade over Elementor Theme Builder storage.
 *
 * Templates are `elementor_library` custom posts with two meta fields:
 *   - `_elementor_template_type` — header / footer / single / single-post /
 *     single-page / archive / search-results / error-404 / loop-item.
 *   - `_elementor_conditions`    — array of display rules. Optional at create
 *     time, set later via {@see self::set_conditions()}.
 *
 * `_elementor_data` is initialised to an empty document so Elementor's editor
 * can open the template immediately, and `_elementor_edit_mode` is set to
 * `builder` so the Elementor UI takes over (rather than the classic editor).
 *
 * This facade exists so the Theme Builder abilities can be unit-tested
 * against a single, narrow surface — and so the rest of the plugin never
 * needs to know about the underlying `elementor_library` post type or its
 * meta keys.
 */
final class TemplateStore {

	/**
	 * Every template type Elementor Theme Builder recognises today. New types
	 * may be added by Elementor; if so, extend this list and the matching
	 * input_schema enums in the Theme Builder abilities.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_TYPES = [
		'header',
		'footer',
		'single',
		'single-post',
		'single-page',
		'archive',
		'search-results',
		'error-404',
		'loop-item',
	];

	public static function is_allowed_type( string $type ): bool {
		return in_array( $type, self::ALLOWED_TYPES, true );
	}

	/**
	 * Create an empty Elementor Theme Builder template.
	 *
	 * Returns the new post ID on success, or a WP_Error on failure
	 * (invalid type, or whatever wp_insert_post() refused).
	 */
	public static function create( string $title, string $type ): int|\WP_Error {
		if ( ! self::is_allowed_type( $type ) ) {
			return new \WP_Error(
				'stonewright_invalid_template_type',
				/* translators: %s: rejected template_type value */
				sprintf( __( 'Unsupported template_type: %s.', 'stonewright' ), $type ),
				[ 'status' => 400, 'allowed' => self::ALLOWED_TYPES ]
			);
		}

		$id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, '_elementor_template_type', $type );
		update_post_meta( (int) $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $id, '_elementor_data', wp_json_encode( [] ) );

		return (int) $id;
	}

	/**
	 * Cached-conditions option key used by Elementor Pro / ProElements
	 * theme-builder. Deleting this option forces a lazy re-scan of every
	 * `_elementor_conditions`-bearing post on the next front-end request,
	 * which is what actually wires our template into the page render.
	 */
	private const PRO_CONDITIONS_CACHE_OPTION = 'elementor_pro_theme_builder_conditions';

	/**
	 * Replace the display conditions on a Theme Builder template.
	 *
	 * Stonewright accepts a rich, object-shaped condition array on its API
	 * boundary — `[{type:'include', name:'general'}, {type:'exclude',
	 * name:'archive', sub_name:'category', sub_id:12}, ...]` — and serialises
	 * to the slash-delimited string format Elementor Pro / ProElements
	 * actually persists: `['include/general', 'exclude/archive/category/12']`.
	 *
	 * After writing the meta we delete the
	 * `elementor_pro_theme_builder_conditions` cache option so ProElements'
	 * Conditions_Cache regenerates from the fresh meta on next page load.
	 * Without that bust the page keeps using the stale cache and our new
	 * header / footer never reaches the front end.
	 *
	 * Pass an empty array to clear all conditions.
	 *
	 * @param array<int, array<string, mixed>|string> $conditions
	 */
	public static function set_conditions( int $template_id, array $conditions ): bool {
		$serialised = [];
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				$serialised[] = trim( $condition, '/' );
				continue;
			}
			if ( ! is_array( $condition ) ) {
				continue;
			}
			// Strip internal `_id` field Elementor's admin UI adds.
			unset( $condition['_id'] );
			// Order must be type / name / sub_name / sub_id to match
			// Conditions_Manager::save_conditions in ProElements.
			$ordered = [];
			foreach ( [ 'type', 'name', 'sub_name', 'sub_id' ] as $key ) {
				if ( isset( $condition[ $key ] ) && '' !== (string) $condition[ $key ] ) {
					$ordered[] = (string) $condition[ $key ];
				}
			}
			if ( [] !== $ordered ) {
				$serialised[] = implode( '/', $ordered );
			}
		}

		if ( [] === $serialised ) {
			delete_post_meta( $template_id, '_elementor_conditions' );
		} else {
			update_post_meta( $template_id, '_elementor_conditions', $serialised );
		}

		// Bust the ProElements cached conditions option so the next front-end
		// request re-scans posts and picks up our template.
		delete_option( self::PRO_CONDITIONS_CACHE_OPTION );

		return true;
	}

	/**
	 * Read the `_elementor_template_type` meta off a template post.
	 * Returns an empty string if the post does not exist or has no type set.
	 */
	public static function get_type( int $template_id ): string {
		return (string) get_post_meta( $template_id, '_elementor_template_type', true );
	}
}

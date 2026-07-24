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
		return self::insert( $title, $type, 'publish', '' );
	}

	public static function create_staged( string $title, string $type, string $owner ): int|\WP_Error {
		$owner = sanitize_key( $owner );
		if ( 'loop-item' !== $type || '' === $owner ) {
			return new \WP_Error(
				'stonewright_staged_template_invalid',
				__( 'Staged templates require loop-item type and a transaction owner.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		return self::insert( $title, $type, 'draft', $owner );
	}

	public static function publish_staged( int $template_id, string $owner ): bool|\WP_Error {
		$owned = self::assert_owner( $template_id, $owner );
		if ( $owned instanceof \WP_Error ) {
			return $owned;
		}
		$result = wp_update_post(
			[
				'ID'          => $template_id,
				'post_status' => 'publish',
			],
			true
		);

		return is_wp_error( $result ) ? $result : true;
	}

	public static function finalize_staged( int $template_id, string $owner ): bool|\WP_Error {
		$owned = self::assert_owner( $template_id, $owner );
		if ( $owned instanceof \WP_Error ) {
			return $owned;
		}

		return delete_post_meta( $template_id, '_stonewright_transaction_owner' );
	}

	public static function delete_staged( int $template_id, string $owner ): bool|\WP_Error {
		$owned = self::assert_owner( $template_id, $owner );
		if ( $owned instanceof \WP_Error ) {
			return $owned;
		}

		return false !== wp_delete_post( $template_id, true );
	}

	private static function insert( string $title, string $type, string $status, string $owner ): int|\WP_Error {
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
				'post_status' => $status,
			],
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( (int) $id, '_elementor_template_type', $type );
		update_post_meta( (int) $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $id, '_elementor_data', wp_json_encode( [] ) );
		if ( '' !== $owner ) {
			update_post_meta( (int) $id, '_stonewright_transaction_owner', $owner );
		}

		return (int) $id;
	}

	private static function assert_owner( int $template_id, string $owner ): true|\WP_Error {
		$post  = get_post( $template_id );
		$owner = sanitize_key( $owner );
		if ( ! $post || 'elementor_library' !== (string) $post->post_type ) {
			return new \WP_Error(
				'stonewright_staged_template_not_found',
				__( 'The staged template was not found.', 'stonewright' ),
				[ 'status' => 404, 'template_id' => $template_id ]
			);
		}
		$stored = (string) get_post_meta( $template_id, '_stonewright_transaction_owner', true );
		if ( '' === $owner || '' === $stored || ! hash_equals( $stored, $owner ) ) {
			return new \WP_Error(
				'stonewright_staged_template_owner_mismatch',
				__( 'The staged template belongs to a different transaction.', 'stonewright' ),
				[ 'status' => 409, 'template_id' => $template_id ]
			);
		}

		return true;
	}

	/**
	 * Cached-conditions option key used by Elementor Pro / ProElements
	 * theme-builder. Stonewright primes this cache after direct meta writes so
	 * front-end requests can resolve header/footer templates immediately.
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
	 * After writing the meta we also prime ProElements' cached conditions
	 * option. Without that cache update, a template can exist with correct
	 * meta yet never replace the theme header/footer on the front end.
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

		self::prime_conditions_cache( $template_id, $serialised );

		return true;
	}

	/**
	 * Read the `_elementor_template_type` meta off a template post.
	 * Returns an empty string if the post does not exist or has no type set.
	 */
	public static function get_type( int $template_id ): string {
		return (string) get_post_meta( $template_id, '_elementor_template_type', true );
	}

	/**
	 * ProElements reads Theme Builder display rules from a precomputed option
	 * before it scans post meta. Directly writing `_elementor_conditions` is
	 * therefore not enough on many installs; prime that cache immediately so
	 * header/footer templates take effect on the next request.
	 *
	 * @param array<int, string> $serialised
	 */
	private static function prime_conditions_cache( int $template_id, array $serialised ): void {
		$cache = get_option( self::PRO_CONDITIONS_CACHE_OPTION, [] );
		if ( ! is_array( $cache ) ) {
			$cache = [];
		}

		foreach ( $cache as $location => $entries ) {
			if ( is_array( $entries ) && array_key_exists( $template_id, $entries ) ) {
				unset( $entries[ $template_id ] );
				$cache[ $location ] = $entries;
			}
		}

		if ( [] === $serialised ) {
			update_option( self::PRO_CONDITIONS_CACHE_OPTION, $cache, false );
			return;
		}

		$location = self::location_for_template_type( self::get_type( $template_id ) );
		if ( '' === $location ) {
			delete_option( self::PRO_CONDITIONS_CACHE_OPTION );
			return;
		}

		if ( ! isset( $cache[ $location ] ) || ! is_array( $cache[ $location ] ) ) {
			$cache[ $location ] = [];
		}
		$cache[ $location ][ $template_id ] = $serialised;

		update_option( self::PRO_CONDITIONS_CACHE_OPTION, $cache, false );
	}

	private static function location_for_template_type( string $type ): string {
		return match ( $type ) {
			'header' => 'header',
			'footer' => 'footer',
			'single', 'single-post', 'single-page' => 'single',
			'archive', 'search-results', 'error-404' => 'archive',
			'loop-item' => 'loop',
			default => '',
		};
	}
}

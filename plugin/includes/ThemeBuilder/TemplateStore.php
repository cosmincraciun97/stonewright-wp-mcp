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
	 * Replace the display conditions on a Theme Builder template.
	 *
	 * Conditions are stored as a single meta key (`_elementor_conditions`)
	 * containing an array of rule objects. This setter replaces the whole
	 * array — callers that want to add/remove a single rule should read,
	 * mutate, and write the full list themselves.
	 *
	 * @param array<int, array<string, mixed>> $conditions
	 */
	public static function set_conditions( int $template_id, array $conditions ): bool {
		return (bool) update_post_meta( $template_id, '_elementor_conditions', $conditions );
	}

	/**
	 * Read the `_elementor_template_type` meta off a template post.
	 * Returns an empty string if the post does not exist or has no type set.
	 */
	public static function get_type( int $template_id ): string {
		return (string) get_post_meta( $template_id, '_elementor_template_type', true );
	}
}

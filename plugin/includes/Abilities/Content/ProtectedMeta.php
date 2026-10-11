<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Writes the few protected (underscore) meta keys that content abilities set on purpose.
 *
 * Core denies the generic meta capability for a key that starts with an underscore, so a template or an
 * Elementor copy would lose its data if it went through that gate. These keys are written directly, after
 * the caller has been checked for edit access to the post itself, and only from this fixed list.
 */
final class ProtectedMeta {

	/** Keys a page template or a duplicate may set. Nothing else that starts with an underscore is written. */
	public const ALLOWLIST = [
		'_elementor_data',
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_page_settings',
		'_wp_page_template',
		'_thumbnail_id',
	];

	public static function is_allowed( string $key ): bool {
		return in_array( $key, self::ALLOWLIST, true );
	}

	/**
	 * Sets one allowlisted key to one value.
	 *
	 * @return bool False when the key is not on the list or the user cannot edit the post; nothing was written.
	 */
	public static function set( int $post_id, string $key, mixed $value ): bool {
		if ( ! self::is_allowed( $key ) || ! Permissions::can_edit_post( $post_id ) ) {
			return false;
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		return true;
	}

	/**
	 * Adds one value of an allowlisted key, for a copy that keeps every value of a key.
	 *
	 * @return bool False when the key is not on the list or the user cannot edit the post; nothing was written.
	 */
	public static function add( int $post_id, string $key, mixed $value ): bool {
		if ( ! self::is_allowed( $key ) || ! Permissions::can_edit_post( $post_id ) ) {
			return false;
		}
		add_post_meta( $post_id, $key, wp_slash( $value ) );
		return true;
	}
}

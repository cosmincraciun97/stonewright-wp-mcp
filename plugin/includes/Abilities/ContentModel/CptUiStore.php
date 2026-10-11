<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

/**
 * The CPT UI-compatible option entries that the content model abilities keep (cptui_post_types and
 * cptui_taxonomies).
 *
 * Stonewright only writes the entries. It does not register post types from them on later requests: the
 * CPT UI plugin does that when it is active, and without it a registered type lives for the current request.
 */
final class CptUiStore {

	public const POST_TYPES = 'cptui_post_types';

	public const TAXONOMIES = 'cptui_taxonomies';

	/**
	 * Whether the CPT UI plugin is active, which is what makes a stored entry register again on later requests.
	 */
	public static function is_active(): bool {
		if ( array_key_exists( 'stonewright_test_cptui_active', $GLOBALS ) ) {
			return (bool) $GLOBALS['stonewright_test_cptui_active'];
		}
		return defined( 'CPT_VERSION' );
	}

	public static function persistence_note(): string {
		return self::is_active()
			? __( 'The entry is stored in the CPT UI option, and the active CPT UI plugin registers it on every request.', 'stonewright' )
			: __( 'The entry is stored in the CPT UI option, but the CPT UI plugin is not active, so nothing registers it on later requests. It exists for this request only until CPT UI or equivalent code registers it.', 'stonewright' );
	}

	/**
	 * @return array<string, mixed>|null The stored entry of a slug, or null when there is none.
	 */
	public static function existing( string $option, string $slug ): ?array {
		$config = get_option( $option, [] );
		return is_array( $config ) && isset( $config[ $slug ] ) && is_array( $config[ $slug ] ) ? $config[ $slug ] : null;
	}

	/**
	 * Merges a payload into the stored entry of a slug, keeping the keys the payload does not name (a CPT UI
	 * entry holds many more settings than Stonewright sets), and reads the option back.
	 *
	 * @param array<string, mixed> $payload
	 * @return bool True when the option holds the merged entry afterwards.
	 */
	public static function save( string $option, string $slug, array $payload ): bool {
		$config = get_option( $option, [] );
		$config = is_array( $config ) ? $config : [];
		$config[ $slug ] = array_replace( isset( $config[ $slug ] ) && is_array( $config[ $slug ] ) ? $config[ $slug ] : [], $payload );
		update_option( $option, $config, false );

		$stored = self::existing( $option, $slug );
		return null !== $stored && array_replace( $stored, $payload ) === $stored;
	}

	/**
	 * A CPT UI option value as a boolean: CPT UI stores true and false as the strings "true" and "false".
	 */
	public static function to_bool( mixed $value, bool $default ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== $value ) {
			return ! in_array( strtolower( $value ), [ 'false', '0', 'no' ], true );
		}
		return null === $value || '' === $value ? $default : (bool) $value;
	}
}

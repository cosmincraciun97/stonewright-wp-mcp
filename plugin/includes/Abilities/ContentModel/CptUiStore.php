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
	 * entry holds many more settings than Stonewright sets), fills in the settings the entry still lacks, and
	 * reads the option back.
	 *
	 * CPT UI reads most settings of an entry without checking that they exist, so an entry that lacks one logs
	 * PHP warnings on every request, and the missing setting counts as false: a type registered as public and
	 * visible came back hidden from wp-admin and without permalinks. The filled values are what WordPress
	 * makes of the arguments the abilities register in their own request.
	 *
	 * @param array<string, mixed> $payload
	 * @return bool True when the option holds the merged entry afterwards.
	 */
	public static function save( string $option, string $slug, array $payload ): bool {
		$config = get_option( $option, [] );
		$config = is_array( $config ) ? $config : [];
		$entry  = array_replace( isset( $config[ $slug ] ) && is_array( $config[ $slug ] ) ? $config[ $slug ] : [], $payload );
		$config[ $slug ] = $entry + ( self::TAXONOMIES === $option ? self::taxonomy_defaults( $entry ) : self::post_type_defaults( $entry ) );
		update_option( $option, $config, false );

		$stored = self::existing( $option, $slug );
		return null !== $stored && array_replace( $stored, $payload ) === $stored;
	}

	/**
	 * Every setting CPT UI reads from a post type entry, in its formats ("true" and "false" strings), with the
	 * values WordPress gives a type registered with nothing but the entry's public flag. Where WordPress
	 * derives one setting from another (the menu from the UI, the UI from public), the entry's own value of
	 * the source setting counts.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function post_type_defaults( array $entry ): array {
		$public  = self::to_bool( $entry['public'] ?? null, true );
		$show_ui = self::to_bool( $entry['show_ui'] ?? null, $public );

		return [
			'description'           => '',
			'public'                => self::to_string( $public ),
			'publicly_queryable'    => self::to_string( $public ),
			'show_ui'               => self::to_string( $show_ui ),
			'show_in_nav_menus'     => self::to_string( $public ),
			'delete_with_user'      => '',
			'show_in_rest'          => 'true',
			'rest_base'             => '',
			'rest_controller_class' => '',
			'rest_namespace'        => '',
			'has_archive'           => 'false',
			'has_archive_string'    => '',
			'exclude_from_search'   => self::to_string( ! $public ),
			'capability_type'       => 'post',
			'hierarchical'          => 'false',
			'can_export'            => 'true',
			'rewrite'               => 'true',
			'rewrite_slug'          => '',
			'rewrite_withfront'     => 'true',
			'query_var'             => 'true',
			'query_var_slug'        => '',
			'menu_position'         => '',
			'show_in_menu'          => self::to_string( $show_ui ),
			'show_in_menu_string'   => '',
			'menu_icon'             => '',
			'register_meta_box_cb'  => '',
			'supports'              => [ 'title', 'editor' ],
			'taxonomies'            => [],
			'labels'                => [],
			'custom_supports'       => '',
			'enter_title_here'      => '',
		];
	}

	/**
	 * Every setting CPT UI reads from a taxonomy entry, in its formats, with the values WordPress gives a
	 * taxonomy registered with nothing but the entry's public flag, deriving as WordPress does.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function taxonomy_defaults( array $entry ): array {
		$public  = self::to_bool( $entry['public'] ?? null, true );
		$show_ui = self::to_bool( $entry['show_ui'] ?? null, $public );

		return [
			'description'           => '',
			'public'                => self::to_string( $public ),
			'publicly_queryable'    => self::to_string( $public ),
			'hierarchical'          => 'false',
			'show_ui'               => self::to_string( $show_ui ),
			'show_in_menu'          => self::to_string( $show_ui ),
			'show_in_nav_menus'     => self::to_string( $public ),
			'query_var'             => 'true',
			'query_var_slug'        => '',
			'rewrite'               => 'true',
			'rewrite_slug'          => '',
			'rewrite_withfront'     => 'true',
			'rewrite_hierarchical'  => 'false',
			'show_admin_column'     => 'false',
			'show_in_rest'          => 'true',
			'show_tagcloud'         => self::to_string( $show_ui ),
			'sort'                  => 'false',
			'show_in_quick_edit'    => self::to_string( $show_ui ),
			'rest_base'             => '',
			'rest_controller_class' => '',
			'rest_namespace'        => '',
			'labels'                => [],
			'meta_box_cb'           => '',
			'default_term'          => '',
			'object_types'          => [],
		];
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

	/**
	 * A boolean as CPT UI stores it.
	 */
	private static function to_string( bool $value ): string {
		return $value ? 'true' : 'false';
	}
}

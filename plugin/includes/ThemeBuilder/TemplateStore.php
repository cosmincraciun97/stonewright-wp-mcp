<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeBuilder;

use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\OptionLock;

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

	private static ?object $pro_cache_override = null;

	/** Stands in for Elementor Pro's conditions cache (an object with regenerate()); null goes back to the runtime lookup. For tests. */
	public static function use_pro_conditions_cache( ?object $cache ): void {
		self::$pro_cache_override = $cache;
	}

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

	/**
	 * Removes a template the current call created and could not finish. Nothing else refers to it yet, and
	 * a call that fails must not leave an empty template published, so it is deleted for good.
	 */
	public static function discard( int $template_id ): bool {
		return false !== wp_delete_post( $template_id, true );
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
			wp_slash(
				[
					'post_title'  => $title,
					'post_type'   => 'elementor_library',
					'post_status' => $status,
				]
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		RescueGuard::note_post_created( (int) $id );

		update_post_meta( (int) $id, '_elementor_template_type', $type );
		update_post_meta( (int) $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $id, '_elementor_data', wp_json_encode( [] ) );
		if ( '' !== $owner ) {
			update_post_meta( (int) $id, '_stonewright_transaction_owner', $owner );
		}

		return (int) $id;
	}

	private static function assert_owner( int $template_id, string $owner ): bool|\WP_Error {
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
	 * Option where Elementor Pro / ProElements keeps its list of display rules, `[ location => [ template_id =>
	 * [ 'include/general', ... ] ] ]`. Elementor Pro reads it before it scans post meta, so a template whose meta is
	 * right but which is missing from the list does not apply on the front end. With Elementor Pro loaded it rebuilds
	 * the list itself; without it Stonewright changes only the entry of the template it wrote.
	 */
	private const PRO_CONDITIONS_CACHE_OPTION = 'elementor_pro_theme_builder_conditions';

	private const CONDITIONS_LOCK         = 'theme_builder_conditions';
	private const CONDITIONS_LOCK_SECONDS = 30;
	private const CONDITIONS_LOCK_WAIT_MS = 2000;

	/**
	 * Replace the display conditions on a Theme Builder template.
	 *
	 * Stonewright accepts a rich, object-shaped condition array on its API
	 * boundary — `[{type:'include', name:'general'}, {type:'exclude',
	 * name:'archive', sub_name:'category', sub_id:12}, ...]` — and serialises
	 * to the slash-delimited string format Elementor Pro / ProElements
	 * actually persists: `['include/general', 'exclude/archive/category/12']`.
	 *
	 * After writing the meta the list of display rules that Elementor Pro keeps in an option is refreshed. With
	 * Elementor Pro loaded, Elementor Pro rebuilds it from the stored rules of every template (what its own editor
	 * does); without it, only this template's entry is changed. Calls take turns on a lock, so two of them do not
	 * drop each other's entries.
	 *
	 * Pass an empty array to clear all conditions.
	 *
	 * Returns true when the stored rules, read back, are the rules that were asked for and the list was refreshed;
	 * false when the stored rules differ from the request; a WP_Error when nothing was written (the location of the
	 * template type is unknown, or another call holds the lock) or when the rules were stored but the list could not
	 * be refreshed.
	 *
	 * @param array<int, array<string, mixed>|string> $conditions
	 * @return true|false|\WP_Error
	 */
	public static function set_conditions( int $template_id, array $conditions ): bool|\WP_Error {
		$serialised = [];
		foreach ( $conditions as $condition ) {
			$rule = self::serialise_condition( $condition );
			if ( '' !== $rule ) {
				$serialised[] = $rule;
			}
		}

		$pro_cache = self::pro_conditions_cache();
		$location  = '';
		if ( null === $pro_cache && [] !== $serialised ) {
			$type     = self::get_type( $template_id );
			$resolved = self::location_for_template_type( $type );
			if ( null === $resolved ) {
				return new \WP_Error(
					'stonewright_theme_builder_location_unknown',
					sprintf(
						/* translators: %s: Elementor template type */
						__( 'The display rules were not stored: Elementor Pro\'s Theme Builder is not loaded and Stonewright does not know the location of a "%s" template, so it cannot update Elementor Pro\'s list of display rules safely.', 'stonewright' ),
						$type
					),
					[ 'status' => 409, 'template_id' => $template_id, 'template_type' => $type ]
				);
			}
			$location = $resolved;
		}

		if ( ! self::take_conditions_lock() ) {
			return new \WP_Error(
				'stonewright_theme_builder_conditions_busy',
				__( 'Another call is changing Theme Builder display rules, so these rules were not stored.', 'stonewright' ),
				[ 'status' => 409, 'retryable' => true, 'retry_after' => 1 ]
			);
		}

		try {
			if ( [] === $serialised ) {
				delete_post_meta( $template_id, '_elementor_conditions' );
			} else {
				update_post_meta( $template_id, '_elementor_conditions', $serialised );
			}

			$refreshed = null !== $pro_cache
				? self::rebuild_pro_cache( $pro_cache )
				: self::update_cached_rules( $template_id, $serialised, $location );
		} finally {
			OptionLock::release( self::CONDITIONS_LOCK );
		}

		if ( self::get_conditions( $template_id ) !== $serialised ) {
			return false;
		}

		return $refreshed instanceof \WP_Error ? $refreshed : true;
	}

	/**
	 * The stored display rules of a template in their stored form (`include/general`), empty when it has none.
	 *
	 * @return array<int, string>
	 */
	public static function get_conditions( int $template_id ): array {
		$stored = get_post_meta( $template_id, '_elementor_conditions', true );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$rules = [];
		foreach ( $stored as $condition ) {
			$rule = self::serialise_condition( $condition );
			if ( '' !== $rule ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * One display rule in the stored string form, empty when it is not a rule.
	 */
	private static function serialise_condition( mixed $condition ): string {
		if ( is_string( $condition ) ) {
			return trim( $condition, '/' );
		}
		if ( ! is_array( $condition ) ) {
			return '';
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
		return implode( '/', $ordered );
	}

	/**
	 * Read the `_elementor_template_type` meta off a template post.
	 * Returns an empty string if the post does not exist or has no type set.
	 */
	public static function get_type( int $template_id ): string {
		return (string) get_post_meta( $template_id, '_elementor_template_type', true );
	}

	/**
	 * Waits up to two seconds for the lock that makes calls change the list of display rules one at a time.
	 */
	private static function take_conditions_lock(): bool {
		$deadline = microtime( true ) + self::CONDITIONS_LOCK_WAIT_MS / 1000;
		do {
			if ( OptionLock::acquire( self::CONDITIONS_LOCK, self::CONDITIONS_LOCK_SECONDS ) ) {
				return true;
			}
			usleep( 50000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Has Elementor Pro rebuild its list of display rules from the stored rules of every template.
	 */
	private static function rebuild_pro_cache( object $pro_cache ): ?\WP_Error {
		try {
			$pro_cache->regenerate();
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'stonewright_theme_builder_cache_rebuild_failed',
				sprintf(
					/* translators: %s: error message from Elementor Pro */
					__( 'The display rules are stored, but Elementor Pro could not rebuild its list of display rules: %s. Saving any template\'s conditions in the Elementor editor, or running wp elementor-pro theme-builder clear-conditions, rebuilds it.', 'stonewright' ),
					rtrim( $e->getMessage(), '.' )
				),
				[ 'status' => 500 ]
			);
		}

		return null;
	}

	/**
	 * Changes only this template's entry in Elementor Pro's list of display rules. Used when Elementor Pro is not
	 * loaded, so it cannot rebuild the list. Other templates' entries are kept, and a value that is not a list is
	 * left as it is.
	 *
	 * @param array<int, string> $serialised
	 */
	private static function update_cached_rules( int $template_id, array $serialised, string $location ): ?\WP_Error {
		$cache = get_option( self::PRO_CONDITIONS_CACHE_OPTION, [] );
		if ( false === $cache || '' === $cache || null === $cache ) {
			$cache = [];
		}
		if ( ! is_array( $cache ) ) {
			return self::cache_unreadable_error();
		}

		$updated = $cache;
		foreach ( $updated as $key => $entries ) {
			if ( is_array( $entries ) && array_key_exists( $template_id, $entries ) ) {
				unset( $entries[ $template_id ] );
				$updated[ $key ] = $entries;
			}
		}

		if ( [] !== $serialised && '' !== $location ) {
			if ( isset( $updated[ $location ] ) && ! is_array( $updated[ $location ] ) ) {
				return self::cache_unreadable_error();
			}
			$updated[ $location ][ $template_id ] = $serialised;
		}

		// No autoload argument: Elementor Pro's own save leaves it at the default too.
		if ( $updated !== $cache ) {
			update_option( self::PRO_CONDITIONS_CACHE_OPTION, $updated );
		}

		return null;
	}

	private static function cache_unreadable_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_theme_builder_cache_unreadable',
			__( 'The display rules are stored, but Elementor Pro\'s list of display rules holds a value Stonewright cannot read, so it was left as it is.', 'stonewright' ),
			[ 'status' => 500 ]
		);
	}

	/**
	 * Where Elementor Pro lists a template type, mirroring the `location` of its document classes. An empty string
	 * means Elementor Pro never lists the type; null means Stonewright does not know the type.
	 */
	private static function location_for_template_type( string $type ): ?string {
		return match ( $type ) {
			'header' => 'header',
			'footer' => 'footer',
			'single', 'single-post', 'single-page', 'error-404', 'product' => 'single',
			'archive', 'search-results', 'product-archive' => 'archive',
			'popup' => 'popup',
			'loop-item' => '',
			default => null,
		};
	}

	/**
	 * Elementor Pro's conditions cache object (the one its own editor save calls regenerate() on), or null when
	 * Elementor Pro's Theme Builder is not loaded or any step of the lookup is missing.
	 */
	private static function pro_conditions_cache(): ?object {
		if ( null !== self::$pro_cache_override ) {
			return self::$pro_cache_override;
		}

		$plugin_class = 'ElementorPro\\Plugin';
		if ( ! class_exists( $plugin_class, false ) || ! is_callable( [ $plugin_class, 'instance' ] ) ) {
			return null;
		}
		$plugin = $plugin_class::instance();
		if ( ! is_object( $plugin ) || ! isset( $plugin->modules_manager ) || ! is_object( $plugin->modules_manager ) || ! method_exists( $plugin->modules_manager, 'get_modules' ) ) {
			return null;
		}
		$module = $plugin->modules_manager->get_modules( 'theme-builder' );
		if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return null;
		}
		$manager = $module->get_conditions_manager();
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_cache' ) ) {
			return null;
		}
		$cache = $manager->get_cache();

		return is_object( $cache ) && method_exists( $cache, 'regenerate' ) ? $cache : null;
	}
}

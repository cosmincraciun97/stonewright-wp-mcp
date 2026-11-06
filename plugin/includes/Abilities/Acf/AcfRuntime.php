<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Security\RescueGuard;

/**
 * ACF availability, field-key resolution, raw readback, and typed comparators.
 */
final class AcfRuntime {

	private const COMPARABLE_TYPES = [
		'text'              => true,
		'textarea'          => true,
		'number'            => true,
		'range'             => true,
		'email'             => true,
		'url'               => true,
		'password'          => true,
		'wysiwyg'           => true,
		'oembed'            => true,
		'select'            => true,
		'checkbox'          => true,
		'radio'             => true,
		'button_group'      => true,
		'true_false'        => true,
		'image'             => true,
		'file'              => true,
		'gallery'           => true,
		'post_object'       => true,
		'page_link'         => true,
		'relationship'      => true,
		'user'              => true,
		'taxonomy'          => true,
		'date_picker'       => true,
		'date_time_picker'  => true,
		'time_picker'       => true,
		'color_picker'      => true,
		'link'              => true,
		'group'             => true,
		'repeater'          => true,
		'flexible_content'  => true,
		'google_map'        => true,
	];

	private const REFERENCE_TYPES = [
		'image'        => 'post',
		'file'         => 'post',
		'gallery'      => 'post',
		'post_object'  => 'post',
		'relationship' => 'post',
		'page_link'    => 'post',
		'user'         => 'user',
	];

	private const ID_MAP_KEYS = [ 'ID', 'id', 'attachment_id' ];

	private const DECIMAL_MAX_DIGITS = 80;

	public static function is_active(): bool {
		// Unit tests toggle this without unloading function stubs.
		if ( array_key_exists( 'stonewright_test_acf_active', $GLOBALS ) ) {
			return (bool) $GLOBALS['stonewright_test_acf_active'];
		}
		return function_exists( 'get_fields' )
			|| function_exists( 'acf_get_field_groups' )
			|| class_exists( 'ACF', false );
	}

	/**
	 * Whether ACF can import a field group, which is the call that saves the group and its fields.
	 */
	public static function can_import_field_groups(): bool {
		// Unit tests toggle this without unloading function stubs.
		if ( array_key_exists( 'stonewright_test_acf_import_available', $GLOBALS ) ) {
			return (bool) $GLOBALS['stonewright_test_acf_import_available'];
		}
		return function_exists( 'acf_import_field_group' );
	}

	/**
	 * The posts that hold a field group key, and whether ACF also keeps the group outside the posts table.
	 *
	 * The posts are queried by post_name: acf_get_field_group() is not used here, because it answers with
	 * the local copy (ID 0) when a JSON or PHP copy of the group exists. `local` is '' when the group has no
	 * local copy, 'json' when ACF loads it from a JSON file, and 'php' when code registers it.
	 *
	 * @return array{ids: list<int>, local: string} Post ids ascending, at most ten.
	 */
	public static function group_posts_by_key( string $key ): array {
		$out = [ 'ids' => [], 'local' => '' ];
		if ( '' === $key ) {
			return $out;
		}
		if ( function_exists( 'get_posts' ) ) {
			$ids = get_posts(
				[
					'post_type'              => 'acf-field-group',
					'name'                   => $key,
					'post_status'            => [ 'publish', 'acf-disabled' ],
					'posts_per_page'         => 10,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
			$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id > 0 ) ) );
			sort( $ids );
			$out['ids'] = $ids;
		}
		if ( function_exists( 'acf_get_local_field_group' ) ) {
			$local = acf_get_local_field_group( $key );
			if ( is_array( $local ) && [] !== $local ) {
				// A local group without the marker is one that code registered: acf_add_local_field_group() defaults it to php.
				$out['local'] = 'json' === (string) ( $local['local'] ?? 'php' ) ? 'json' : 'php';
			}
		}
		return $out;
	}

	/**
	 * The field posts of a group, nested ones included, as field key to post id.
	 *
	 * Fields hang off their parent by post_parent: the group for a top-level field, the field for a sub field
	 * of a repeater, group, or flexible content layout. The statuses are the ones acf_get_raw_fields() reads,
	 * so the map holds exactly the posts ACF's importer sees.
	 *
	 * @return array<string, int>
	 */
	public static function field_posts_of_group( int $group_id ): array {
		$map = [];
		if ( $group_id < 1 || ! function_exists( 'get_posts' ) ) {
			return $map;
		}
		self::collect_field_posts( $group_id, $map, 0 );
		return $map;
	}

	/**
	 * @param array<string, int> $map
	 */
	private static function collect_field_posts( int $parent_id, array &$map, int $depth ): void {
		if ( $depth > 10 ) {
			return;
		}
		$posts = get_posts(
			[
				'post_type'              => 'acf-field',
				'post_parent'            => $parent_id,
				'post_status'            => [ 'publish', 'trash' ],
				'posts_per_page'         => -1,
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		foreach ( (array) $posts as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}
			$id  = (int) $post->ID;
			$key = (string) $post->post_name;
			if ( $id < 1 || '' === $key || isset( $map[ $key ] ) ) {
				continue;
			}
			$map[ $key ] = $id;
			self::collect_field_posts( $id, $map, $depth + 1 );
		}
	}

	/**
	 * The payload with the post ids of the group and of every field whose key the group holds, nested ones
	 * included. A field the group does not hold keeps the id it came with, if any.
	 *
	 * @param array<string, mixed> $group
	 * @return array<string, mixed>
	 */
	public static function with_post_ids( array $group, int $post_id ): array {
		if ( $post_id < 1 ) {
			return $group;
		}
		$group['ID'] = $post_id;
		if ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) {
			$group['fields'] = self::fields_with_post_ids( $group['fields'], self::field_posts_of_group( $post_id ) );
		}
		return $group;
	}

	/**
	 * Field arrays with `ID` filled in by key from a map, recursively through sub fields and layouts, where the
	 * field has no id of its own.
	 *
	 * @param mixed              $fields
	 * @param array<string, int> $map
	 * @return mixed
	 */
	public static function fields_with_post_ids( mixed $fields, array $map, int $depth = 0 ): mixed {
		if ( ! is_array( $fields ) || $depth > 10 ) {
			return $fields;
		}
		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = (string) ( $field['key'] ?? '' );
			if ( (int) ( $field['ID'] ?? 0 ) < 1 && '' !== $key && isset( $map[ $key ] ) ) {
				$field['ID'] = $map[ $key ];
			}
			if ( isset( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::fields_with_post_ids( $field['sub_fields'], $map, $depth + 1 );
			}
			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $name => $layout ) {
					if ( is_array( $layout ) && isset( $layout['sub_fields'] ) ) {
						$field['layouts'][ $name ]['sub_fields'] = self::fields_with_post_ids( $layout['sub_fields'], $map, $depth + 1 );
					}
				}
			}
			$fields[ $index ] = $field;
		}
		return $fields;
	}

	/**
	 * The fields of a payload or of acf_get_fields() as one flat list, nested ones included: each entry is
	 * the field's key and id, in the order ACF's importer walks them.
	 *
	 * @param mixed $fields
	 * @return list<array{key: string, ID: int}>
	 */
	public static function flatten_fields( mixed $fields, int $depth = 0 ): array {
		$out = [];
		if ( ! is_array( $fields ) || $depth > 10 ) {
			return $out;
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$out[] = [ 'key' => (string) ( $field['key'] ?? '' ), 'ID' => (int) ( $field['ID'] ?? 0 ) ];
			foreach ( self::flatten_fields( $field['sub_fields'] ?? null, $depth + 1 ) as $sub ) {
				$out[] = $sub;
			}
			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				foreach ( self::flatten_fields( is_array( $layout ) ? ( $layout['sub_fields'] ?? null ) : null, $depth + 1 ) as $sub ) {
					$out[] = $sub;
				}
			}
		}
		return $out;
	}

	/**
	 * The field with a key, searched through sub fields and layouts.
	 *
	 * @param mixed $fields
	 * @return array<string, mixed>|null
	 */
	public static function find_field( mixed $fields, string $key, int $depth = 0 ): ?array {
		if ( ! is_array( $fields ) || '' === $key || $depth > 10 ) {
			return null;
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( $key === (string) ( $field['key'] ?? '' ) ) {
				return $field;
			}
			$found = self::find_field( $field['sub_fields'] ?? null, $key, $depth + 1 );
			if ( null !== $found ) {
				return $found;
			}
			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				$found = self::find_field( is_array( $layout ) ? ( $layout['sub_fields'] ?? null ) : null, $key, $depth + 1 );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Saves a field group and its fields through acf_import_field_group(), as an upsert by key.
	 *
	 * ACF's importer updates a group in place only when the payload carries the post id of the group; with
	 * no id it inserts a second group and a second copy of every field. So the group is resolved by key
	 * first and its id set, a payload id that belongs to another group or field is refused, and a field the
	 * database holds that the payload leaves out is refused unless `allow_field_removal` is set, because ACF
	 * deletes it for good. Every refusal happens before anything is written.
	 *
	 * ACF keeps a group and each of its fields as posts. The posts that exist are recorded in the change
	 * ledger before the import, and every post the import inserts is recorded after it, so the save can be
	 * traced and undone.
	 *
	 * @param array<string, mixed> $group   A field group with its key and its fields; `ID` optional.
	 * @param array<string, mixed> $options `allow_field_removal` (bool) lets the import delete fields.
	 * @return array<string, mixed>|\WP_Error The saved group with `post_id`, `created`, `fields_removed`,
	 *                                        `fields_created`, `created_post_ids`, `duplicate_post_ids` and
	 *                                        `local`, or an error when the save was refused or ACF did not
	 *                                        store the group.
	 */
	public static function import_field_group( array $group, array $options = [] ): array|\WP_Error {
		if ( ! self::can_import_field_groups() || ! function_exists( 'acf_import_field_group' ) ) {
			return new \WP_Error(
				'stonewright_acf_import_unavailable',
				__( 'This ACF version cannot import a field group, so nothing was saved.', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}
		$key = (string) ( $group['key'] ?? '' );
		if ( '' === $key ) {
			return new \WP_Error( 'stonewright_acf_group_key_required', 'group.key is required.', [ 'status' => 400 ] );
		}

		// 1. Resolve the group to its post, so ACF updates it in place.
		$resolved   = self::group_posts_by_key( $key );
		$ids        = $resolved['ids'];
		$local      = $resolved['local'];
		$given_id   = (int) ( $group['ID'] ?? 0 );
		$post_id    = 0;
		$duplicates = [];
		if ( count( $ids ) >= 2 ) {
			if ( $given_id > 0 && in_array( $given_id, $ids, true ) ) {
				$post_id    = $given_id;
				$duplicates = array_values( array_diff( $ids, [ $given_id ] ) );
			} else {
				return new \WP_Error(
					'stonewright_acf_group_key_ambiguous',
					__( 'More than one ACF field group post holds this key, so the save cannot tell which one to update. Nothing was written.', 'stonewright' ),
					[ 'status' => 409, 'key' => $key, 'post_ids' => $ids ]
				);
			}
		} elseif ( 1 === count( $ids ) ) {
			if ( $given_id > 0 && $given_id !== $ids[0] ) {
				return self::group_id_mismatch( $key, $given_id, $ids );
			}
			$post_id = $ids[0];
		} else {
			if ( $given_id > 0 ) {
				return self::group_id_mismatch( $key, $given_id, $ids );
			}
			if ( 'php' === $local ) {
				return new \WP_Error(
					'stonewright_acf_group_registered_in_code',
					__( 'This ACF field group is registered in PHP code, so a save cannot change it. Nothing was written.', 'stonewright' ),
					[ 'status' => 409, 'key' => $key ]
				);
			}
		}
		$group['ID'] = $post_id;

		// 2. A field id must belong to that field of this group; a field without one is ACF's to resolve by key.
		$db_fields      = self::field_posts_of_group( $post_id );
		$payload_fields = self::flatten_fields( $group['fields'] ?? null );
		foreach ( $payload_fields as $field ) {
			if ( $field['ID'] > 0 && ( $db_fields[ $field['key'] ] ?? 0 ) !== $field['ID'] ) {
				return new \WP_Error(
					'stonewright_acf_field_id_mismatch',
					__( 'A field in the payload carries a post id that does not belong to that field key in this group. Nothing was written.', 'stonewright' ),
					[ 'status' => 409, 'key' => $key, 'field_key' => $field['key'], 'post_id' => $field['ID'] ]
				);
			}
		}

		// 3. The fields ACF would delete: those the database holds and the payload leaves out.
		$payload_keys = array_values( array_unique( array_filter( array_column( $payload_fields, 'key' ), static fn( string $k ): bool => '' !== $k ) ) );
		$removed      = array_values( array_diff( array_keys( $db_fields ), $payload_keys ) );
		if ( [] !== $removed && true !== ( $options['allow_field_removal'] ?? false ) ) {
			return new \WP_Error(
				'stonewright_acf_fields_would_be_removed',
				__( 'The payload leaves out fields this group holds, and ACF deletes them permanently. Nothing was written.', 'stonewright' ),
				[ 'status' => 409, 'key' => $key, 'field_keys' => $removed ]
			);
		}
		$fields_created = array_values( array_diff( $payload_keys, array_keys( $db_fields ) ) );

		// 4. Record the posts the import will overwrite, and listen for the ones it inserts.
		if ( $post_id > 0 ) {
			RescueGuard::note_post_overwrite( $post_id );
		}
		foreach ( $db_fields as $field_post_id ) {
			RescueGuard::note_post_overwrite( $field_post_id );
		}
		$before = array_values( array_unique( array_merge( $post_id > 0 ? [ $post_id ] : [], array_values( $db_fields ), self::field_group_post_ids( $key ) ) ) );

		$inserted = [];
		$listener = static function ( $inserted_id, $post = null, $update = false ) use ( &$inserted ): void {
			if ( $update ) {
				return;
			}
			$inserted[ (int) $inserted_id ] = (int) $inserted_id;
		};
		add_action( 'wp_insert_post', $listener, 10, 3 );
		try {
			// 5. Import.
			$result = acf_import_field_group( $group );
		} finally {
			remove_action( 'wp_insert_post', $listener, 10 );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'stonewright_acf_import_failed', __( 'ACF did not import the field group.', 'stonewright' ), [ 'status' => 500, 'key' => $key ] );
		}

		// Read the group back: an import that returned a group is not proof that it was stored.
		$saved_id = (int) ( $result['ID'] ?? 0 );
		if ( $post_id > 0 && $saved_id > 0 && $saved_id !== $post_id ) {
			return new \WP_Error(
				'stonewright_acf_import_failed',
				__( 'ACF reported the import under another post than the one that holds the key.', 'stonewright' ),
				[ 'status' => 500, 'key' => $key, 'post_id' => $post_id, 'reported_post_id' => $saved_id ]
			);
		}
		$final_id = $post_id > 0 ? $post_id : $saved_id;
		if ( $final_id > 0 ) {
			$post = get_post( $final_id );
			if ( ! is_object( $post ) || 'acf-field-group' !== (string) $post->post_type || $key !== (string) $post->post_name ) {
				return new \WP_Error(
					'stonewright_acf_import_failed',
					__( 'ACF reported the import, but the field group post could not be read back under its key.', 'stonewright' ),
					[ 'status' => 500, 'key' => $key, 'post_id' => $final_id ]
				);
			}
		}
		$saved = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $key ) : false;
		if ( ! is_array( $saved ) ) {
			// Without a post to read back, ACF's own answer is the only proof of storage, and there is none.
			if ( $final_id < 1 ) {
				return new \WP_Error( 'stonewright_acf_import_failed', __( 'ACF reported the import, but the field group could not be read back.', 'stonewright' ), [ 'status' => 500, 'key' => $key ] );
			}
			$saved = $result;
		}
		if ( $key !== (string) ( $saved['key'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_acf_import_failed', __( 'ACF reported the import, but the field group could not be read back.', 'stonewright' ), [ 'status' => 500, 'key' => $key ] );
		}

		// 6. Record every post the import inserted: the ones the listener saw, plus any the readback shows.
		$created = [];
		foreach ( $inserted as $inserted_id ) {
			$post = get_post( $inserted_id );
			if ( is_object( $post ) && in_array( (string) $post->post_type, [ 'acf-field-group', 'acf-field' ], true ) ) {
				$created[] = $inserted_id;
			}
		}
		$after   = array_merge( $final_id > 0 ? [ $final_id ] : [], array_values( self::field_posts_of_group( $final_id ) ), self::field_group_post_ids( $key ) );
		$created = array_values( array_unique( array_merge( $created, array_values( array_diff( $after, $before ) ) ) ) );
		sort( $created );
		foreach ( $created as $created_id ) {
			RescueGuard::note_post_created( $created_id );
		}

		if ( $final_id > 0 ) {
			$saved['ID'] = $final_id;
		}
		return array_merge(
			$saved,
			[
				'post_id'            => $final_id,
				'created'            => 0 === $post_id,
				'fields_removed'     => $removed,
				'fields_created'     => $fields_created,
				'created_post_ids'   => $created,
				'duplicate_post_ids' => $duplicates,
				'local'              => $local,
			]
		);
	}

	/**
	 * @param list<int> $ids
	 */
	private static function group_id_mismatch( string $key, int $given_id, array $ids ): \WP_Error {
		return new \WP_Error(
			'stonewright_acf_group_id_mismatch',
			__( 'group.ID does not name the post that holds group.key. A save never renames a group key. Nothing was written.', 'stonewright' ),
			[ 'status' => 409, 'key' => $key, 'post_id' => $given_id, 'post_ids' => $ids ]
		);
	}

	/**
	 * The ids of the posts that hold a field group and all its fields, including nested ones, as ACF reports
	 * them. ACF answers with the local copy (id 0) for a group it also keeps in JSON or PHP, so this is the
	 * fallback behind group_posts_by_key() and field_posts_of_group(), not the first source.
	 *
	 * @return list<int>
	 */
	private static function field_group_post_ids( string $key ): array {
		$ids = [];
		if ( '' === $key ) {
			return $ids;
		}
		if ( function_exists( 'acf_get_field_group' ) ) {
			$group = acf_get_field_group( $key );
			if ( is_array( $group ) && (int) ( $group['ID'] ?? 0 ) > 0 ) {
				$ids[] = (int) $group['ID'];
			}
		}
		if ( function_exists( 'acf_get_fields' ) ) {
			self::collect_field_post_ids( acf_get_fields( $key ), $ids );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param mixed      $fields
	 * @param list<int> $ids
	 */
	private static function collect_field_post_ids( mixed $fields, array &$ids ): void {
		if ( ! is_array( $fields ) ) {
			return;
		}
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( (int) ( $field['ID'] ?? 0 ) > 0 ) {
				$ids[] = (int) $field['ID'];
			}
			self::collect_field_post_ids( $field['sub_fields'] ?? null, $ids );
			foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
				self::collect_field_post_ids( is_array( $layout ) ? ( $layout['sub_fields'] ?? null ) : null, $ids );
			}
		}
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resolve_field( string $selector, int $post_id = 0 ) {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return self::unknown_selector_error( $selector );
		}

		$field = null;
		if ( function_exists( 'acf_get_field' ) ) {
			$found = acf_get_field( $selector );
			if ( is_array( $found ) && '' !== self::field_key( $found ) ) {
				$field = $found;
			}
		}
		if ( null === $field && function_exists( 'acf_maybe_get_field' ) ) {
			$found = acf_maybe_get_field( $selector, $post_id, false );
			if ( is_array( $found ) && '' !== self::field_key( $found ) ) {
				$field = $found;
			}
		}
		if ( ! is_array( $field ) ) {
			return self::unknown_selector_error( $selector );
		}

		return $field;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function field_key( array $field ): string {
		$key = (string) ( $field['key'] ?? '' );
		return str_starts_with( $key, 'field_' ) ? $key : '';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function field_type( array $field ): string {
		return sanitize_key( (string) ( $field['type'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function is_comparable( array $field ): bool {
		$type = self::field_type( $field );
		return '' !== $type && isset( self::COMPARABLE_TYPES[ $type ] );
	}

	public static function read_raw( string $selector, int $post_id ): mixed {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}
		return get_field( $selector, $post_id, false );
	}

	/**
	 * Stored ACF field key for this post (`acf_get_reference` or `_{$name}` meta).
	 *
	 * @param array<string, mixed> $field
	 */
	public static function read_reference( array $field, int $post_id ): string {
		$name = (string) ( $field['name'] ?? '' );
		$key  = self::field_key( $field );
		$selectors = array_values(
			array_filter(
				[ $name, $key ],
				static fn( string $selector ): bool => '' !== $selector
			)
		);
		if ( function_exists( 'acf_get_reference' ) ) {
			foreach ( $selectors as $selector ) {
				$ref = acf_get_reference( $selector, $post_id );
				if ( is_string( $ref ) && str_starts_with( $ref, 'field_' ) ) {
					return $ref;
				}
			}
		}
		foreach ( $selectors as $selector ) {
			$meta = get_post_meta( $post_id, '_' . $selector, true );
			if ( is_string( $meta ) && str_starts_with( $meta, 'field_' ) ) {
				return $meta;
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function flush_value_cache( int $post_id, array $field ): void {
		if ( ! function_exists( 'acf_flush_value_cache' ) ) {
			return;
		}
		$name = (string) ( $field['name'] ?? '' );
		$key  = self::field_key( $field );
		if ( '' !== $name ) {
			acf_flush_value_cache( $post_id, $name );
		}
		if ( '' !== $key && $key !== $name ) {
			acf_flush_value_cache( $post_id, $key );
		}
	}

	/**
	 * Shape-check a proposed stored value before compare or write.
	 *
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	public static function validate_value( mixed $value, array $field ): bool|\WP_Error {
		return self::validate_value_at( $value, $field, 0 );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function values_equal( mixed $expected, mixed $actual, array $field ): bool {
		return self::canonicalize( $expected, $field ) === self::canonicalize( $actual, $field );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function references_valid( mixed $value, array $field ): bool {
		if ( self::validate_value( $value, $field ) instanceof \WP_Error ) {
			return false;
		}
		$type = self::field_type( $field );
		$kind = self::REFERENCE_TYPES[ $type ] ?? '';
		if ( '' === $kind ) {
			return true;
		}
		foreach ( self::extract_reference_ids( $value, $type ) as $id ) {
			if ( $id < 1 ) {
				return false;
			}
			if ( 'user' === $kind ) {
				$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $id ) : false;
				if ( ! $user ) {
					return false;
				}
				continue;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public static function canonicalize( mixed $value, array $field, int $depth = 0 ): mixed {
		if ( $depth > 20 ) {
			return null;
		}
		$type = self::field_type( $field );
		return match ( $type ) {
			'true_false' => self::canonicalize_true_false( $value ),
			'number', 'range' => self::canonicalize_number( $value ),
			'image', 'file' => self::canonicalize_id( $value ),
			'gallery', 'relationship' => self::canonicalize_id_list( $value ),
			'post_object', 'user', 'taxonomy' => is_array( $value ) && ! self::is_id_map( $value )
				? self::canonicalize_id_list( $value )
				: self::canonicalize_id( $value ),
			'page_link' => is_numeric( $value ) || self::is_id_map( is_array( $value ) ? $value : null )
				? self::canonicalize_id( $value )
				: self::canonicalize_generic( $value ),
			'checkbox', 'select' => self::canonicalize_ordered_list( $value ),
			'repeater', 'flexible_content' => self::canonicalize_rows( $value, $field, $depth ),
			'group' => self::canonicalize_group( $value, $field, $depth ),
			default => self::canonicalize_generic( $value ),
		};
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function result( int $post_id, string $selector, mixed $value, array $extra ): array {
		return array_merge(
			[
				'post_id'             => $post_id,
				'selector'            => $selector,
				'ok'                  => true,
				'value'               => $value,
				'changed'             => false,
				'execution_status'    => 'unchanged',
				'verification_status' => 'verified',
				'effect_verified'     => true,
			],
			$extra
		);
	}

	public static function bound_type( array $field ): string {
		$type = self::field_type( $field );
		if ( '' === $type ) {
			return 'unknown';
		}
		return mb_substr( $type, 0, 40 );
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_value_at( mixed $value, array $field, int $depth ): bool|\WP_Error {
		if ( $depth > 20 ) {
			return self::invalid_value_error();
		}
		$type = self::field_type( $field );
		return match ( $type ) {
			'true_false' => self::validate_true_false( $value ),
			'number', 'range' => self::validate_number( $value ),
			'image', 'file' => self::validate_reference_value( $value, false ),
			'gallery', 'relationship' => self::validate_reference_list( $value ),
			'post_object', 'user', 'taxonomy' => is_array( $value ) && ! self::is_id_map( $value )
				? self::validate_reference_list( $value )
				: self::validate_reference_value( $value, false ),
			'page_link' => self::validate_page_link( $value ),
			'group' => self::validate_group( $value, $field, $depth ),
			'repeater', 'flexible_content' => self::validate_rows( $value, $field, $depth ),
			default => true,
		};
	}

	private static function unknown_selector_error( string $selector ): \WP_Error {
		return new \WP_Error(
			'stonewright_acf_unknown_selector',
			__( 'Unknown ACF selector. Resolve a real field name or field_* key before writing.', 'stonewright' ),
			[
				'status'   => 404,
				'selector' => mb_substr( $selector, 0, 80 ),
			]
		);
	}

	private static function invalid_value_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_acf_invalid_value',
			__( 'ACF value does not match the field type.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}

	private static function validate_true_false( mixed $value ): bool|\WP_Error {
		if ( true === $value || false === $value || 1 === $value || 0 === $value || '1' === $value || '0' === $value ) {
			return true;
		}
		return self::invalid_value_error();
	}

	private static function validate_number( mixed $value ): bool|\WP_Error {
		if ( null === $value || '' === $value ) {
			return true;
		}
		if ( null === self::normalize_decimal( $value ) ) {
			return self::invalid_value_error();
		}
		return true;
	}

	private static function validate_page_link( mixed $value ): bool|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return true;
		}
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			return true;
		}
		return self::validate_reference_value( $value, false );
	}

	private static function validate_reference_value( mixed $value, bool $required ): bool|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return $required ? self::invalid_value_error() : true;
		}
		return self::parse_positive_id( $value ) instanceof \WP_Error
			? self::invalid_value_error()
			: true;
	}

	private static function validate_reference_list( mixed $value ): bool|\WP_Error {
		if ( self::is_empty_reference( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return self::validate_reference_value( $value, true );
		}
		if ( self::is_id_map( $value ) ) {
			return self::validate_reference_value( $value, true );
		}
		foreach ( $value as $item ) {
			if ( self::validate_reference_value( $item, true ) instanceof \WP_Error ) {
				return self::invalid_value_error();
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_group( mixed $value, array $field, int $depth ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid_value_error();
		}
		$subs = self::sub_field_index( $field );
		foreach ( $value as $key => $item ) {
			$sub = $subs[ (string) $key ] ?? null;
			if ( ! is_array( $sub ) ) {
				return self::invalid_value_error();
			}
			$inner = self::validate_value_at( $item, $sub, $depth + 1 );
			if ( $inner instanceof \WP_Error ) {
				return $inner;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return true|\WP_Error
	 */
	private static function validate_rows( mixed $value, array $field, int $depth ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::invalid_value_error();
		}
		$rows = array_is_list( $value ) ? $value : array_values( $value );
		foreach ( $rows as $row ) {
			$inner = self::validate_group( $row, $field, $depth );
			if ( $inner instanceof \WP_Error ) {
				return $inner;
			}
		}
		return true;
	}

	private static function canonicalize_true_false( mixed $value ): int {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 1;
		}
		return 0;
	}

	private static function canonicalize_number( mixed $value ): mixed {
		if ( null === $value || '' === $value ) {
			return '';
		}
		$normalized = self::normalize_decimal( $value );
		return null === $normalized ? $value : $normalized;
	}

	private static function canonicalize_id( mixed $value ): ?int {
		if ( self::is_empty_reference( $value ) ) {
			return null;
		}
		$parsed = self::parse_positive_id( $value );
		return $parsed instanceof \WP_Error ? null : $parsed;
	}

	/**
	 * @return list<int|null>
	 */
	private static function canonicalize_id_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$id = self::canonicalize_id( $value );
			return null === $id ? [] : [ $id ];
		}
		if ( self::is_id_map( $value ) ) {
			$id = self::canonicalize_id( $value );
			return null === $id ? [] : [ $id ];
		}
		$out = [];
		foreach ( $value as $item ) {
			$out[] = self::canonicalize_id( $item );
		}
		return $out;
	}

	private static function canonicalize_ordered_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [ self::canonicalize_generic( $value ) ];
		}
		$items = array_is_list( $value ) ? $value : array_values( $value );
		$out   = [];
		foreach ( $items as $item ) {
			$out[] = self::canonicalize_generic( $item );
		}
		return $out;
	}

	private static function canonicalize_generic( mixed $value ): mixed {
		if ( is_object( $value ) && isset( $value->ID ) ) {
			return (int) $value->ID;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			$out = [];
			foreach ( $value as $item ) {
				$out[] = self::canonicalize_generic( $item );
			}
			return $out;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$out[ (string) $key ] = self::canonicalize_generic( $item );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function canonicalize_rows( mixed $value, array $field, int $depth ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$rows = array_is_list( $value ) ? $value : array_values( $value );
		$out  = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$out[] = self::canonicalize_generic( $row );
				continue;
			}
			$out[] = self::canonicalize_group( $row, $field, $depth );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function canonicalize_group( mixed $value, array $field, int $depth ): mixed {
		if ( ! is_array( $value ) ) {
			return self::canonicalize_generic( $value );
		}
		$subs = self::sub_field_index( $field );
		$out  = [];
		foreach ( $value as $key => $item ) {
			$sub = $subs[ (string) $key ] ?? [ 'type' => 'text', 'key' => 'field_nested_' . $key ];
			$out[ (string) $key ] = self::canonicalize( $item, $sub, $depth + 1 );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, array<string, mixed>>
	 */
	private static function sub_field_index( array $field ): array {
		$subs = [];
		foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$name = (string) ( $sub['name'] ?? '' );
			$key  = (string) ( $sub['key'] ?? '' );
			if ( '' !== $name ) {
				$subs[ $name ] = $sub;
			}
			if ( '' !== $key ) {
				$subs[ $key ] = $sub;
			}
		}
		return $subs;
	}

	/**
	 * @return list<int>
	 */
	private static function extract_reference_ids( mixed $value, string $type ): array {
		if ( in_array( $type, [ 'gallery', 'relationship' ], true )
			|| ( is_array( $value ) && ! self::is_id_map( $value ) && in_array( $type, [ 'post_object', 'user', 'taxonomy' ], true ) ) ) {
			$ids = [];
			foreach ( self::canonicalize_id_list( $value ) as $id ) {
				if ( is_int( $id ) ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}
		$id = self::canonicalize_id( $value );
		return is_int( $id ) ? [ $id ] : [];
	}

	private static function is_id_map( mixed $value ): bool {
		return is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['id'] ) || isset( $value['attachment_id'] ) );
	}

	private static function is_empty_reference( mixed $value ): bool {
		return null === $value || false === $value || '' === $value;
	}

	private static function parse_positive_id( mixed $value ): int|\WP_Error {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : self::invalid_value_error();
		}
		if ( is_float( $value ) ) {
			if ( is_finite( $value ) && $value >= 1 && $value === floor( $value ) && $value <= PHP_INT_MAX ) {
				return (int) $value;
			}
			return self::invalid_value_error();
		}
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '/^\+?[1-9]\d*$/', $value ) ) {
				return self::invalid_value_error();
			}
			$digits = ltrim( $value, '+' );
			$as_int = (int) $digits;
			if ( (string) $as_int !== $digits ) {
				return self::invalid_value_error();
			}
			return $as_int;
		}
		if ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			if ( isset( $value->ID ) && 1 === count( $vars ) ) {
				return self::parse_positive_id( $value->ID );
			}
			return self::invalid_value_error();
		}
		if ( is_array( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! in_array( (string) $key, self::ID_MAP_KEYS, true ) ) {
					return self::invalid_value_error();
				}
			}
			$raw = $value['ID'] ?? $value['id'] ?? $value['attachment_id'] ?? null;
			if ( null === $raw ) {
				return self::invalid_value_error();
			}
			return self::parse_positive_id( $raw );
		}
		return self::invalid_value_error();
	}

	private static function normalize_decimal( mixed $value ): ?string {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
			$encoded = json_encode( $value );
			return is_string( $encoded ) ? self::canonical_decimal_string( $encoded ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		return self::canonical_decimal_string( trim( $value ) );
	}

	private static function canonical_decimal_string( string $raw ): ?string {
		if ( '' === $raw ) {
			return '';
		}
		if ( 1 !== preg_match( '/^([+-])?(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/', $raw, $match ) ) {
			return null;
		}
		$sign = '-' === ( $match[1] ?? '' ) ? '-' : '';
		if ( '' !== ( $match[4] ?? '' ) ) {
			$int  = '';
			$frac = $match[4];
		} else {
			$int  = $match[2] ?? '';
			$frac = $match[3] ?? '';
		}
		if ( '' === $int && '' === $frac ) {
			return null;
		}
		$digits = $int . $frac;
		$exp    = isset( $match[5] ) && '' !== $match[5] ? (int) $match[5] : 0;
		if ( abs( $exp ) > self::DECIMAL_MAX_DIGITS ) {
			return null;
		}
		$point = strlen( $int ) + $exp;
		if ( $point <= 0 ) {
			$normalized = '0.' . str_repeat( '0', -$point ) . $digits;
		} elseif ( $point >= strlen( $digits ) ) {
			$normalized = $digits . str_repeat( '0', $point - strlen( $digits ) );
		} else {
			$normalized = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
		}
		if ( str_contains( $normalized, '.' ) ) {
			[ $whole, $fraction ] = explode( '.', $normalized, 2 );
			$whole    = ltrim( $whole, '0' );
			$whole    = '' === $whole ? '0' : $whole;
			$fraction = rtrim( $fraction, '0' );
			$normalized = '' === $fraction ? $whole : $whole . '.' . $fraction;
		} else {
			$normalized = ltrim( $normalized, '0' );
			$normalized = '' === $normalized ? '0' : $normalized;
		}
		$digit_count = strlen( str_replace( '.', '', $normalized ) );
		if ( $digit_count > self::DECIMAL_MAX_DIGITS ) {
			return null;
		}
		if ( '0' === $normalized ) {
			return '0';
		}
		return $sign . $normalized;
	}
}

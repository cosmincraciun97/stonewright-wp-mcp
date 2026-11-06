<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfFieldGroupGet extends AbilityKernel {

	/** The most fields include_settings returns in full. */
	private const SETTINGS_MAX_FIELDS = 200;

	/** The largest full answer include_settings returns, in bytes of JSON. */
	private const SETTINGS_MAX_BYTES = 262144;

	public function name(): string {
		return 'stonewright/acf-field-group-get';
	}

	public function label(): string {
		return __( 'ACF: Get field group', 'stonewright' );
	}

	public function description(): string {
		return __( 'Gets one ACF field group and its fields as compact DTOs with their post IDs, the full settings with include_settings, or one field in full with field_key.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'key' ],
			'properties'           => [
				'key'              => [ 'type' => 'string', 'minLength' => 1 ],
				'include_settings' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Return the full group array and the full field arrays (parent, sub_fields, layouts and every setting) instead of the compact DTOs. Capped at 200 fields or 256 KiB: above the cap the answer is truncated:true with the compact fields and field_keys, and field_key reads one field in full.',
				],
				'field_key'        => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Return this one field of the group in full, nested fields included, as field.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_acf();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			static function ( array $args ) {
				if ( ! AcfRuntime::is_active() || ! function_exists( 'acf_get_field_group' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$key   = (string) $args['key'];
				$group = acf_get_field_group( $key );
				if ( ! is_array( $group ) ) {
					return new \WP_Error( 'stonewright_acf_group_not_found', 'Field group not found.' );
				}
				// The post ids come from the posts table: ACF answers with its local copy (id 0) for a group it also keeps in JSON or PHP.
				$posts      = AcfRuntime::group_posts_by_key( $key );
				$post_id    = (int) ( $posts['ids'][0] ?? ( $group['ID'] ?? 0 ) );
				$duplicates = array_values( array_slice( $posts['ids'], 1 ) );
				$fields     = function_exists( 'acf_get_fields' ) ? acf_get_fields( $key ) : [];
				$fields     = AcfRuntime::fields_with_post_ids( is_array( $fields ) ? array_values( $fields ) : [], AcfRuntime::field_posts_of_group( $post_id ) );
				$identity   = [
					'ID'                 => $post_id,
					'local'              => (string) $posts['local'],
					'duplicate_post_ids' => $duplicates,
				];
				$compact    = array_merge(
					[
						'key'    => (string) ( $group['key'] ?? $key ),
						'title'  => (string) ( $group['title'] ?? '' ),
						'active' => (bool) ( $group['active'] ?? true ),
					],
					$identity
				);

				$field_key = (string) ( $args['field_key'] ?? '' );
				if ( '' !== $field_key ) {
					$field = AcfRuntime::find_field( $fields, $field_key );
					if ( null === $field ) {
						return new \WP_Error(
							'stonewright_acf_field_not_found',
							__( 'The field group has no field with this key.', 'stonewright' ),
							[ 'status' => 404, 'key' => $key, 'field_key' => $field_key ]
						);
					}
					return [
						'group' => $compact,
						'field' => $field,
					];
				}

				if ( filter_var( $args['include_settings'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
					$full  = array_merge( $group, $identity );
					$flat  = AcfRuntime::flatten_fields( $fields );
					$count = count( $flat );
					$body  = [
						'group'     => $full,
						'fields'    => $fields,
						'truncated' => false,
					];
					if ( $count <= self::SETTINGS_MAX_FIELDS && strlen( (string) wp_json_encode( $body ) ) <= self::SETTINGS_MAX_BYTES ) {
						return $body;
					}
					return [
						'group'       => $full,
						'fields'      => self::compact_fields( $fields ),
						'field_keys'  => array_values( array_unique( array_filter( array_column( $flat, 'key' ), static fn( string $k ): bool => '' !== $k ) ) ),
						'field_count' => $count,
						'truncated'   => true,
					];
				}

				return [
					'group'  => $compact,
					'fields' => self::compact_fields( $fields ),
				];
			}
		);
	}

	/**
	 * @param list<mixed> $fields
	 * @return list<array<string, mixed>>
	 */
	private static function compact_fields( array $fields ): array {
		$compact = [];
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$compact[] = [
				'key'      => (string) ( $f['key'] ?? '' ),
				'name'     => (string) ( $f['name'] ?? '' ),
				'label'    => (string) ( $f['label'] ?? '' ),
				'type'     => (string) ( $f['type'] ?? '' ),
				'required' => (bool) ( $f['required'] ?? false ),
				'ID'       => (int) ( $f['ID'] ?? 0 ),
			];
		}
		return $compact;
	}
}

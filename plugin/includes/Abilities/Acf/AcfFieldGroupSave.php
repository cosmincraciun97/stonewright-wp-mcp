<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfFieldGroupSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/acf-field-group-save';
	}

	public function label(): string {
		return __( 'ACF: Save field group', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates an ACF field group and its fields through ACF\'s own import, resolving the group and its fields by key so an existing group is updated in place, and returns an error when ACF cannot import. Post IDs on the group and its fields are optional. A field the payload leaves out is deleted by ACF permanently, so that save is refused unless allow_field_removal is set. The field group and field posts are recorded in the change ledger. Confirmation token required in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'group' ],
			'properties'           => [
				'group'               => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'The field group as ACF JSON: key, title, location, active and fields (sub_fields and layouts for nested types). ID on the group and on its fields is optional: the save resolves them by key and updates the existing posts in place. An ID that belongs to another group or field is refused.',
				],
				'allow_field_removal' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Let the save go ahead when the payload leaves out fields the group holds in the database. ACF deletes those fields permanently, values included; without this flag such a save is refused with stonewright_acf_fields_would_be_removed and nothing is written.',
				],
				'confirmation_token'  => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => [ 'ok', 'key', 'post_id', 'created', 'fields_removed', 'fields_created', 'created_post_ids', 'duplicate_post_ids', 'local' ],
			'properties'           => [
				'ok'                 => [ 'type' => 'boolean' ],
				'key'                => [ 'type' => 'string' ],
				'post_id'            => [
					'type'        => 'integer',
					'description' => 'The post that holds the field group after the save; 0 when ACF reported no post.',
				],
				'created'            => [
					'type'        => 'boolean',
					'description' => 'True when no post held the key before the save.',
				],
				'fields_removed'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Keys of the fields ACF deleted because the payload left them out (allow_field_removal was set).',
				],
				'fields_created'     => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Keys of the fields the group did not hold before the save.',
				],
				'created_post_ids'   => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Posts the import inserted (the group and new fields); each is recorded in the change ledger.',
				],
				'duplicate_post_ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Other posts that hold the same key; the save continued on post_id because the call named it.',
				],
				'local'              => [
					'type'        => 'string',
					'enum'        => [ '', 'json', 'php' ],
					'description' => 'Whether ACF also keeps the group outside the posts table: in a JSON file or in PHP code.',
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_acf();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$err = $this->confirmation_token_error( $args, $verify );
				if ( null !== $err ) {
					return $err;
				}
				if ( ! AcfRuntime::is_active() ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$group = (array) ( $args['group'] ?? [] );
				if ( '' === (string) ( $group['key'] ?? '' ) ) {
					return new \WP_Error( 'stonewright_acf_group_key_required', 'group.key is required.' );
				}
				// acf_import_field_group saves the group and its fields. Without it nothing is saved, and the call says so.
				$result = AcfRuntime::import_field_group(
					$group,
					[ 'allow_field_removal' => filter_var( $args['allow_field_removal'] ?? false, FILTER_VALIDATE_BOOLEAN ) ]
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$key     = (string) $group['key'];
				$post_id = (int) ( $result['post_id'] ?? 0 );
				// Persist Stonewright-side contract mirror used by content-model flows, with the post ids the save resolved.
				$stored         = get_option( 'stonewright_acf_field_groups', [] );
				$stored         = is_array( $stored ) ? $stored : [];
				$stored[ $key ] = AcfRuntime::with_post_ids( $group, $post_id );
				update_option( 'stonewright_acf_field_groups', $stored, false );

				return [
					'ok'                 => true,
					'key'                => $key,
					'post_id'            => $post_id,
					'created'            => (bool) ( $result['created'] ?? false ),
					'fields_removed'     => array_values( array_map( 'strval', (array) ( $result['fields_removed'] ?? [] ) ) ),
					'fields_created'     => array_values( array_map( 'strval', (array) ( $result['fields_created'] ?? [] ) ) ),
					'created_post_ids'   => array_values( array_map( 'intval', (array) ( $result['created_post_ids'] ?? [] ) ) ),
					'duplicate_post_ids' => array_values( array_map( 'intval', (array) ( $result['duplicate_post_ids'] ?? [] ) ) ),
					'local'              => (string) ( $result['local'] ?? '' ),
				];
			}
		);
	}
}

<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Diagnostics;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** Read-only object-level capability diagnostic; it never bypasses a denial. */
final class CapabilityPreflight extends AbilityKernel {

	public function name(): string {
		return 'stonewright/capability-preflight';
	}

	public function label(): string {
		return __( 'Preflight object capability', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports user, role, primitive capabilities, object editability, and detected permission filters without changing access rules.', 'stonewright' );
	}

	public function category(): string {
		return 'diagnostics';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true, 'properties' => [ 'ok' => [ 'type' => 'boolean' ], 'object_editable' => [ 'type' => 'boolean' ], 'capabilities' => [ 'type' => 'object' ], 'deny_source' => [ 'type' => 'string' ] ] ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		$user_id = (string) get_current_user_id();
		$roles = [];
		$user = wp_get_current_user();
		$user_vars = is_object( $user ) ? get_object_vars( $user ) : [];
		if ( is_array( $user_vars['roles'] ?? null ) ) {
			$roles = array_values( array_map( 'sanitize_key', $user_vars['roles'] ) );
		}
		$capabilities = [];
		foreach ( [ 'read', 'edit_posts', 'edit_pages', 'edit_post', 'publish_posts', 'manage_options', 'edit_theme_options' ] as $capability ) {
			$capabilities[ $capability ] = 'edit_post' === $capability ? current_user_can( $capability, $post_id ) : current_user_can( $capability );
		}
		$deny_source = apply_filters( 'stonewright_permission_deny_source', '', $post_id );
		$deny_source = is_scalar( $deny_source ) ? sanitize_text_field( (string) $deny_source ) : '';
		$plugins = get_option( 'active_plugins', [] );
		$plugins = is_array( $plugins ) ? array_values( array_map( 'sanitize_text_field', $plugins ) ) : [];
		$post_vars = get_object_vars( $post );
		return [
			'ok'                 => true,
			'post_id'            => $post_id,
			'user_id_hash'       => hash_hmac( 'sha256', $user_id, wp_salt( 'auth' ) ),
			'roles'              => $roles,
			'capabilities'       => $capabilities,
			'object_editable'    => (bool) $capabilities['edit_post'],
			'post'               => [
				'type'           => sanitize_key( (string) $post->post_type ),
				'status'         => sanitize_key( (string) $post->post_status ),
				'author_id_hash' => hash_hmac( 'sha256', (string) (int) ( $post_vars['post_author'] ?? 0 ), wp_salt( 'auth' ) ),
			],
			'permission_filters' => [
				'map_meta_cap_hooked' => has_filter( 'map_meta_cap' ) > 0,
				'publishpress_detected' => (bool) count( preg_grep( '/publishpress|permissions/i', $plugins ) ?: [] ),
			],
			'deny_source'        => $deny_source,
			'remediation'        => '' !== $deny_source ? 'Remove or adjust the object-level exclusion in the permission plugin UI, then re-authenticate and retest current_user_can(edit_post). No bypass is provided.' : 'If denied, inspect the role and object-level permission UI before retrying.',
		];
	}
}

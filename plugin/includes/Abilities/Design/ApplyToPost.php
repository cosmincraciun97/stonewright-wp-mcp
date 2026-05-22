<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\AssetSideloader;
use Stonewright\WpMcp\Elementor\ElementorWriter;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design.apply_to_post
 *
 * Applies a validated DesignSpec to an existing WordPress post:
 *   1. Permission check: can_manage_design() + can_edit_post($post_id).
 *   2. Confirmation token enforced in production-safe mode (AGENTS.md rule 5).
 *   3. Sideloads images from spec.assets via AssetSideloader.
 *   4. Replaces image src/assetRef URLs in the spec with WP attachment URLs.
 *   5. Delegates to ElementorWriter::write() which enforces Backup → Validate → Render → write → audit.
 *
 * Security envelope:
 *   - Backup BEFORE write is enforced inside ElementorWriter (AGENTS.md rule 3).
 *   - Validator BEFORE render is enforced inside ElementorWriter (AGENTS.md rule 4).
 *   - Confirmation token required in production-safe mode (AGENTS.md rule 5).
 *   - SSRF-safe asset sideloading via AssetSideloader (uses wp_safe_remote_get).
 *
 * @stonewright-status stable
 */
final class ApplyToPost extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-apply-to-post';
	}

	public function label(): string {
		return __( 'Apply design spec to post', 'stonewright' );
	}

	public function description(): string {
		return __( 'Sideloads image assets, then applies a Stonewright Design Spec to an Elementor page. Requires a confirmation token in production-safe mode. Backup is created before any mutation.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'               => [ 'type' => 'object', 'description' => 'Stonewright Design Spec to apply.' ],
				'post_id'            => [ 'type' => 'integer', 'description' => 'ID of the WordPress post to update.' ],
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Required in production-safe mode.' ],
			],
			'required' => [ 'spec', 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'           => [ 'type' => 'integer' ],
				'spec_sha8'         => [ 'type' => 'string' ],
				'sideloaded_assets' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Attachment IDs created by sideloading.' ],
				'diagnostics'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required' => [ 'post_id', 'spec_sha8', 'sideloaded_assets', 'diagnostics' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::can_manage_design() ) {
			return false;
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			// post_id not yet validated — deny and let execute() produce the error.
			return false;
		}
		return Permissions::can_edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$raw_spec = $args['spec'] ?? null;

				if ( $post_id <= 0 ) {
					return $this->error( 'invalid_post_id', __( 'post_id must be a positive integer.', 'stonewright' ) );
				}

				if ( ! is_array( $raw_spec ) ) {
					return $this->error( 'missing_spec', __( 'The spec parameter is required and must be an object.', 'stonewright' ) );
				}

				// ── Confirmation token (production-safe mode) ─────────────────────
				if ( Permissions::is_production_safe() ) {
					$token = (string) ( $args['confirmation_token'] ?? '' );
					if ( '' === $token ) {
						return $this->error(
							'confirmation_token_required',
							__( 'A confirmation token is required in production-safe mode.', 'stonewright' )
						);
					}
					// Strip confirmation_token from args before hashing — ConfirmationToken normalizes this internally.
					$verify = ConfirmationToken::verify_or_error( $token, $this->name(), $args );
					if ( is_wp_error( $verify ) ) {
						return $verify;
					}
				}

				// ── Sideload images ───────────────────────────────────────────────

				/*
				 * PARTIAL SIDELOAD FAILURE BEHAVIOUR (intentional):
				 * - Individual asset sideload failures are NON-FATAL. Each failure is
				 *   recorded as a warning and iteration continues with the next asset.
				 *   The renderer falls back to the original CDN URL for failed assets.
				 * - Successfully-sideloaded attachments PERSIST even if a later asset
				 *   fails OR if ElementorWriter::write() subsequently rejects the spec.
				 *   This is intentional — media_handle_sideload() has no atomic undo.
				 * - Orphaned attachments (sideloaded but never referenced by a live post)
				 *   can be identified via: meta_query for '_stonewright_sideloaded_at'.
				 *   No automatic cleanup is performed.
				 */
				$sideloaded_assets = [];
				$url_map           = []; // original URL → WP attachment URL

				if ( ! empty( $raw_spec['assets'] ) && is_array( $raw_spec['assets'] ) ) {
					foreach ( $raw_spec['assets'] as $asset ) {
						if ( ! is_array( $asset ) || empty( $asset['url'] ) ) {
							continue;
						}
						$source_url    = (string) $asset['url'];
						$attachment_id = AssetSideloader::sideload( $source_url );
						if ( is_wp_error( $attachment_id ) ) {
							// Non-fatal: record and continue. The renderer will use the original URL.
							$raw_spec['warnings'][] = sprintf( 'Asset sideload failed for "%s": %s', $source_url, $attachment_id->get_error_message() );
							continue;
						}
						$wp_url = wp_get_attachment_url( $attachment_id );
						if ( false !== $wp_url && '' !== $wp_url ) {
							$url_map[ $source_url ] = $wp_url;
						}
						$sideloaded_assets[] = $attachment_id;
					}
				}

				// ── Replace image URLs in spec ────────────────────────────────────
				if ( [] !== $url_map ) {
					$raw_spec = self::replace_asset_urls( $raw_spec, $url_map );
				}

				// ── Write via ElementorWriter (Backup → Validate → Render → write) ─
				$diagnostics = [];
				$write_result = ElementorWriter::write( $post_id, $raw_spec, $diagnostics );
				if ( is_wp_error( $write_result ) ) {
					return $write_result;
				}

				// ── Compute spec fingerprint ──────────────────────────────────────
				// Re-run validate to get the normalized spec for the sha fingerprint.
				$validated = \Stonewright\WpMcp\DesignSpec\Validator::validate( $raw_spec );
				$spec_json = is_wp_error( $validated )
					? (string) wp_json_encode( $raw_spec, JSON_UNESCAPED_SLASHES )
					: (string) wp_json_encode( $validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$spec_sha8 = substr( sha1( $spec_json ), 0, 8 );

				return [
					'post_id'           => $post_id,
					'spec_sha8'         => $spec_sha8,
					'sideloaded_assets' => $sideloaded_assets,
					'diagnostics'       => $diagnostics,
				];
			}
		);
	}

	/**
	 * Recursively replace image src/url values in the spec using the URL map.
	 *
	 * @param array<string, mixed>  $spec
	 * @param array<string, string> $url_map Original URL → WP attachment URL.
	 * @return array<string, mixed>
	 */
	private static function replace_asset_urls( array $spec, array $url_map ): array {
		array_walk_recursive(
			$spec,
			static function ( mixed &$value ) use ( $url_map ): void {
				if ( is_string( $value ) && isset( $url_map[ $value ] ) ) {
					$value = $url_map[ $value ];
				}
			}
		);
		return $spec;
	}

	/**
	 * Redact confirmation_token from audit log.
	 *
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}

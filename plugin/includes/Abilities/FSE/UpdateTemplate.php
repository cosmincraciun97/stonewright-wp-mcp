<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * Compatibility wrapper for stonewright/fse-update-template.
 *
 * Keeps the id-based (`theme//slug`) caller contract while applying the same
 * strong envelope as {@see WriteTemplate}: can_manage_fse, ConfirmationGuard,
 * Backup::snapshot_post, and sanitized post_content.
 *
 * @stonewright-status stable
 */
final class UpdateTemplate extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/fse-update-template';
	}

	public function label(): string {
		return __( 'Update template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compatibility wrapper around fse-write-template. Updates wp_template or wp_template_part content by id (e.g. "theme//slug") using the same backup, confirmation, and sanitization envelope.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [ 'type' => 'string' ],
				'type'               => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part' ], 'default' => 'wp_template' ],
				'content'            => [ 'type' => 'string' ],
				'title'              => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'id', 'content' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_fse();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$content = BlockMarkup::sanitize( (string) $args['content'] );
				if ( is_wp_error( $content ) ) {
					return $content;
				}

				$cpt = isset( $args['type'] ) ? (string) $args['type'] : 'wp_template';
				if ( ! in_array( $cpt, [ 'wp_template', 'wp_template_part' ], true ) ) {
					return $this->error( 'invalid_type', __( 'Type must be wp_template or wp_template_part.', 'stonewright' ) );
				}

				if ( ! function_exists( 'get_block_template' ) ) {
					return $this->error( 'fse_unavailable', __( 'Block templates are not available on this site.', 'stonewright' ) );
				}

				$template = get_block_template( (string) $args['id'], $cpt );
				if ( ! $template ) {
					return $this->error( 'not_found', __( 'Template not found.', 'stonewright' ) );
				}

				$post_id = $template->wp_id ?? 0;
				$payload = [ 'post_content' => $content ];
				if ( isset( $args['title'] ) ) {
					$payload['post_title'] = sanitize_text_field( (string) $args['title'] );
				}

				if ( $post_id ) {
					Backup::snapshot_post( (int) $post_id );
					$payload['ID'] = (int) $post_id;
					$result        = wp_update_post( $payload, true );
				} else {
					$slug                   = $template->slug ?? '';
					$payload['post_type']   = $cpt;
					$payload['post_status'] = 'publish';
					$payload['post_name']   = (string) $slug;
					$payload['post_title']  = $payload['post_title'] ?? ( is_object( $template->title ) ? ( $template->title->rendered ?? '' ) : (string) $template->title );
					$result                 = wp_insert_post( $payload, true );
				}

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return [
					'id'      => (string) $args['id'],
					'post_id' => (int) $result,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}

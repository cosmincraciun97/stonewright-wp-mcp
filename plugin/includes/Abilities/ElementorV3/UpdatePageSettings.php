<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CachePurgeAdvisor;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
use Stonewright\WpMcp\Elementor\Write\EditorKses;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdatePageSettings extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-update-page-settings';
	}

	public function label(): string {
		return __( 'Update Elementor page settings', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates _elementor_page_settings (background, layout). Custom CSS keys require stonewright/theme-custom-css with a human-issued custom_code_grant.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'custom_code_grant'  => [ 'type' => 'string', 'description' => 'Single-use human-issued custom-code grant for custom CSS or an HTML widget style tag in this call, bound to the exact candidate code. Send it only when the user has approved that code.' ],
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'settings' => [ 'type' => 'object' ],
				'mode'     => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
			],
			'required'             => [ 'post_id', 'settings' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'content_filtered' => [ 'type' => 'boolean', 'description' => 'True when the user cannot unfiltered_html and Elementor\'s filtering changed the settings before they were stored.' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$post_id     = (int) $args['post_id'];
				$incoming    = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : [];
				$css_gate    = ElementorCustomCssGate::assert_incoming( $incoming, $args );
				if ( $css_gate instanceof \WP_Error ) {
					return $css_gate;
				}
				foreach ( $incoming as $key => $value ) {
					$violation = CssValueGuard::setting_violation( (string) $key, $value );
					if ( null !== $violation ) {
						return CssValueGuard::refusal( 'settings.' . $key . ( '' === $violation['path'] ? '' : '.' . $violation['path'] ), $violation['expected'], $value );
					}
				}
				$owner = 'page-settings-' . substr( hash( 'sha256', $post_id . '|' . hrtime( true ) ), 0, 24 );
				$lease = PostWriteLock::acquire( $post_id, $owner );
				if ( $lease instanceof \WP_Error ) {
					return $lease;
				}

				try {
					$snapshot_id = Backup::snapshot_post( $post_id );
					if ( '' === $snapshot_id ) {
						return $this->backup_failed_error();
					}

					$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
					if ( ! is_array( $existing ) ) {
						$existing = [];
					}

					$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
					$next = 'replace' === $mode
						? $incoming
						: array_merge( $existing, $incoming );
					// For a user without unfiltered_html Elementor filters the settings when it stores them; filter first, the way its editor does.
					$filtered         = EditorKses::filter( $next );
					$content_filtered = $filtered !== $next ? [ 'content_filtered' => true ] : [];
					$next             = $filtered;

					if ( false === update_post_meta( $post_id, '_elementor_page_settings', wp_slash( $next ) ) && $next !== $existing ) {
						return $this->error( 'write_failed', __( 'Could not save Elementor page settings.', 'stonewright' ) );
					}

					PostCacheInvalidator::invalidate( $post_id );
					CachePurgeAdvisor::note_write();

					return $content_filtered + [
						'post_id'     => $post_id,
						'snapshot_id' => $snapshot_id,
					];
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
	}
}

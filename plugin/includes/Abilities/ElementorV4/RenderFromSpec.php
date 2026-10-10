<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\V4\AtomicWriteReadback;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Validates a Stonewright Design Spec and renders it into an Elementor V4 atomic
 * element tree. Writes to the post unless dry_run is true (default).
 * When replace is false (default) the rendered tree is appended; when true it
 * replaces the entire existing tree.
 *
 * The spec goes through Validator::validate() first; an invalid spec returns the
 * structured `stonewright_spec_invalid` WP_Error and nothing is rendered.
 * ElementorV4SpecRenderer then maps each section and each heading, paragraph,
 * image, button, separator, icon, row, column, and card block onto a node tree,
 * and AtomicRenderer compiles every node against the certified Atomic schema:
 * layout nodes (sections, rows, columns, and cards) become `e-flexbox`
 * containers, widget nodes use `elType` widget with an `e-` widgetType such as
 * `e-heading`, and every prop is wrapped in the typed `{ $$type, value }`
 * envelope. Section and container styling (layout, direction with tablet and
 * mobile variants, gap, padding, background color, full width, alignment, and
 * z-index) is written as typed Atomic styles. An unsupported property, an
 * unsupported block type, a block type or prop without a certified Atomic
 * schema, and a value that schema rejects, is returned as a structured
 * WP_Error (for example `stonewright_v4_unknown_node`) that names the failing
 * path; a partial tree is never returned as success and nothing is written.
 * After a write the saved tree is read back recursively, and a dropped child is
 * returned as an error.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class RenderFromSpec extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v4-render-from-spec';
	}

	public function label(): string {
		return __( 'Render spec to Elementor V4 (experimental)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Fallback writer for when no certified native ability covers the page: validates a Stonewright Design Spec and renders it as an Elementor V4 atomic tree. When native Elementor composition is certified, prefer stonewright-elementor-native-execute. dry_run=true (default) returns the tree without writing. Section styling is written as typed Atomic styles; an unsupported property or block type returns an error naming its path. Every write is followed by a recursive readback of the written tree.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'               => [ 'type' => 'object' ],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'replace'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'atomic_tree' => [ 'type' => 'array' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'errors'      => [ 'type' => 'array' ],
				'diagnostics' => [ 'type' => 'array' ],
				'readback'    => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check( ! (bool) ( $args['dry_run'] ?? true ) );
		if ( is_wp_error( $gate ) ) {
return $gate; }
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$spec    = is_array( $args['spec'] ) ? $args['spec'] : [];
				$dry_run = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;
				$replace = isset( $args['replace'] ) ? (bool) $args['replace'] : false;

				$normalized = Validator::validate( $spec );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				$diagnostics = [];
				$atomic_tree = ElementorV4SpecRenderer::render( $normalized, $diagnostics );
				if ( is_wp_error( $atomic_tree ) ) {
					return $atomic_tree;
				}

				if ( $dry_run || empty( $args['post_id'] ) ) {
					return [
						'ok'          => true,
						'dry_run'     => true,
						'atomic_tree' => $atomic_tree,
						'snapshot_id' => '',
						'errors'      => [],
						'diagnostics' => $diagnostics,
					];
				}

				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$existing = [];
				if ( $replace ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
					$new_tree = $atomic_tree;
				} else {
					$existing = ElementorData::read( $post_id );
					$new_tree = array_merge( $existing, $atomic_tree );
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'backup_failed', __( 'Backup snapshot failed; write aborted.', 'stonewright' ) );
				}

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}
				$readback = AtomicWriteReadback::verify_tree( $post_id, $new_tree, $snapshot_id, 'render_from_spec', [ 'ids' => array_keys( ElementorData::flatten( $atomic_tree ) ), 'before' => $existing ] );
				if ( $readback instanceof \WP_Error ) {
					return $readback;
				}

				return [
					'ok'          => true,
					'dry_run'     => false,
					'atomic_tree' => $atomic_tree,
					'snapshot_id' => $snapshot_id,
					'errors'      => [],
					'diagnostics' => $diagnostics,
					'readback'    => $readback,
				];
			}
		);
	}
}

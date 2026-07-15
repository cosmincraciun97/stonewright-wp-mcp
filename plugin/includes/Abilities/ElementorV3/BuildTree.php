<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Composite write of a raw Elementor element tree with path-exact errors.
 *
 * @stonewright-status stable
 */
final class BuildTree extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-build-tree';
	}

	public function label(): string {
		return __( 'Build Elementor tree', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a full Elementor element tree (sections/containers/widgets), snapshots, writes atomically, regenerates CSS for the post, and returns a compact digest. Errors include the exact tree path (e.g. sections[1].elements[3].settings.title).', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'tree' ],
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'tree'               => [
					'type'        => 'array',
					'description' => 'Elementor elements array (top-level sections/containers).',
				],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'          => [ 'type' => 'boolean' ],
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'element_count' => [ 'type' => 'integer' ],
				'aliases_applied' => [ 'type' => 'integer' ],
				'css'         => [ 'type' => 'object' ],
				'digest'      => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'post_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$tree    = isset( $args['tree'] ) && is_array( $args['tree'] ) ? $args['tree'] : null;
				$dry_run = ! empty( $args['dry_run'] );

				$token_error = $this->confirmation_token_error(
					$args,
					[
						'post_id' => $post_id,
						'dry_run' => $dry_run,
					]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( null === $tree ) {
					return $this->error( 'invalid_tree', __( 'tree must be an array of Elementor elements.', 'stonewright' ) );
				}

				$aliases_applied = 0;
				$normalized      = $this->normalize_tree( $tree, [], $aliases_applied );
				if ( $normalized instanceof \WP_Error ) {
					return $normalized;
				}

				if ( ! SettingsValidator::validate_tree( $normalized ) ) {
					return $this->error(
						'invalid_tree_structure',
						__( 'Elementor tree failed structural validation (ids, elType nesting).', 'stonewright' )
					);
				}

				if ( $dry_run ) {
					return [
						'ok'              => true,
						'post_id'         => $post_id,
						'dry_run'         => true,
						'element_count'   => count( ElementorData::flatten( $normalized ) ),
						'aliases_applied' => $aliases_applied,
					];
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'backup_failed', __( 'Backup snapshot failed; write aborted.', 'stonewright' ) );
				}

				if ( ! ElementorData::write( $post_id, $normalized ) ) {
					return $this->error( 'write_failed', __( 'Failed to persist Elementor tree.', 'stonewright' ) );
				}

				$css    = CssRegenerator::regenerate_post( $post_id );
				$digest = ( new PageDigest() )->execute(
					[
						'post_id'   => $post_id,
						'max_nodes' => 80,
					]
				);

				return [
					'ok'              => true,
					'post_id'         => $post_id,
					'snapshot_id'     => $snapshot_id,
					'dry_run'         => false,
					'element_count'   => count( ElementorData::flatten( $normalized ) ),
					'aliases_applied' => $aliases_applied,
					'css'             => $css,
					'digest'          => is_array( $digest ) ? $digest : [],
				];
			}
		);
	}

	/**
	 * @param array<int, mixed> $nodes
	 * @param list<string|int>  $path
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function normalize_tree( array $nodes, array $path, int &$aliases_applied ) {
		$out = [];
		foreach ( $nodes as $index => $node ) {
			$here = array_merge( $path, [ $index ] );
			if ( ! is_array( $node ) ) {
				return $this->path_error( $here, 'node_not_object', 'Element must be an object.' );
			}

			$el_type = (string) ( $node['elType'] ?? '' );
			if ( '' === $el_type ) {
				return $this->path_error( $here, 'missing_elType', 'elType is required.' );
			}

			if ( empty( $node['id'] ) || ! is_string( $node['id'] ) ) {
				$node['id'] = ElementorData::generate_id();
			}

			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$norm     = SettingsKeyAliases::normalize( $settings );
			$settings = $norm['settings'];
			$aliases_applied += count( $norm['applied'] );

			if ( 'widget' === $el_type ) {
				$widget_type = (string) ( $node['widgetType'] ?? '' );
				if ( '' === $widget_type ) {
					return $this->path_error( array_merge( $here, [ 'widgetType' ] ), 'missing_widgetType', 'widgetType is required for widgets.' );
				}
				// Soft validate when schema available; unknown widgets still allowed with warning path.
				$validated = SettingsValidator::validate( $widget_type, $settings, false, false );
				if ( is_wp_error( $validated ) ) {
					// Schema missing is non-fatal in composite builds; structural write still proceeds.
					$code = $validated->get_error_code();
					if ( 'unknown_widget' !== $code && 'schema_unavailable' !== $code ) {
						$data = $validated->get_error_data();
						$path_hint = is_array( $data ) && isset( $data['path'] ) ? (string) $data['path'] : 'settings';
						return $this->path_error(
							array_merge( $here, [ $path_hint ] ),
							(string) $code,
							$validated->get_error_message()
						);
					}
				} elseif ( is_array( $validated ) && isset( $validated['settings'] ) && is_array( $validated['settings'] ) ) {
					$settings = $validated['settings'];
				}
			} elseif ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
				$validated = SettingsValidator::validate_container( $settings, $el_type, false );
				if ( is_array( $validated ) && isset( $validated['settings'] ) && is_array( $validated['settings'] ) ) {
					$settings = $validated['settings'];
				}
			}

			$node['settings'] = $settings;
			$children         = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			if ( [] !== $children ) {
				$child_norm = $this->normalize_tree( $children, array_merge( $here, [ 'elements' ] ), $aliases_applied );
				if ( $child_norm instanceof \WP_Error ) {
					return $child_norm;
				}
				$node['elements'] = $child_norm;
			} else {
				$node['elements'] = [];
			}

			$out[] = $node;
		}

		return $out;
	}

	/**
	 * @param list<string|int> $path
	 */
	private function path_error( array $path, string $code, string $message ): \WP_Error {
		$path_string = $this->format_path( $path );
		return new \WP_Error(
			'stonewright_tree_invalid',
			sprintf( '%s at %s', $message, $path_string ),
			[
				'status' => 400,
				'code'   => $code,
				'path'   => $path_string,
			]
		);
	}

	/**
	 * @param list<string|int> $path
	 */
	private function format_path( array $path ): string {
		$out = 'tree';
		foreach ( $path as $segment ) {
			if ( is_int( $segment ) ) {
				$out .= '[' . $segment . ']';
			} else {
				$out .= '.' . $segment;
			}
		}
		return $out;
	}
}

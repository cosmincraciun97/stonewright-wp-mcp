<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\SparseSettingsNormalizer;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class UpdateElement extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-update-element';
	}

	public function label(): string {
		return __( 'Update Elementor element', 'stonewright' );
	}

	public function description(): string {
		return __( 'Patches settings of an element identified by id. Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string' ],
				'settings'   => [ 'type' => 'object' ],
				'mode'       => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
				'dry_run'    => [ 'type' => 'boolean', 'default' => false ],
				'expected_tree_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			],
			'required'             => [ 'post_id', 'element_id', 'settings' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'before_hash' => [ 'type' => 'string' ],
				'after_hash'  => [ 'type' => 'string' ],
				'post_write'  => [ 'type' => 'object' ],
				'next_step'   => [ 'type' => 'object' ],
				'write_receipt' => [ 'type' => 'object' ],
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
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$dry_run = ! empty( $args['dry_run'] );
				if ( $dry_run ) {
					return $this->update( $args, $post_id, true, '' );
				}

				$owner = 'single-' . substr(
					hash( 'sha256', $post_id . '|' . (string) $args['element_id'] . '|' . hrtime( true ) ),
					0,
					24
				);
				$lease = PostWriteLock::acquire( $post_id, $owner );
				if ( $lease instanceof \WP_Error ) {
					return $lease;
				}

				try {
					return $this->update( $args, $post_id, false, $owner );
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update( array $args, int $post_id, bool $dry_run, string $lock_owner ): array|\WP_Error {
				$tree        = ElementorData::read( $post_id );
				$before_hash = TreeHasher::hash( $tree );
				$expected_hash = isset( $args['expected_tree_hash'] ) ? (string) $args['expected_tree_hash'] : '';
				if ( '' !== $expected_hash && ! hash_equals( $expected_hash, $before_hash ) ) {
					return $this->error(
						'tree_conflict',
						__( 'Elementor page changed after planning; refresh structure before writing.', 'stonewright' ),
						[
							'status'            => 409,
							'expected_tree_hash'=> $expected_hash,
							'current_tree_hash' => $before_hash,
						]
					);
				}

				$architecture = AtomicTreeInspector::subtree_architecture( $tree, (string) $args['element_id'] );
				if ( in_array( $architecture, [ 'v4', 'mixed' ], true ) ) {
					return $this->error(
						'v3_architecture_mismatch',
						__( 'The targeted Elementor element is or contains V4 Atomic nodes. V3 mutation is blocked.', 'stonewright' ),
						[
							'status'               => 409,
							'target_architecture'  => $architecture,
							'before_hash'          => $before_hash,
							'repair'               => 'Run elementor-document-health and target one returned v3_safe_root, or use the typed Elementor V4 ability.',
						]
					);
				}

				$path        = ElementorData::find_path( $tree, (string) $args['element_id'] );
				if ( null === $path ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$existing = $this->resolve( $tree, $path );
				if ( null === $existing ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$settings = isset( $existing['settings'] ) && is_array( $existing['settings'] ) ? $existing['settings'] : [];
				$mode     = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$incoming = (array) $args['settings'];
				$supplied = SettingsKeyAliases::normalize( $incoming )['settings'];
				$next     = 'replace' === $mode ? $incoming : array_merge( $settings, $incoming );
				$element_type = (string) ( $existing['elType'] ?? '' );
				if ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
					if ( 'container' === $element_type ) {
						$supplied = ContainerSettings::normalize( $supplied );
						$next     = ContainerSettings::normalize( $next );
					}
					$validated = SettingsValidator::validate_container( $next, $element_type, false, true );
					if ( $validated instanceof \WP_Error ) {
						return $validated;
					}
					$schema = ContainerSchemaRepository::get( $element_type );
					$controls = is_array( $schema ) ? (array) ( $schema['controls'] ?? [] ) : [];
					$next = SparseSettingsNormalizer::for_write(
						$validated['settings'],
						$controls,
						$supplied,
						'replace' === $mode ? [] : $settings
					);
				} elseif ( 'widget' === ( $existing['elType'] ?? '' ) ) {
					$widget_type = (string) ( $existing['widgetType'] ?? '' );
					$validated = SettingsValidator::validate( $widget_type, $next, false, false, true );
					if ( $validated instanceof \WP_Error ) {
						return $validated;
					}
					$schema   = WidgetSchemaRepository::get( $widget_type );
					$controls = is_array( $schema ) ? (array) ( $schema['controls'] ?? [] ) : [];
					$required = is_array( $schema ) ? array_map( 'strval', (array) ( $schema['required_for_render'] ?? [] ) ) : [];
					$next     = SparseSettingsNormalizer::for_write(
						$validated['settings'],
						$controls,
						$supplied,
						'replace' === $mode ? [] : $settings,
						$required
					);
				}

				$existing['settings'] = $next;

				$new_tree = ElementorData::set( $tree, $path, $existing );
				$after_hash = TreeHasher::hash( $new_tree );
				if ( $dry_run ) {
					return [
						'ok'          => true,
						'post_id'     => $post_id,
						'snapshot_id' => '',
						'dry_run'     => true,
						'before_hash' => $before_hash,
						'after_hash'  => $after_hash,
						'changed_setting_keys' => array_values( array_keys( $incoming ) ),
						'post_write'  => [],
						'next_step'   => [
							'tool'               => 'stonewright/elementor-v3-update-element',
							'expected_tree_hash' => $before_hash,
							'then'               => 'stonewright/elementor-post-write-verify',
						],
					];
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( ! ElementorData::write( $post_id, $new_tree, [ 'touched_ids' => [ (string) $args['element_id'] ], 'lock_owner' => $lock_owner ] ) ) {
					return ElementorData::write_error_for_ability();
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
					'dry_run'     => false,
					'before_hash' => $before_hash,
					'after_hash'  => $after_hash,
					'post_write'  => ElementorData::last_write_receipt(),
					'next_step'   => [
						'tool'        => 'stonewright/elementor-post-write-verify',
						'post_id'     => $post_id,
						'element_ids' => [ (string) $args['element_id'] ],
						'required_before_browser_acceptance' => true,
					],
				];
	}

	private function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}
}

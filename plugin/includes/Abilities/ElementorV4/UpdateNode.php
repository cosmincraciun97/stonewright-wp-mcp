<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Surgical settings patch for one Elementor V4 Atomic node by id.
 *
 * Loads the full document tree (never the inspector projection), merges or
 * replaces settings on a single atomic node, snapshots, then writes through
 * ElementorData::write without integrity bypass.
 *
 * @stonewright-status experimental
 */
final class UpdateNode extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-update-node';
	}

	public function label(): string {
		return __( 'Update Elementor V4 atomic node', 'stonewright' );
	}

	public function description(): string {
		return __( 'Patches settings of one atomic Elementor node by id. Snapshots before write. Use dry_run first.', 'stonewright' );
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
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string', 'minLength' => 1 ],
				'settings'   => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Persisted atomic settings keys (e.g. title, tag, classes) with typed $$type envelopes — not DesignSpec prop names.',
				],
				'mode'       => [
					'type'    => 'string',
					'enum'    => [ 'merge', 'replace' ],
					'default' => 'merge',
				],
				'dry_run'    => [
					'type'    => 'boolean',
					'default' => false,
				],
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
				'element_id'  => [ 'type' => 'string' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'settings'    => [ 'type' => 'object' ],
				'architecture' => [ 'type' => 'string', 'enum' => [ 'empty', 'v3', 'v4', 'mixed' ] ],
				'warnings'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$dry_run = ! empty( $args['dry_run'] );
		$gate    = V4FeatureGate::check( ! $dry_run );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id    = (int) $args['post_id'];
				$element_id = (string) $args['element_id'];
				$dry_run    = ! empty( $args['dry_run'] );
				$mode       = isset( $args['mode'] ) ? (string) $args['mode'] : 'merge';
				$incoming   = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : null;

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( null === $incoming ) {
					return $this->error( 'invalid_settings', __( 'settings must be an object of atomic setting patches.', 'stonewright' ) );
				}
				if ( ! in_array( $mode, [ 'merge', 'replace' ], true ) ) {
					return $this->error( 'invalid_mode', __( 'mode must be merge or replace.', 'stonewright' ) );
				}

				// Full document only — never write AtomicTreeInspector::atomic_tree back.
				$tree    = ElementorData::read( $post_id );
				$inspect = AtomicTreeInspector::inspect( $tree );
				$architecture = (string) ( $inspect['architecture'] ?? 'empty' );

				if ( in_array( $architecture, [ 'v3', 'empty' ], true ) ) {
					return $this->error(
						'v4_architecture_mismatch',
						__( 'This document has no Elementor V4 Atomic nodes. Use V3 element update or batch-mutate instead.', 'stonewright' ),
						[
							'status'       => 409,
							'architecture' => $architecture,
							'repair'       => RemediationHints::for_code( 'stonewright_v4_architecture_mismatch', $this->name() ),
						]
					);
				}

				$path = ElementorData::find_path( $tree, $element_id );
				if ( null === $path ) {
					$live_ids = $this->collect_atomic_ids(
						isset( $inspect['atomic_tree'] ) && is_array( $inspect['atomic_tree'] ) ? $inspect['atomic_tree'] : [],
						20
					);
					return $this->error(
						'element_not_found',
						__( 'Atomic element not found in the document tree.', 'stonewright' ),
						[
							'status'           => 404,
							'element_id'       => $element_id,
							'live_atomic_ids'  => $live_ids,
							'repair'           => RemediationHints::for_code( 'stonewright_element_not_found', $this->name() ),
						]
					);
				}

				$existing = $this->resolve( $tree, $path );
				if ( null === $existing ) {
					return $this->error( 'element_not_found', __( 'Atomic element not found in the document tree.', 'stonewright' ) );
				}

				if ( ! $this->is_atomic_node( $existing ) ) {
					return $this->error(
						'non_atomic_target',
						__( 'Target element is not an Elementor V4 Atomic node (elType/widgetType must start with e-).', 'stonewright' ),
						[
							'status'      => 400,
							'element_id'  => $element_id,
							'elType'      => (string) ( $existing['elType'] ?? '' ),
							'widgetType'  => (string) ( $existing['widgetType'] ?? '' ),
							'repair'      => 'Call stonewright/elementor-v4-read-atomic-tree to list atomic ids, or use stonewright/elementor-v3-update-element for classic V3 nodes.',
						]
					);
				}

				// Settings-only: never mutate id / elType / widgetType / version / styles tree.
				$current_settings = isset( $existing['settings'] ) && is_array( $existing['settings'] )
					? $existing['settings']
					: [];

				$atomic_type = $this->atomic_type( $existing );
				$validated   = $this->validate_atomic_settings( $atomic_type, $current_settings, $incoming );
				if ( $validated instanceof \WP_Error ) {
					return $validated;
				}

				/** @var array{settings: array<string, mixed>, warnings: list<string>} $validated */
				$next     = 'replace' === $mode
					? $validated['settings']
					: array_merge( $current_settings, $validated['settings'] );
				$warnings = $validated['warnings'];

				if ( $dry_run ) {
					return [
						'post_id'      => $post_id,
						'snapshot_id'  => '',
						'element_id'   => $element_id,
						'dry_run'      => true,
						'settings'     => $next,
						'architecture' => $architecture,
						'warnings'     => $warnings,
					];
				}

				$existing['settings'] = $next;
				$new_tree             = ElementorData::set( $tree, $path, $existing );

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'backup_failed', __( 'Backup snapshot failed; write aborted.', 'stonewright' ) );
				}

				if ( ! ElementorData::write( $post_id, $new_tree ) ) {
					return ElementorData::write_error_for_ability();
				}

				return [
					'post_id'      => $post_id,
					'snapshot_id'  => $snapshot_id,
					'element_id'   => $element_id,
					'dry_run'      => false,
					'settings'     => $next,
					'architecture' => $architecture,
					'warnings'     => $warnings,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function is_atomic_node( array $element ): bool {
		$atomic_type = $this->atomic_type( $element );
		return '' !== $atomic_type && str_starts_with( $atomic_type, 'e-' );
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function atomic_type( array $element ): string {
		$el_type     = (string) ( $element['elType'] ?? '' );
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		return 'widget' === $el_type ? $widget_type : $el_type;
	}

	/**
	 * Light ability-layer validation for atomic settings patches.
	 *
	 * @param array<string, mixed> $current Existing node settings.
	 * @param array<string, mixed> $incoming Patch keys.
	 * @return array{settings: array<string, mixed>, warnings: list<string>}|\WP_Error
	 */
	private function validate_atomic_settings( string $atomic_type, array $current, array $incoming ): array|\WP_Error {
		$warnings = [];
		$schema   = AtomicSchemaRepository::for_atomic_type( $atomic_type );
		$reverse  = null === $schema ? [] : $this->reverse_prop_map( $schema );

		if ( null === $schema ) {
			$warnings[] = sprintf(
				/* translators: %s: atomic type slug */
				__( 'Atomic type "%s" is not in the schema repository; applying structure-only settings checks.', 'stonewright' ),
				$atomic_type
			);
		}

		$out = [];
		foreach ( $incoming as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return $this->error( 'invalid_settings_key', __( 'Settings keys must be non-empty strings.', 'stonewright' ) );
			}

			// classes is a documented special envelope on atomic nodes.
			$known = isset( $reverse[ $key ] ) || 'classes' === $key;
			$on_node = array_key_exists( $key, $current );

			if ( $known ) {
				if ( ! $this->is_typed_envelope( $value ) ) {
					return $this->error(
						'invalid_settings_envelope',
						sprintf(
							/* translators: %s: settings key */
							__( 'Atomic setting "%s" must be a typed envelope with $$type and value.', 'stonewright' ),
							$key
						),
						[
							'status'       => 400,
							'settings_key' => $key,
							'expected'     => [ '$$type' => 'string', 'value' => 'mixed' ],
						]
					);
				}
				$expected_type = (string) ( $reverse[ $key ] ?? '' );
				// classes uses a dedicated envelope; raw-json accepts any runtime envelope type.
				if (
					'' !== $expected_type
					&& 'raw-json' !== $expected_type
					&& (string) $value['$$type'] !== $expected_type
				) {
					return $this->error(
						'invalid_settings_envelope_type',
						sprintf(
							/* translators: 1: settings key, 2: expected $$type, 3: actual $$type */
							__( 'Atomic setting "%1$s" expects $$type "%2$s", got "%3$s".', 'stonewright' ),
							$key,
							$expected_type,
							(string) $value['$$type']
						),
						[
							'status'        => 400,
							'settings_key'  => $key,
							'expected_type' => $expected_type,
							'actual_type'   => (string) $value['$$type'],
						]
					);
				}
				$out[ $key ] = $value;
				continue;
			}

			if ( $on_node ) {
				// Preserve unknown keys already on the node (parity with preserve_unknown).
				if ( is_array( $value ) && $this->looks_like_partial_envelope( $value ) && ! $this->is_typed_envelope( $value ) ) {
					return $this->error(
						'invalid_settings_envelope',
						sprintf(
							/* translators: %s: settings key */
							__( 'Settings key "%s" looks like a typed envelope but is incomplete (needs both $$type and value).', 'stonewright' ),
							$key
						),
						[ 'status' => 400, 'settings_key' => $key ]
					);
				}
				$out[ $key ] = $value;
				$warnings[]  = sprintf(
					/* translators: %s: settings key */
					__( 'Preserved unknown settings key already present on node: %s.', 'stonewright' ),
					$key
				);
				continue;
			}

			// New key: require schema reverse map (handled above) or typed envelope.
			if ( $this->is_typed_envelope( $value ) ) {
				$out[ $key ] = $value;
				$warnings[]  = sprintf(
					/* translators: %s: settings key */
					__( 'Accepted new settings key with typed envelope (not in reverse prop map): %s.', 'stonewright' ),
					$key
				);
				continue;
			}

			return $this->error(
				'unknown_settings_key',
				sprintf(
					/* translators: 1: settings key, 2: atomic type */
					__( 'Unknown settings key "%1$s" for atomic type "%2$s". Use a schema key with a $$type envelope, or patch a key already present on the node.', 'stonewright' ),
					$key,
					$atomic_type
				),
				[
					'status'        => 400,
					'settings_key'  => $key,
					'atomic_type'   => $atomic_type,
					'known_keys'    => array_values( array_unique( array_merge( array_keys( $reverse ), [ 'classes' ] ) ) ),
				]
			);
		}

		return [
			'settings' => $out,
			'warnings' => $warnings,
		];
	}

	/**
	 * Build settings-key → envelope-type map from AtomicSchemaRepository props.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, string>
	 */
	private function reverse_prop_map( array $schema ): array {
		$map   = [ 'classes' => 'classes' ];
		$props = isset( $schema['props'] ) && is_array( $schema['props'] ) ? $schema['props'] : [];
		foreach ( $props as $design_name => $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$key = (string) ( $prop['key'] ?? $design_name );
			if ( '' === $key ) {
				continue;
			}
			$map[ $key ] = (string) ( $prop['type'] ?? '' );
		}
		return $map;
	}

	/**
	 * @param mixed $value
	 */
	private function is_typed_envelope( mixed $value ): bool {
		return is_array( $value )
			&& array_key_exists( '$$type', $value )
			&& array_key_exists( 'value', $value )
			&& is_string( $value['$$type'] )
			&& '' !== $value['$$type'];
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function looks_like_partial_envelope( array $value ): bool {
		return array_key_exists( '$$type', $value ) || array_key_exists( 'value', $value );
	}

	/**
	 * @param list<array<string, mixed>> $atomic_tree
	 * @return list<string>
	 */
	private function collect_atomic_ids( array $atomic_tree, int $cap ): array {
		$ids = [];
		$this->walk_ids( $atomic_tree, $ids, $cap );
		return $ids;
	}

	/**
	 * @param list<mixed>|array<int, mixed> $nodes
	 * @param list<string>                  $ids
	 */
	private function walk_ids( array $nodes, array &$ids, int $cap ): void {
		foreach ( $nodes as $node ) {
			if ( count( $ids ) >= $cap ) {
				return;
			}
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$ids[] = (string) $node['id'];
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->walk_ids( $node['elements'], $ids, $cap );
			}
		}
	}

	/**
	 * @param array<int, mixed> $tree
	 * @param list<int>         $path
	 * @return array<string, mixed>|null
	 */
	private function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) || ! is_array( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}
}

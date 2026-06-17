<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Applies multiple Elementor V3 tree mutations in one compact write.
 *
 * @stonewright-status stable
 */
final class BatchMutate extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-batch-mutate';
	}

	public function label(): string {
		return __( 'Batch mutate Elementor V3 page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies many Elementor V3 add/update/move/remove operations to one page in one request, with one read, one snapshot, and one write. Use op_id refs to avoid follow-up reads for generated IDs.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'stop_on_error'      => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
				'operations'         => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 200,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'action'                 => [
								'type' => 'string',
								'enum' => [ 'add_container', 'add_widget', 'update_element', 'move_element', 'remove_element' ],
							],
							'type'                   => [
								'type'        => 'string',
								'description' => 'Compact alias for action: container, widget, update, move, remove.',
							],
							'op'                     => [
								'type'        => 'string',
								'description' => 'Alias for action/type.',
							],
							'op_id'                  => [ 'type' => 'string' ],
							'parent_id'              => [ 'type' => 'string' ],
							'parent_ref'             => [ 'type' => 'string' ],
							'parent'                 => [
								'type'        => 'string',
								'description' => 'Alias for parent_id, or parent_ref when prefixed with @.',
							],
							'element_id'             => [ 'type' => 'string' ],
							'element_ref'            => [ 'type' => 'string' ],
							'target'                 => [
								'type'        => 'string',
								'description' => 'Alias for element_id, or element_ref when prefixed with @.',
							],
							'target_id'              => [ 'type' => 'string' ],
							'target_ref'             => [ 'type' => 'string' ],
							'new_parent_id'          => [ 'type' => 'string' ],
							'new_parent_ref'         => [ 'type' => 'string' ],
							'new_parent'             => [
								'type'        => 'string',
								'description' => 'Alias for new_parent_id, or new_parent_ref when prefixed with @.',
							],
							'position'               => [ 'type' => 'integer' ],
							'widget_type'            => [ 'type' => 'string' ],
							'widget'                 => [ 'type' => 'string' ],
							'settings'               => [ 'type' => 'object' ],
							'mode'                   => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
							'allow_html_widget'      => [ 'type' => 'boolean', 'default' => false ],
							'allow_raw_known_widget' => [ 'type' => 'boolean', 'default' => true ],
						],
					],
				],
			],
			'required'             => [ 'post_id', 'operations' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'post_id'     => [ 'type' => 'integer' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'applied'     => [ 'type' => 'integer' ],
				'failed'      => [ 'type' => 'integer' ],
				'items'       => [ 'type' => 'array' ],
				'refs'          => [ 'type' => 'object' ],
				'elements'      => [ 'type' => 'integer' ],
				'element_count' => [ 'type' => 'integer' ],
				'metrics'       => [ 'type' => 'object' ],
				'preview'       => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$start      = microtime( true );
				$post_id    = (int) $args['post_id'];
				$operations = isset( $args['operations'] ) && is_array( $args['operations'] ) ? self::normalize_operations( array_values( $args['operations'] ) ) : [];
				$dry_run    = ! empty( $args['dry_run'] );

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				if ( [] === $operations ) {
					return $this->error( 'missing_operations', __( 'At least one batch operation is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				if ( ! $dry_run && self::contains_destructive_operation( $operations ) ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				$read_start = microtime( true );
				$tree       = ElementorData::read( $post_id );
				$read_ms    = self::elapsed_ms( $read_start );
				$items      = [];
				$refs       = [];
				$applied    = 0;
				$failed     = 0;
				$stop       = ! array_key_exists( 'stop_on_error', $args ) || (bool) $args['stop_on_error'];

				foreach ( $operations as $index => $operation ) {
					$operation = is_array( $operation ) ? $operation : [];
					$result    = $this->apply_operation( $tree, $operation, $refs );

					if ( $result instanceof \WP_Error ) {
						++$failed;
						$items[] = self::error_item( $index, $result );
						if ( $stop ) {
							return $this->error(
								'batch_operation_failed',
								__( 'Elementor batch mutation failed before writing any changes.', 'stonewright' ),
								[
									'items'   => $items,
									'applied' => $applied,
									'failed'  => $failed,
								]
							);
						}
						continue;
					}

					++$applied;
					$items[] = array_merge(
						[
							'index' => $index,
							'ok'    => true,
						],
						$result
					);
				}

				$snapshot_id = '';
				$write_ms    = 0.0;
				if ( ! $dry_run ) {
					$snapshot_id = Backup::snapshot_post( $post_id );
					$write_start = microtime( true );
					if ( ! ElementorData::write( $post_id, $tree ) ) {
						return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
					}
					$write_ms = self::elapsed_ms( $write_start );
				}

				$element_count = count( ElementorData::flatten( $tree ) );
				$response      = [
					'ok'            => 0 === $failed,
					'post_id'       => $post_id,
					'dry_run'       => $dry_run,
					'snapshot_id'   => $snapshot_id,
					'applied'       => $applied,
					'failed'        => $failed,
					'items'         => $items,
					'refs'          => $refs,
					'elements'      => $element_count,
					'element_count' => $element_count,
					'metrics'       => [
						'elapsed_ms' => self::elapsed_ms( $start ),
						'read_ms'    => $read_ms,
						'write_ms'   => $write_ms,
					],
				];

				if ( $dry_run ) {
					$response['preview'] = $tree;
				}

				return $response;
			}
		);
	}

	/**
	 * @param array<int, mixed> $operations
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_operations( array $operations ): array {
		return array_map(
			static fn( mixed $operation ): array => self::normalize_operation( is_array( $operation ) ? $operation : [] ),
			$operations
		);
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function normalize_operation( array $operation ): array {
		$normalized = $operation;
		$action     = (string) ( $normalized['action'] ?? $normalized['type'] ?? $normalized['op'] ?? '' );
		if ( '' !== $action ) {
			$normalized['action'] = self::normalize_action( $action );
		}

		self::copy_ref_or_id_alias( $normalized, 'parent', 'parent_ref', 'parent_id' );
		self::copy_ref_or_id_alias( $normalized, 'target', 'element_ref', 'element_id' );
		self::copy_ref_or_id_alias( $normalized, 'new_parent', 'new_parent_ref', 'new_parent_id' );

		if ( isset( $normalized['target_id'] ) && ! isset( $normalized['element_id'] ) ) {
			$normalized['element_id'] = (string) $normalized['target_id'];
		}
		if ( isset( $normalized['target_ref'] ) && ! isset( $normalized['element_ref'] ) ) {
			$normalized['element_ref'] = (string) $normalized['target_ref'];
		}
		if ( isset( $normalized['widget'] ) && ! isset( $normalized['widget_type'] ) ) {
			$normalized['widget_type'] = (string) $normalized['widget'];
		}

		return $normalized;
	}

	private static function normalize_action( string $action ): string {
		return match ( $action ) {
			'container' => 'add_container',
			'widget'    => 'add_widget',
			'update'    => 'update_element',
			'move'      => 'move_element',
			'remove', 'delete' => 'remove_element',
			default     => $action,
		};
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	private static function copy_ref_or_id_alias( array &$operation, string $alias, string $ref_key, string $id_key ): void {
		if ( ! isset( $operation[ $alias ] ) || isset( $operation[ $ref_key ] ) || isset( $operation[ $id_key ] ) ) {
			return;
		}

		$value = (string) $operation[ $alias ];
		if ( str_starts_with( $value, '@' ) ) {
			$operation[ $ref_key ] = substr( $value, 1 );
			return;
		}
		$operation[ $id_key ] = $value;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_operation( array &$tree, array $operation, array &$refs ): array|\WP_Error {
		$action = isset( $operation['action'] ) ? (string) $operation['action'] : '';

		return match ( $action ) {
			'add_container'  => $this->add_container( $tree, $operation, $refs ),
			'add_widget'     => $this->add_widget( $tree, $operation, $refs ),
			'update_element' => $this->update_element( $tree, $operation, $refs ),
			'move_element'   => $this->move_element( $tree, $operation, $refs ),
			'remove_element' => $this->remove_element( $tree, $operation, $refs ),
			default          => $this->error( 'invalid_action', __( 'Unsupported Elementor batch action.', 'stonewright' ), [ 'action' => $action ] ),
		};
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function add_container( array &$tree, array $operation, array &$refs ): array|\WP_Error {
		$parent_path = $this->parent_path( $tree, $operation, $refs, 'parent_id', 'parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$settings = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		$element  = [
			'id'       => ElementorData::generate_id(),
			'elType'   => 'container',
			'isInner'  => [] !== $parent_path,
			'settings' => ContainerSettings::normalize( $settings ),
			'elements' => [],
		];

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return $this->created_item( $operation, $refs, $element['id'], 'container' );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function add_widget( array &$tree, array $operation, array &$refs ): array|\WP_Error {
		$widget_type = isset( $operation['widget_type'] ) ? (string) $operation['widget_type'] : '';
		if ( '' === $widget_type ) {
			return $this->error( 'missing_widget_type', __( 'add_widget requires widget_type.', 'stonewright' ) );
		}
		if ( 'html' === $widget_type && empty( $operation['allow_html_widget'] ) ) {
			return $this->error( 'html_widget_requires_explicit_approval', __( 'Elementor HTML widgets are disabled by default.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$settings = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		if ( 'html' !== $widget_type && WidgetCatalog::has( $widget_type ) ) {
			$violations = self::required_setting_violations( $widget_type, $settings );
			if ( [] !== $violations ) {
				return $this->error(
					'invalid_settings',
					__( 'Known-widget settings failed validation. Provide exact Elementor control keys.', 'stonewright' ),
					[
						'violations' => $violations,
						'widget'     => $widget_type,
					]
				);
			}
		}

		$parent_path = $this->parent_path( $tree, $operation, $refs, 'parent_id', 'parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$element = [
			'id'         => ElementorData::generate_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => [],
		];

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return $this->created_item( $operation, $refs, $element['id'], 'widget' );
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_element( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$element = self::resolve( $tree, $path );
		if ( null === $element ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$incoming = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : [];
		$existing = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		$mode     = isset( $operation['mode'] ) ? (string) $operation['mode'] : 'merge';
		$settings = 'replace' === $mode ? $incoming : array_merge( $existing, $incoming );
		if ( 'container' === ( $element['elType'] ?? '' ) ) {
			$settings = ContainerSettings::normalize( $settings );
		}

		$element['settings'] = $settings;
		$tree                = ElementorData::set( $tree, $path, $element );

		return [
			'action'     => 'update_element',
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function move_element( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$src_path = ElementorData::find_path( $tree, $element_id );
		if ( null === $src_path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$element = self::resolve( $tree, $src_path );
		if ( null === $element ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$tree        = ElementorData::set( $tree, $src_path, null );
		$parent_path = $this->parent_path( $tree, $operation, $refs, 'new_parent_id', 'new_parent_ref' );
		if ( $parent_path instanceof \WP_Error ) {
			return $parent_path;
		}

		$position = isset( $operation['position'] ) ? (int) $operation['position'] : PHP_INT_MAX;
		$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

		return [
			'action'     => 'move_element',
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<string, mixed>|\WP_Error
	 */
	private function remove_element( array &$tree, array $operation, array $refs ): array|\WP_Error {
		$element_id = $this->element_id( $operation, $refs );
		if ( $element_id instanceof \WP_Error ) {
			return $element_id;
		}

		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ), [ 'element_id' => $element_id ] );
		}

		$tree = ElementorData::set( $tree, $path, null );

		return [
			'action'     => 'remove_element',
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<string, mixed>            $operation
	 * @param array<string, string>           $refs
	 * @return array<int, int>|\WP_Error
	 */
	private function parent_path( array $tree, array $operation, array $refs, string $id_key, string $ref_key ): array|\WP_Error {
		$parent_id = '';
		if ( isset( $operation[ $ref_key ] ) && is_string( $operation[ $ref_key ] ) ) {
			$ref = $operation[ $ref_key ];
			if ( ! isset( $refs[ $ref ] ) ) {
				return $this->error( 'unknown_ref', __( 'Batch operation references an unknown op_id.', 'stonewright' ), [ 'ref' => $ref ] );
			}
			$parent_id = $refs[ $ref ];
		} elseif ( isset( $operation[ $id_key ] ) ) {
			$parent_id = (string) $operation[ $id_key ];
		}

		if ( '' === $parent_id ) {
			return [];
		}

		$path = ElementorData::find_path( $tree, $parent_id );
		if ( null === $path ) {
			return $this->error( 'parent_not_found', __( 'Parent element not found.', 'stonewright' ), [ 'parent_id' => $parent_id ] );
		}

		return $path;
	}

	/**
	 * @param array<string, mixed>  $operation
	 * @param array<string, string> $refs
	 * @return string|\WP_Error
	 */
	private function element_id( array $operation, array $refs ): string|\WP_Error {
		if ( isset( $operation['element_ref'] ) && is_string( $operation['element_ref'] ) ) {
			$ref = $operation['element_ref'];
			if ( ! isset( $refs[ $ref ] ) ) {
				return $this->error( 'unknown_ref', __( 'Batch operation references an unknown op_id.', 'stonewright' ), [ 'ref' => $ref ] );
			}
			return $refs[ $ref ];
		}

		$element_id = isset( $operation['element_id'] ) ? (string) $operation['element_id'] : '';
		if ( '' === $element_id ) {
			return $this->error( 'missing_element_id', __( 'Operation requires element_id or element_ref.', 'stonewright' ) );
		}

		return $element_id;
	}

	/**
	 * @param array<string, mixed>  $operation
	 * @param array<string, string> $refs
	 * @return array<string, mixed>
	 */
	private function created_item( array $operation, array &$refs, string $element_id, string $kind ): array {
		if ( isset( $operation['op_id'] ) && is_string( $operation['op_id'] ) && '' !== $operation['op_id'] ) {
			$refs[ $operation['op_id'] ] = $element_id;
		}

		return [
			'action'     => 'add_' . $kind,
			'element_id' => $element_id,
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, array<string, mixed>>
	 */
	private static function required_setting_violations( string $widget_type, array $settings ): array {
		$violations = [];
		foreach ( WidgetCatalog::required_for_render( $widget_type ) as $req_key ) {
			$present = array_key_exists( $req_key, $settings ) && self::setting_has_value( $settings[ $req_key ] );
			if ( ! $present ) {
				$violations[] = [
					'path'     => 'settings.' . $req_key,
					'code'     => 'required_missing',
					'expected' => 'non-empty Elementor control value',
					'got'      => array_key_exists( $req_key, $settings ) ? $settings[ $req_key ] : null,
				];
			}
		}

		return $violations;
	}

	private static function setting_has_value( mixed $value ): bool {
		if ( null === $value || '' === $value ) {
			return false;
		}
		if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
			return null !== $value['value'] && '' !== $value['value'];
		}
		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 */
	private static function contains_destructive_operation( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( is_array( $operation ) && 'remove_element' === ( $operation['action'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>|null
	 */
	private static function resolve( array $tree, array $path ): ?array {
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

	private static function elapsed_ms( float $start ): float {
		return round( ( microtime( true ) - $start ) * 1000, 3 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error_item( int $index, \WP_Error $error ): array {
		return [
			'index' => $index,
			'ok'    => false,
			'error' => [
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => (array) $error->get_error_data(),
			],
		];
	}
}

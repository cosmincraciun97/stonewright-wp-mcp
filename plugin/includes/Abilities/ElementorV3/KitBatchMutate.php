<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Transactional Elementor kit globals mutation.
 *
 * Prefer this ability for bounded design-profile kit work (container width,
 * body/heading typography, color/typography token refs, layout defaults).
 * Individual {@see UpdateKitColors} / {@see UpdateKitTypography} remain thin
 * wrappers for single-group edits.
 *
 * Safety loop: dry-run → snapshot → apply once → readback/hash → optional rollback.
 * Unknown kit settings always survive (merge-only writes).
 *
 * @stonewright-status stable
 */
final class KitBatchMutate extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-kit-batch-mutate';
	}

	public function label(): string {
		return __( 'Batch mutate Elementor kit globals', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies container width, colors, typography, and layout kit settings in one transactional write with dry-run, snapshot, readback, and optional rollback. Unknown kit settings are preserved.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
				'rollback_snapshot'  => [
					'type'        => 'string',
					'description' => 'When set with rollback=true, restores this snapshot instead of applying operations.',
				],
				'rollback'           => [ 'type' => 'boolean', 'default' => false ],
				'operations'         => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 50,
					'description' => 'Typed kit operations. group: colors|typography|layout|settings.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'group'    => [
								'type' => 'string',
								'enum' => [ 'colors', 'typography', 'layout', 'settings' ],
							],
							'mode'     => [ 'type' => 'string', 'enum' => [ 'merge', 'replace' ], 'default' => 'merge' ],
							'bucket'   => [ 'type' => 'string', 'enum' => [ 'system', 'custom', 'global' ], 'default' => 'custom' ],
							'colors'   => [ 'type' => 'array' ],
							'fonts'    => [ 'type' => 'array' ],
							'setting'  => [ 'type' => 'string' ],
							'value'    => [],
							'settings' => [ 'type' => 'object' ],
						],
						'required'             => [ 'group' ],
					],
				],
			],
			'required'             => [ 'operations' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'kit_id'        => [ 'type' => 'integer' ],
				'dry_run'       => [ 'type' => 'boolean' ],
				'snapshot_id'   => [ 'type' => 'string' ],
				'applied'       => [ 'type' => 'integer' ],
				'before_hash'   => [ 'type' => 'string' ],
				'after_hash'    => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'preview'       => [ 'type' => 'object' ],
				'readback'      => [ 'type' => 'object' ],
				'rollback'      => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
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

				$kit_id = (int) get_option( 'elementor_active_kit', 0 );
				if ( $kit_id <= 0 || ! get_post( $kit_id ) ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit.', 'stonewright' ) );
				}

				if ( ! empty( $args['rollback'] ) ) {
					return self::rollback( $kit_id, (string) ( $args['rollback_snapshot'] ?? '' ) );
				}

				$operations = isset( $args['operations'] ) && is_array( $args['operations'] )
					? array_values( $args['operations'] )
					: [];
				if ( [] === $operations ) {
					return $this->error( 'missing_operations', __( 'At least one kit operation is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$current = get_post_meta( $kit_id, '_elementor_page_settings', true );
				$current = is_array( $current ) ? $current : [];
				$before_hash = self::hash_settings( $current );

				$planned = $current;
				$applied = 0;
				foreach ( $operations as $operation ) {
					if ( ! is_array( $operation ) ) {
						return $this->error( 'invalid_operation', __( 'Each kit operation must be an object.', 'stonewright' ), [ 'status' => 400 ] );
					}
					$next = self::apply_operation( $planned, $operation );
					if ( $next instanceof \WP_Error ) {
						return $next;
					}
					$planned = $next;
					++$applied;
				}

				$after_hash = self::hash_settings( $planned );
				$preview    = self::preview_diff( $current, $planned );

				if ( ! empty( $args['dry_run'] ) ) {
					return [
						'ok'          => true,
						'kit_id'      => $kit_id,
						'dry_run'     => true,
						'snapshot_id' => '',
						'applied'     => 0,
						'before_hash' => $before_hash,
						'after_hash'  => $after_hash,
						'preview'     => $preview,
						'rollback'    => false,
					];
				}

				$snapshot_id = Backup::snapshot_post( $kit_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'snapshot_failed', __( 'Could not snapshot the Elementor kit before write.', 'stonewright' ), [ 'status' => 409 ] );
				}

				if ( false === update_post_meta( $kit_id, '_elementor_page_settings', $planned ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor kit settings.', 'stonewright' ) );
				}

				$readback = get_post_meta( $kit_id, '_elementor_page_settings', true );
				$readback = is_array( $readback ) ? $readback : [];
				$readback_hash = self::hash_settings( $readback );

				return [
					'ok'            => true,
					'kit_id'        => $kit_id,
					'dry_run'       => false,
					'snapshot_id'   => $snapshot_id,
					'applied'       => $applied,
					'before_hash'   => $before_hash,
					'after_hash'    => $after_hash,
					'readback_hash' => $readback_hash,
					'preview'       => $preview,
					'readback'      => [
						'container_width' => $readback['container_width'] ?? null,
						'colors_custom'   => count( is_array( $readback['custom_colors'] ?? null ) ? $readback['custom_colors'] : [] ),
						'typo_system'     => count( is_array( $readback['system_typography'] ?? null ) ? $readback['system_typography'] : [] ),
						'typo_custom'     => count( is_array( $readback['custom_typography'] ?? null ) ? $readback['custom_typography'] : [] ),
					],
					'rollback'      => false,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function apply_operation( array $settings, array $operation ): array|\WP_Error {
		$group = (string) ( $operation['group'] ?? '' );

		return match ( $group ) {
			'colors'     => self::apply_colors( $settings, $operation ),
			'typography' => self::apply_typography( $settings, $operation ),
			'layout'     => self::apply_layout( $settings, $operation ),
			'settings'   => self::apply_settings( $settings, $operation ),
			default      => new \WP_Error(
				'stonewright_kit_operation_invalid',
				__( 'Unknown kit operation group.', 'stonewright' ),
				[ 'status' => 400, 'group' => $group ]
			),
		};
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function apply_colors( array $settings, array $operation ): array {
		$bucket = self::color_bucket( (string) ( $operation['bucket'] ?? 'custom' ) );
		$mode   = (string) ( $operation['mode'] ?? 'merge' );
		$existing = isset( $settings[ $bucket ] ) && is_array( $settings[ $bucket ] ) ? $settings[ $bucket ] : [];
		$incoming = [];
		foreach ( (array) ( $operation['colors'] ?? [] ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$incoming[] = [
				'_id'   => (string) ( $c['id'] ?? $c['_id'] ?? '' ),
				'title' => (string) ( $c['title'] ?? '' ),
				'color' => (string) ( $c['color'] ?? '' ),
			];
		}
		$settings[ $bucket ] = 'replace' === $mode ? $incoming : self::merge_entries( $existing, $incoming );
		return $settings;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function apply_typography( array $settings, array $operation ): array {
		$bucket   = self::typography_bucket( (string) ( $operation['bucket'] ?? 'custom' ) );
		$mode     = (string) ( $operation['mode'] ?? 'merge' );
		$existing = isset( $settings[ $bucket ] ) && is_array( $settings[ $bucket ] ) ? $settings[ $bucket ] : [];
		$incoming = [];
		foreach ( (array) ( $operation['fonts'] ?? [] ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$entry = [
				'_id'   => (string) ( $f['id'] ?? $f['_id'] ?? '' ),
				'title' => (string) ( $f['title'] ?? '' ),
			];
			if ( isset( $f['font_family'] ) ) {
				$entry['typography_font_family'] = (string) $f['font_family'];
				$entry['typography_typography']  = 'custom';
			}
			if ( isset( $f['font_weight'] ) ) {
				$entry['typography_font_weight'] = (string) $f['font_weight'];
			}
			if ( isset( $f['font_size'] ) && is_array( $f['font_size'] ) ) {
				$entry['typography_font_size'] = $f['font_size'];
			}
			if ( isset( $f['line_height'] ) && is_array( $f['line_height'] ) ) {
				$entry['typography_line_height'] = $f['line_height'];
			}
			if ( isset( $f['letter_spacing'] ) && is_array( $f['letter_spacing'] ) ) {
				$entry['typography_letter_spacing'] = $f['letter_spacing'];
			}
			// Preserve any extra Elementor keys the caller already validated.
			foreach ( $f as $key => $value ) {
				if ( is_string( $key ) && str_starts_with( $key, 'typography_' ) && ! array_key_exists( $key, $entry ) ) {
					$entry[ $key ] = $value;
				}
			}
			$incoming[] = $entry;
		}
		$settings[ $bucket ] = 'replace' === $mode ? $incoming : self::merge_entries( $existing, $incoming );
		return $settings;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function apply_layout( array $settings, array $operation ): array|\WP_Error {
		// Single setting form: { group: layout, setting: container_width, value: {size:1140,unit:px} }
		if ( isset( $operation['setting'] ) ) {
			$key = (string) $operation['setting'];
			if ( '' === $key ) {
				return new \WP_Error( 'stonewright_kit_operation_invalid', __( 'Layout setting key is required.', 'stonewright' ), [ 'status' => 400 ] );
			}
			$settings[ $key ] = $operation['value'] ?? null;
			return $settings;
		}

		// Bulk settings form under layout group.
		if ( isset( $operation['settings'] ) && is_array( $operation['settings'] ) ) {
			foreach ( $operation['settings'] as $key => $value ) {
				$settings[ (string) $key ] = $value;
			}
			return $settings;
		}

		// Fixture-friendly shortcuts.
		if ( isset( $operation['container_width'] ) ) {
			$width = $operation['container_width'];
			$settings['container_width'] = is_array( $width )
				? $width
				: [ 'size' => (int) $width, 'unit' => 'px' ];
		}
		if ( isset( $operation['space_between_widgets'] ) ) {
			$settings['space_between_widgets'] = $operation['space_between_widgets'];
		}

		return $settings;
	}

	/**
	 * Merge arbitrary kit settings without dropping unknown keys.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function apply_settings( array $settings, array $operation ): array {
		$incoming = isset( $operation['settings'] ) && is_array( $operation['settings'] )
			? $operation['settings']
			: [];
		foreach ( $incoming as $key => $value ) {
			$settings[ (string) $key ] = $value;
		}
		return $settings;
	}

	/**
	 * @param list<array<string, mixed>> $existing
	 * @param list<array<string, mixed>> $incoming
	 * @return list<array<string, mixed>>
	 */
	private static function merge_entries( array $existing, array $incoming ): array {
		$by_id = [];
		foreach ( $existing as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = (string) ( $row['_id'] ?? $row['id'] ?? '' );
			if ( '' === $id ) {
				$by_id[ 'anon_' . count( $by_id ) ] = $row;
				continue;
			}
			$by_id[ $id ] = $row;
		}
		foreach ( $incoming as $row ) {
			$id = (string) ( $row['_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$previous = $by_id[ $id ] ?? [];
			// Merge entry keys so unknown Elementor properties on the prior row survive.
			$by_id[ $id ] = array_merge( is_array( $previous ) ? $previous : [], $row );
		}
		return array_values( $by_id );
	}

	private static function color_bucket( string $bucket ): string {
		return match ( $bucket ) {
			'system' => 'system_colors',
			'global' => 'global_colors',
			default  => 'custom_colors',
		};
	}

	private static function typography_bucket( string $bucket ): string {
		return match ( $bucket ) {
			'system' => 'system_typography',
			'global' => 'global_typography',
			default  => 'custom_typography',
		};
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function hash_settings( array $settings ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalize( $settings ) ) );
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private static function preview_diff( array $before, array $after ): array {
		$changed = [];
		$keys    = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		foreach ( $keys as $key ) {
			$b = $before[ $key ] ?? null;
			$a = $after[ $key ] ?? null;
			if ( wp_json_encode( $b ) !== wp_json_encode( $a ) ) {
				$changed[] = (string) $key;
			}
		}
		return [
			'changed_keys' => $changed,
			'count'        => count( $changed ),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function rollback( int $kit_id, string $snapshot_id ): array|\WP_Error {
		if ( '' === $snapshot_id ) {
			return new \WP_Error(
				'stonewright_kit_rollback_missing',
				__( 'rollback_snapshot is required when rollback=true.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		if ( null === Backup::get_snapshot( $kit_id, $snapshot_id ) ) {
			return new \WP_Error(
				'stonewright_kit_rollback_missing',
				__( 'No kit snapshot found for the given rollback_snapshot.', 'stonewright' ),
				[ 'status' => 404, 'snapshot_id' => $snapshot_id ]
			);
		}

		if ( ! Backup::restore( $kit_id, $snapshot_id ) ) {
			return new \WP_Error(
				'stonewright_kit_rollback_failed',
				__( 'Failed to restore the kit from the given snapshot.', 'stonewright' ),
				[ 'status' => 500, 'snapshot_id' => $snapshot_id ]
			);
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : [];
		return [
			'ok'          => true,
			'kit_id'      => $kit_id,
			'dry_run'     => false,
			'snapshot_id' => $snapshot_id,
			'applied'     => 0,
			'before_hash' => '',
			'after_hash'  => self::hash_settings( $settings ),
			'rollback'    => true,
		];
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonicalize( $v );
		}
		return $value;
	}
}

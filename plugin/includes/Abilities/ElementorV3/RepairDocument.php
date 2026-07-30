<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Backup-first recovery for transport and element-id corruption.
 *
 * The repair is deliberately narrow: it decodes a double-encoded root and
 * assigns deterministic ids to duplicate or missing ids. All other node data
 * is preserved and the repaired tree still passes the normal write gates.
 *
 * @stonewright-status stable
 */
final class RepairDocument extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-repair-document';
	}

	public function label(): string {
		return __( 'Repair Elementor document', 'stonewright' );
	}

	public function description(): string {
		return __( 'Repairs double-encoded Elementor data and duplicate or missing element ids without changing content or settings.', 'stonewright' );
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
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Required in production-safe mode.' ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'       => [ 'type' => 'integer' ],
				'repaired'      => [ 'type' => 'boolean' ],
				'fixes_applied' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'snapshot_id'   => [ 'type' => 'string' ],
				'message'       => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'repaired', 'fixes_applied', 'snapshot_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				if ( $post_id <= 0 || ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$token_error = $this->confirmation_token_error( $args, [ 'post_id' => $post_id ] );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$raw   = get_post_meta( $post_id, '_elementor_data', true );
				$tree  = ElementorData::read( $post_id );
				$fixes = [];

				$decoded_root = self::decode_double_encoded_root( $raw );
				if ( null !== $decoded_root ) {
					$tree    = $decoded_root;
					$fixes[] = 'double_encoded_root';
				}

				$tree = self::reindex_ids( $tree, $post_id, $id_fixes );
				if ( $id_fixes['duplicate'] ) {
					$fixes[] = 'duplicate_ids';
				}
				if ( $id_fixes['missing'] ) {
					$fixes[] = 'missing_ids';
				}

				if ( [] === $fixes ) {
					return [
						'post_id'       => $post_id,
						'repaired'      => false,
						'fixes_applied' => [],
						'snapshot_id'   => '',
						'message'       => __( 'No transport or element-id corruption detected.', 'stonewright' ),
					];
				}

				if ( ! SettingsValidator::validate_tree( $tree ) ) {
					return SettingsValidator::last_error()
						?? $this->error( 'repair_validation_failed', __( 'The repaired Elementor tree still failed validation.', 'stonewright' ) );
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error( 'snapshot_failed', __( 'Could not snapshot the post before repair.', 'stonewright' ) );
				}

				// Never bypass the integrity gate: the normalized tree must pass the same
				// persistence path as every other typed Elementor write.
				if ( ! ElementorData::write( $post_id, $tree ) ) {
					return ElementorData::write_error_for_ability();
				}

				return [
					'post_id'       => $post_id,
					'repaired'      => true,
					'fixes_applied' => $fixes,
					'snapshot_id'   => $snapshot_id,
				];
			}
		);
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function decode_double_encoded_root( mixed $raw ): ?array {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		foreach ( array_unique( [ $raw, wp_unslash( $raw ) ] ) as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( ! is_string( $decoded ) ) {
				continue;
			}
			$inner = json_decode( $decoded, true );
			if ( is_array( $inner ) && array_is_list( $inner ) ) {
				return $inner;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @param array{duplicate:bool,missing:bool}|null $fixes
	 * @return array<int, array<string, mixed>>
	 */
	private static function reindex_ids( array $tree, int $post_id, ?array &$fixes = null ): array {
		$fixes  = [ 'duplicate' => false, 'missing' => false ];
		$seen   = [];
		$serial = 0;

		$walk = static function ( array $nodes, string $path ) use ( &$walk, &$seen, &$serial, &$fixes, $post_id ): array {
			foreach ( $nodes as $index => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$node_path = $path . '.' . (string) $index;
				$id        = isset( $node['id'] ) && is_scalar( $node['id'] ) ? trim( (string) $node['id'] ) : '';
				$missing   = '' === $id;
				$duplicate = ! $missing && isset( $seen[ $id ] );
				if ( $missing || $duplicate ) {
					$fixes[ $missing ? 'missing' : 'duplicate' ] = true;
					do {
						++$serial;
						$id = substr( hash( 'sha256', $post_id . ':' . $node_path . ':' . (string) $serial ), 0, 7 );
					} while ( isset( $seen[ $id ] ) );
					$node['id'] = $id;
				}
				$seen[ $id ] = true;
				if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
					$node['elements'] = $walk( $node['elements'], $node_path . '.elements' );
				}
				$nodes[ $index ] = $node;
			}
			return $nodes;
		};

		return $walk( $tree, 'root' );
	}
}

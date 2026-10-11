<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Adapter for Elementor's Variables_Service; never writes guessed kit keys. */
final class AtomicVariableRepositoryAdapter {

	public function __construct( private readonly object $service ) {}

	public static function runtime(): self|\WP_Error {
		$service_class   = '\\Elementor\\Modules\\Variables\\Services\\Variables_Service';
		$repository_class = '\\Elementor\\Modules\\Variables\\Storage\\Variables_Repository';
		$batch_class     = '\\Elementor\\Modules\\Variables\\Services\\Batch_Operations\\Batch_Processor';
		if ( ! class_exists( $service_class ) || ! class_exists( $repository_class ) || ! class_exists( $batch_class ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error( 'stonewright_v4_variables_unavailable', 'The installed Elementor runtime does not expose the verified Variables API.' );
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( ! $kit ) {
				return new \WP_Error( 'no_kit', 'No active Elementor kit found.' );
			}
			return new self( new $service_class( new $repository_class( $kit ), new $batch_class() ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variables_unavailable', $error->getMessage() );
		}
	}

	/** @return array<string, array<string, mixed>>|\WP_Error */
	public function all(): array|\WP_Error {
		if ( ! method_exists( $this->service, 'get_variables_list' ) ) {
			return new \WP_Error( 'stonewright_v4_variables_api_mismatch', 'Variables runtime methods do not match the verified adapter.' );
		}
		try {
			$items = $this->service->get_variables_list();
			return is_array( $items ) ? $items : [];
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variables_read_failed', $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): array|\WP_Error {
		return $this->mutate( 'create', '', $data );
	}

	/** @param array<string, mixed> $data */
	public function update( string $id, array $data ): array|\WP_Error {
		return $this->mutate( 'update', $id, $data );
	}

	/** @param array<string, mixed> $data */
	private function mutate( string $operation, string $id, array $data ): array|\WP_Error {
		if ( ! method_exists( $this->service, $operation ) ) {
			return new \WP_Error( 'stonewright_v4_variables_api_mismatch', 'Variables mutation API does not match the verified adapter.' );
		}
		try {
			$previous = null;
			if ( 'update' === $operation ) {
				$before   = $this->all();
				$previous = is_array( $before ) && isset( $before[ $id ] ) && is_array( $before[ $id ] ) ? $before[ $id ] : null;
			}
			$result = 'create' === $operation ? $this->service->create( $data ) : $this->service->update( $id, $data );
			$variable = is_array( $result ) && isset( $result['variable'] ) && is_array( $result['variable'] ) ? $result['variable'] : null;
			if ( null === $variable ) {
				return new \WP_Error( 'stonewright_v4_variable_readback_failed', 'Elementor did not return the variable after mutation.' );
			}
			$all = $this->all();
			if ( is_wp_error( $all ) ) {
				return $all;
			}
			$target_id = (string) ( $variable['id'] ?? $id );
			if ( '' === $target_id || ! isset( $all[ $target_id ] ) ) {
				return new \WP_Error( 'stonewright_v4_variable_readback_failed', 'The variable was not found after mutation.' );
			}
			$stored = array_merge( [ 'id' => $target_id ], $all[ $target_id ] );
			foreach ( [ 'label', 'type', 'value' ] as $field ) {
				if ( array_key_exists( $field, $data ) && self::lost( $field, $data[ $field ], $stored ) ) {
					$rolled_back = $this->roll_back( $operation, $target_id, $previous );
					return new \WP_Error(
						'stonewright_atomic_readback_mismatch',
						'The stored variable does not contain everything that was written: ' . $field . '; ' . ( $rolled_back ? 'the write was rolled back.' : 'the rollback could not be confirmed, restore the returned snapshot.' ),
						[
							'status'              => 409,
							'execution_status'    => 'failed',
							'verification_status' => 'failed',
							'readback_context'    => 'global_variable',
							'rollback_status'     => $rolled_back ? 'succeeded' : 'failed',
							'problems'            => [ [ 'code' => 'variable_mismatch', 'id' => $target_id, 'path' => '/' . $target_id . '/' . $field ] ],
						]
					);
				}
			}
			return $stored;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variable_write_failed', $error->getMessage() );
		}
	}

	/**
	 * Undoes a write whose readback did not match: a created variable is deleted, an updated one gets its
	 * previous label, type and value back. The answer is true only when the readback confirms it.
	 *
	 * @param array<string, mixed>|null $previous The variable as it was before an update.
	 */
	private function roll_back( string $operation, string $id, ?array $previous ): bool {
		try {
			if ( 'create' === $operation ) {
				if ( ! method_exists( $this->service, 'delete' ) ) {
					return false;
				}
				$this->service->delete( $id );
				$all = $this->all();
				// Elementor may keep a deleted variable with a deleted marker instead of dropping it.
				return is_array( $all ) && ( ! isset( $all[ $id ] ) || ! empty( $all[ $id ]['deleted'] ) );
			}
			if ( null === $previous ) {
				return false;
			}
			$restore = array_intersect_key( $previous, array_flip( [ 'label', 'type', 'value' ] ) );
			$this->service->update( $id, $restore );
			$all = $this->all();
			if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
				return false;
			}
			foreach ( $restore as $field => $value ) {
				if ( ! array_key_exists( $field, $all[ $id ] ) || $all[ $id ][ $field ] != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Elementor may re-type a scalar on storage.
					return false;
				}
			}
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}
	/**
	 * Label and type must come back as written; a value may be normalized by Elementor but must not come back empty.
	 *
	 * @param array<string, mixed> $stored
	 */
	private static function lost( string $field, mixed $written, array $stored ): bool {
		if ( ! array_key_exists( $field, $stored ) ) {
			return true;
		}
		if ( 'value' !== $field ) {
			return $written !== $stored[ $field ];
		}
		$empty = static fn( mixed $value ): bool => null === $value || '' === $value || [] === $value;
		return $empty( $stored['value'] ) && ! $empty( $written );
	}
}
